<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Taldres\GeocodeCache\CachedGeocoder;
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Data\PlaceIdQuery;
use Taldres\GeocodeCache\Enums\RefreshOutcome;
use Taldres\GeocodeCache\Exceptions\GeocodingFailedException;
use Taldres\GeocodeCache\Facades\GeocodeCache;
use Taldres\GeocodeCache\Models\GeocodeCacheEntry;
use Taldres\GeocodeCache\Support\GeocodeLog;
use Taldres\GeocodeCache\Support\StoredQuery;

#[AsCommand(name: 'geocode-cache:refresh', description: 'Fetch geocode cache entries that are in use again shortly before they expire')]
class RefreshCommand extends Command
{
    private const int MAXIMUM_CONSECUTIVE_FAILURES = 5;

    protected $signature = 'geocode-cache:refresh
                            {--driver= : The driver to fetch with, defaults to the driver of the configuration}
                            {--limit= : The maximum number of service calls, defaults to the refresh.limit setting}
                            {--pretend : Only report how many entries are due}';

    public function handle(): int
    {
        if (! config('geocode-cache.refresh.enabled')) {
            $this->components->error('The refresh is disabled. Set geocode-cache.refresh.enabled to true first.');

            return self::FAILURE;
        }

        $limit = $this->option('limit') ?? config('geocode-cache.refresh.limit');

        if (! is_numeric($limit) || (int) $limit < 1 || (string) (int) $limit !== (string) $limit) {
            $this->components->error('The limit must be a whole number of 1 or more.');

            return self::FAILURE;
        }

        if (! config('geocode-cache.refresh.store_query') && ! config('geocode-cache.store_place_id')) {
            $this->components->error('Nothing is stored to refresh with. Set geocode-cache.refresh.store_query or geocode-cache.store_place_id to true.');

            return self::FAILURE;
        }

        $driver = $this->option('driver');

        try {
            $geocoder = GeocodeCache::driver(is_string($driver) ? $driver : null);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! config('geocode-cache.refresh.store_query') && ! $geocoder->supportsPlaceIds()) {
            $this->components->error('The adapter cannot look place IDs up, so entries can only be refreshed through a stored query. Set geocode-cache.refresh.store_query to true.');

            return self::FAILURE;
        }

        $source = $geocoder->adapter()->source();
        $limit = (int) $limit;

        if ($this->option('pretend')) {
            $calls = $entries = 0;

            foreach ($this->units($source, $limit) as $unit) {
                if ($this->plan($unit, $source, $geocoder) !== null) {
                    $calls++;
                    $entries += $unit->count();
                }
            }

            $this->components->info("{$calls} service calls would refresh up to {$entries} geocode cache entries (limit {$limit}).");

            return self::SUCCESS;
        }

        $refreshed = $copied = $unchanged = $skipped = $failed = $consecutiveFailures = $byPlaceId = 0;

        foreach ($this->units($source, $limit) as $unit) {
            $plan = $this->plan($unit, $source, $geocoder);

            if ($plan === null) {
                $skipped += $unit->count();

                continue;
            }

            [$representative, $lookup] = $plan;

            try {
                // The entry is read again under the lock. It is skipped if it was deleted, has expired or was renewed
                // since this run selected it.
                $outcome = $geocoder->refreshUsing($representative->query_hash, $lookup, $representative->fetched_at);
                $byPlaceId += $lookup instanceof PlaceIdQuery && $outcome !== RefreshOutcome::Skipped ? 1 : 0;

                if ($outcome === RefreshOutcome::Refreshed) {
                    $refreshed++;
                    $copied += $geocoder->shareWithPlace($representative->query_hash, $unit);
                } elseif ($outcome === RefreshOutcome::NotFound) {
                    $unchanged++;
                } else {
                    $skipped += $unit->count();
                }

                $consecutiveFailures = 0;
            } catch (GeocodingFailedException $exception) {
                $failed++;
                $byPlaceId += $lookup instanceof PlaceIdQuery ? 1 : 0;
                $this->components->warn($exception->getMessage());

                if (++$consecutiveFailures >= self::MAXIMUM_CONSECUTIVE_FAILURES) {
                    $this->components->error('Stopped after '.self::MAXIMUM_CONSECUTIVE_FAILURES.' failures in a row.');
                    GeocodeLog::write('error', 'The geocode cache refresh stopped after repeated failures.', ['failures' => self::MAXIMUM_CONSECUTIVE_FAILURES]);

                    break;
                }
            }
        }

        GeocodeLog::write($failed > 0 ? 'warning' : 'info', 'The geocode cache refresh finished.', [
            'source' => $source,
            'refreshed' => $refreshed,
            'copied' => $copied,
            'without_result' => $unchanged,
            'skipped' => $skipped,
            'failed' => $failed,
            'by_place_id' => $byPlaceId,
        ]);
        $this->components->info("{$refreshed} refreshed, {$copied} copied to entries of the same place, {$unchanged} without result, {$skipped} skipped, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The entries that are valid, in use and about to expire.
     *
     * @return Builder<GeocodeCacheEntry>
     */
    private function due(string $source): Builder
    {
        $now = Date::now()->utc();

        return GeocodeCacheEntry::query()
            ->valid()
            ->where('source', $source)
            ->where(fn (Builder $entries) => config('geocode-cache.refresh.store_query')
                ? $entries->whereNotNull('query_payload')->orWhereNotNull('place_id')
                : $entries->whereNotNull('place_id'))
            ->where('expires_at', '<=', $now->copy()->addDays(config()->integer('geocode-cache.refresh.expiring_within_days')))
            ->where('last_requested_at', '>=', $now->copy()->subDays(config()->integer('geocode-cache.refresh.requested_within_days')));
    }

    /**
     * Groups the due entries by place, because one service call renews all of them: the entries of a place without
     * a place ID stand alone. The units that expire first come first, and there are at most $limit of them.
     *
     * @return Collection<int, Collection<int, GeocodeCacheEntry>>
     */
    private function units(string $source, int $limit): Collection
    {
        $places = $this->due($source)
            ->toBase()
            ->whereNotNull('place_id')
            ->selectRaw('place_id, min(expires_at) as soonest')
            ->groupBy('place_id')
            ->orderBy('soonest')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): Collection => $this->due($source)->where('place_id', $row->place_id)->orderBy('expires_at')->get());

        $alone = $this->due($source)
            ->whereNull('place_id')
            ->orderBy('expires_at')
            ->limit($limit)
            ->get()
            ->map(fn (GeocodeCacheEntry $entry): Collection => Collection::make([$entry]));

        return $places->concat($alone)
            ->sortBy(fn (Collection $unit) => $unit->first()?->getRawOriginal('expires_at'))
            ->take($limit)
            ->values();
    }

    /**
     * How to renew a unit: the entry that stands for it and the lookup for the service. The stored query comes first,
     * because it asks for the address again. Without one, the place ID of the entry is used. Null if neither works.
     *
     * @param  Collection<int, GeocodeCacheEntry>  $unit
     * @return array{GeocodeCacheEntry, GeocodeQuery|PlaceIdQuery}|null
     */
    private function plan(Collection $unit, string $source, CachedGeocoder $geocoder): ?array
    {
        // A query that is stored although the setting is off is a leftover: it is not used and goes with the next renewal.
        foreach (config('geocode-cache.refresh.store_query') ? $unit : [] as $entry) {
            // An entry whose query the adapter cannot answer, such as a place ID for an adapter without that lookup, is skipped.
            $query = $this->queryOf($entry, $source);

            if ($query !== null && $geocoder->supports($query)) {
                return [$entry, $query];
            }
        }

        foreach ($unit as $entry) {
            $placeId = $this->placeIdQueryOf($entry);

            if ($placeId !== null && $geocoder->supports($placeId)) {
                return [$entry, $placeId];
            }
        }

        return null;
    }

    private function placeIdQueryOf(GeocodeCacheEntry $entry): ?PlaceIdQuery
    {
        try {
            return $entry->place_id === null ? null : PlaceIdQuery::make($entry->place_id);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function queryOf(GeocodeCacheEntry $entry, string $source): GeocodeQuery|PlaceIdQuery|null
    {
        try {
            $payload = $entry->query_payload;
            $query = is_array($payload) ? StoredQuery::fromArray($payload) : null;
        } catch (DecryptException|InvalidArgumentException) {
            // The application key changed, or the stored payload is not a query anymore.
            return null;
        }

        return $query?->hash($source) === $entry->query_hash ? $query : null;
    }
}
