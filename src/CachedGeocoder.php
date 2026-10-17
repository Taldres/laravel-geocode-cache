<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Taldres\GeocodeCache\Contracts\Adapter;
use Taldres\GeocodeCache\Contracts\SupportsPlaceIdLookup;
use Taldres\GeocodeCache\Data\Coordinates;
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Data\GeocodeResult;
use Taldres\GeocodeCache\Data\PlaceIdQuery;
use Taldres\GeocodeCache\Enums\RefreshOutcome;
use Taldres\GeocodeCache\Events\GeocodeLookupFailed;
use Taldres\GeocodeCache\Events\GeocodeServiceCalled;
use Taldres\GeocodeCache\Exceptions\GeocodeCacheException;
use Taldres\GeocodeCache\Exceptions\GeocodingFailedException;
use Taldres\GeocodeCache\Exceptions\GeocodingLockTimeoutException;
use Taldres\GeocodeCache\Exceptions\InvalidConfigurationException;
use Taldres\GeocodeCache\Exceptions\UnsupportedQueryException;
use Taldres\GeocodeCache\Models\GeocodeCacheEntry;
use Taldres\GeocodeCache\Models\GeocodeCacheStatistic;
use Taldres\GeocodeCache\Support\GeocodeLog;
use Taldres\GeocodeCache\Support\LockNotAcquired;
use Throwable;

final class CachedGeocoder
{
    public function __construct(private readonly Adapter $adapter) {}

    public function adapter(): Adapter
    {
        return $this->adapter;
    }

    /**
     * Whether the adapter can look a place ID up.
     */
    public function supportsPlaceIds(): bool
    {
        return $this->adapter instanceof SupportsPlaceIdLookup;
    }

    /**
     * Whether the adapter can answer the query. Only a place ID query depends on it.
     */
    public function supports(GeocodeQuery|PlaceIdQuery $query): bool
    {
        return ! $query instanceof PlaceIdQuery || $this->supportsPlaceIds();
    }

    /**
     * @throws GeocodingFailedException
     * @throws UnsupportedQueryException If the query is a place ID and the adapter cannot look it up.
     */
    public function geocode(GeocodeQuery|PlaceIdQuery|string $query): ?GeocodeResult
    {
        $query = is_string($query) ? GeocodeQuery::make($query) : $query;

        // Before the cache is read, so the answer does not depend on what happens to be stored.
        $this->assertSupported($query);

        $hash = $query->hash($this->adapter->source());

        $result = $this->find($hash);

        if ($result !== null) {
            return $result;
        }

        $events = [];

        try {
            return $this->locked($hash, function () use ($query, $hash, &$events): ?GeocodeResult {
                return $this->findOrFetch($query, $hash, $events);
            });
        } catch (LockNotAcquired) {
            // The process that held the lock may have stored the entry just now.
            return $this->find($hash) ?? throw $this->lockTimeout();
        } finally {
            // After the lock was released, so a slow listener holds up no other process and may ask for the same query.
            $this->announce($events);
        }
    }

    /**
     * Fetches the entry of the query again.
     *
     * @throws GeocodingFailedException
     * @throws UnsupportedQueryException If the query is a place ID and the adapter cannot look it up.
     */
    public function refresh(GeocodeQuery|PlaceIdQuery $query, ?CarbonInterface $onlyIfFetchedAt = null): RefreshOutcome
    {
        return $this->refreshUsing($query->hash($this->adapter->source()), $query, $onlyIfFetchedAt);
    }

    /**
     * Fetches the entry with the given hash again, asking the service with $lookup. That is the query of the entry or
     * another one that leads to the same place, such as the place ID of the entry. The stored query stays as it is.
     * The entry is read again under its lock and left alone if it was deleted, has expired, or is not the one that
     * was fetched at $onlyIfFetchedAt anymore.
     *
     * @throws GeocodingFailedException
     * @throws UnsupportedQueryException If the lookup is a place ID and the adapter cannot look it up.
     */
    public function refreshUsing(string $hash, GeocodeQuery|PlaceIdQuery $lookup, ?CarbonInterface $onlyIfFetchedAt = null): RefreshOutcome
    {
        $this->assertSupported($lookup);

        $events = [];

        try {
            return $this->locked($hash, function () use ($lookup, $hash, $onlyIfFetchedAt, &$events): RefreshOutcome {
                $current = $this->validEntry($hash);

                if ($current === null || ($onlyIfFetchedAt !== null && ! $current->fetched_at->equalTo($onlyIfFetchedAt))) {
                    return RefreshOutcome::Skipped;
                }

                $this->track($hash, 'refreshes');
                $coordinates = $this->lookup($lookup, $events);

                if ($coordinates === null) {
                    return RefreshOutcome::NotFound;
                }

                return $this->replace($current, $this->build($lookup, $hash, $coordinates)) ? RefreshOutcome::Refreshed : RefreshOutcome::Skipped;
            });
        } catch (LockNotAcquired) {
            // No fallback to the old entry: it would count as a request, and as a renewal.
            throw $this->lockTimeout();
        } finally {
            $this->announce($events);
        }
    }

    /**
     * Copies the fresh result of a refreshed entry to the other entries of the same place, so one service call renews
     * all of them. Each copy is conditional on the state the entry was selected in. An entry that was deleted, has
     * expired or was renewed since then stays as it is. Returns the number of entries that were updated.
     *
     * @param  iterable<GeocodeCacheEntry>  $siblings
     */
    public function shareWithPlace(string $refreshedHash, iterable $siblings): int
    {
        $refreshed = GeocodeCacheEntry::query()->where('query_hash', $refreshedHash)->first();

        if ($refreshed === null || $refreshed->place_id === null) {
            return 0;
        }

        $values = Collection::make($refreshed->getAttributes())
            ->only(['source', 'latitude', 'longitude', 'place_id', 'fetched_at', 'expires_at'])
            ->all();

        // Queries that were stored while the setting was on go when the entry is renewed.
        if (! config('geocode-cache.refresh.store_query')) {
            $values['query_payload'] = null;
        }
        $updated = 0;

        foreach ($siblings as $sibling) {
            if ($sibling->query_hash === $refreshedHash) {
                continue;
            }

            $updated += GeocodeCacheEntry::query()
                ->valid()
                ->whereKey($sibling->getKey())
                ->where('place_id', $refreshed->place_id)
                ->where('fetched_at', $sibling->getRawOriginal('fetched_at'))
                ->toBase()
                ->update($values);
        }

        return $updated;
    }

    /**
     * @throws InvalidConfigurationException
     */
    public function retentionDays(): int
    {
        $source = $this->adapter->source();
        $maximum = $this->maximumRetentionDays();
        $perSource = config('geocode-cache.source_ttl_days');

        if ($perSource !== null && ! is_array($perSource)) {
            throw new InvalidConfigurationException('The geocode-cache.source_ttl_days setting must be a map of sources to days.');
        }

        // The source is looked up as a plain key, because a name with a dot is no path.
        if (is_array($perSource) && array_key_exists($source, $perSource)) {
            $setting = "geocode-cache.source_ttl_days.{$source}";
            $days = $perSource[$source];
        } else {
            $setting = 'geocode-cache.ttl_days';
            $days = config('geocode-cache.ttl_days');
        }

        if (! is_int($days) || $days < 1) {
            throw new InvalidConfigurationException("The {$setting} setting must be a whole number of days of 1 or more.");
        }

        if ($maximum !== null && $days > $maximum) {
            throw new InvalidConfigurationException("The {$setting} setting must not exceed {$maximum} days for results from {$source}.");
        }

        return $days;
    }

    /**
     * @throws InvalidConfigurationException
     */
    private function maximumRetentionDays(): ?int
    {
        $maximum = $this->adapter->maximumRetentionDays();

        if ($maximum !== null && $maximum < 1) {
            throw new InvalidConfigurationException('The adapter reports a maximum retention below one day.');
        }

        return $maximum;
    }

    /**
     * @throws InvalidConfigurationException
     */
    public function precision(): int
    {
        $maximum = min($this->adapter->maximumPrecision(), Coordinates::MAXIMUM_PRECISION);

        if ($maximum < 0) {
            throw new InvalidConfigurationException('The adapter reports a negative maximum precision.');
        }

        $precision = config('geocode-cache.precision');

        if ($precision === null) {
            return $maximum;
        }

        if (! is_int($precision) || $precision < 0 || $precision > $maximum) {
            throw new InvalidConfigurationException("The geocode-cache.precision setting must be empty or a whole number from 0 to {$maximum} for this adapter.");
        }

        return $precision;
    }

    /**
     * @param  list<GeocodeLookupFailed|GeocodeServiceCalled>  $events  Collects the events to dispatch once the lock is released.
     *
     * @throws GeocodingFailedException
     */
    private function findOrFetch(GeocodeQuery|PlaceIdQuery $query, string $hash, array &$events): ?GeocodeResult
    {
        $result = $this->find($hash);

        if ($result !== null) {
            return $result;
        }

        $this->track($hash, 'lookups');
        $coordinates = $this->lookup($query, $events);

        if ($coordinates === null) {
            return null;
        }

        $entry = $this->build($query, $hash, $coordinates);

        // One atomic statement, so a parallel write cannot cause a unique violation.
        GeocodeCacheEntry::query()->upsert(
            [$entry->getAttributes()],
            ['query_hash'],
            ['source', 'latitude', 'longitude', 'place_id', 'query_payload', 'fetched_at', 'expires_at', 'last_requested_at'],
        );

        return $this->result($entry);
    }

    private function find(string $hash): ?GeocodeResult
    {
        $entry = $this->validEntry($hash);

        if ($entry === null) {
            return null;
        }

        $this->recordRequest($entry);
        $this->track($hash, 'hits');

        return $this->result($entry);
    }

    private function validEntry(string $hash): ?GeocodeCacheEntry
    {
        $entry = GeocodeCacheEntry::query()->where('query_hash', $hash)->first();

        if ($entry === null) {
            return null;
        }

        if ($entry->expires_at->lessThanOrEqualTo(Date::now())) {
            // Only the expired row goes, a fresh one that a parallel process wrote in the meantime stays.
            GeocodeCacheEntry::query()->whereKey($entry->getKey())->expired()->delete();

            return null;
        }

        if ($this->keptTooLong($entry)) {
            // The same source has a stricter maximum than the adapter that stored the entry had, or the maximum has
            // been lowered since. The entry must not live longer than the terms of its source allow.
            GeocodeCacheEntry::query()->whereKey($entry->getKey())->where('expires_at', $entry->getRawOriginal('expires_at'))->delete();

            return null;
        }

        return $entry;
    }

    private function keptTooLong(GeocodeCacheEntry $entry): bool
    {
        $maximum = $this->adapter->maximumRetentionDays();

        // An unusable maximum is reported when the retention is read, before anything is fetched.
        return $maximum !== null
            && $maximum >= 1
            && $entry->fetched_at->copy()->addSeconds($maximum * 86_400)->lessThan($entry->expires_at);
    }

    private function result(GeocodeCacheEntry $entry): GeocodeResult
    {
        return new GeocodeResult(
            $entry->latitude,
            $entry->longitude,
            $entry->place_id,
            $entry->source,
            $entry->fetched_at,
            $entry->expires_at,
        );
    }

    private function recordRequest(GeocodeCacheEntry $entry): void
    {
        if (! config('geocode-cache.refresh.enabled')) {
            return;
        }

        $now = Date::now()->utc();

        GeocodeCacheEntry::query()
            ->whereKey($entry->getKey())
            ->where('last_requested_at', '<=', $now->copy()->subSeconds(config()->integer('geocode-cache.refresh.touch_interval')))
            ->update(['last_requested_at' => $now]);
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws LockNotAcquired If another process still holds the lock after lock.wait seconds.
     */
    private function locked(string $hash, Closure $callback): mixed
    {
        $name = config('geocode-cache.lock.store');
        $store = Cache::store(is_string($name) && $name !== '' ? $name : null)->getStore();

        if (! $store instanceof LockProvider) {
            throw new InvalidConfigurationException('The cache store of the geocode cache locks does not support atomic locks. Set geocode-cache.lock.store to a store that does, such as redis, database, file or memcached.');
        }

        $seconds = config()->integer('geocode-cache.lock.seconds');
        $wait = config()->integer('geocode-cache.lock.wait');

        // A lock without a lifetime would never free itself after a crash.
        if ($seconds < 1 || $wait < 0) {
            throw new InvalidConfigurationException('The geocode-cache.lock.seconds setting must be 1 or more and lock.wait must be 0 or more.');
        }

        $lock = $store->lock("geocode-cache:{$hash}", $seconds);

        try {
            $lock->block($wait);
        } catch (LockTimeoutException) {
            throw new LockNotAcquired;
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<GeocodeLookupFailed|GeocodeServiceCalled>  $events  Collects the events to dispatch once the lock is released.
     *
     * @throws GeocodingFailedException
     */
    private function lookup(GeocodeQuery|PlaceIdQuery $query, array &$events): ?Coordinates
    {
        // An invalid setting has to fail before a call that costs money.
        $this->retentionDays();
        $this->precision();

        try {
            $coordinates = $query instanceof PlaceIdQuery
                ? $this->placeIds()->geocodePlaceId($query)
                : $this->adapter->geocode($query);
        } catch (GeocodingFailedException $exception) {
            GeocodeLog::write('warning', 'The geocoding lookup failed.', ['source' => $this->adapter->source(), 'reason' => $exception->reason]);
            $events[] = new GeocodeLookupFailed($this->adapter->source(), $exception->reason);

            throw $exception;
        } catch (GeocodeCacheException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // An adapter should throw a GeocodingFailedException. Anything else is passed on unchanged, because its
            // message may hold the request URL, but the call may have been paid for, so it is logged and announced.
            GeocodeLog::write('warning', 'The geocoding lookup failed.', ['source' => $this->adapter->source(), 'reason' => 'unexpected']);
            $events[] = new GeocodeLookupFailed($this->adapter->source(), 'unexpected');

            throw $exception;
        }

        GeocodeLog::write('debug', 'The geocoding service was asked.', ['source' => $this->adapter->source(), 'found' => $coordinates !== null]);
        $events[] = new GeocodeServiceCalled($this->adapter->source(), $coordinates !== null);

        return $coordinates;
    }

    /**
     * @param  list<GeocodeLookupFailed|GeocodeServiceCalled>  $events
     */
    private function announce(array $events): void
    {
        foreach ($events as $event) {
            // The service was asked and may have been paid for, so a failing listener must not undo the lookup.
            try {
                $connection = DB::connection();

                // In the transaction of the application a failed statement of a listener aborts the whole transaction
                // on PostgreSQL. A savepoint keeps the failure local, as it does for the statistics.
                if ($connection->transactionLevel() > 0) {
                    $connection->transaction(fn () => Event::dispatch($event));
                } else {
                    Event::dispatch($event);
                }
            } catch (Throwable $exception) {
                GeocodeLog::write('warning', 'A geocode cache event listener failed.', ['event' => $event::class, 'exception' => $exception::class]);
                report($exception);
            }
        }
    }

    private function track(string $hash, string $counter): void
    {
        if (! config('geocode-cache.statistics.enabled')) {
            return;
        }

        try {
            $connection = (new GeocodeCacheStatistic)->getConnection();

            // In the transaction of the application a failed statement aborts the whole transaction on PostgreSQL.
            // A savepoint keeps the failure of the counters local.
            if ($connection->transactionLevel() > 0) {
                $connection->transaction(fn () => GeocodeCacheStatistic::record($hash, $counter));
            } else {
                GeocodeCacheStatistic::record($hash, $counter);
            }
        } catch (Throwable $exception) {
            GeocodeLog::write('warning', 'The request statistics could not be written.', ['exception' => $exception::class]);
        }
    }

    /**
     * @throws UnsupportedQueryException
     */
    private function assertSupported(GeocodeQuery|PlaceIdQuery $query): void
    {
        if ($query instanceof PlaceIdQuery) {
            $this->placeIds();
        }
    }

    /**
     * @throws UnsupportedQueryException
     */
    private function placeIds(): SupportsPlaceIdLookup
    {
        if (! $this->adapter instanceof SupportsPlaceIdLookup) {
            throw new UnsupportedQueryException('The adapter ['.$this->adapter::class.'] cannot look a place ID up. Use a driver that can, such as google.');
        }

        return $this->adapter;
    }

    private function lockTimeout(): GeocodingLockTimeoutException
    {
        GeocodeLog::write('warning', 'A geocoding lookup gave up waiting for the lock of the same query.', ['source' => $this->adapter->source()]);

        return new GeocodingLockTimeoutException;
    }

    private function build(GeocodeQuery|PlaceIdQuery $query, string $hash, Coordinates $coordinates): GeocodeCacheEntry
    {
        $precision = $this->precision();
        $fetchedAt = Date::now()->startOfSecond();

        // Filling a model applies the casts, so the attributes are ready to be written with a query.
        return new GeocodeCacheEntry([
            'query_hash' => $hash,
            'source' => $this->adapter->source(),
            'latitude' => round($coordinates->latitude, $precision),
            'longitude' => round($coordinates->longitude, $precision),
            'place_id' => config('geocode-cache.store_place_id') ? $coordinates->placeId : null,
            'query_payload' => config('geocode-cache.refresh.enabled') && config('geocode-cache.refresh.store_query') ? $query->toArray() : null,
            'fetched_at' => $fetchedAt,
            // Seconds instead of calendar days, so a change of the clock does not shift the end of the period.
            'expires_at' => $fetchedAt->copy()->addSeconds($this->retentionDays() * 86_400),
            'last_requested_at' => $fetchedAt,
        ]);
    }

    private function replace(GeocodeCacheEntry $current, GeocodeCacheEntry $new): bool
    {
        // The stored query stays as it is, because the entry may have been renewed with another lookup than its own.
        // Only when the setting is off it is removed: then no query is meant to be in the table.
        $values = Collection::make($new->getAttributes())->except(['query_hash', 'query_payload', 'last_requested_at'])->all();

        if (! config('geocode-cache.refresh.store_query')) {
            $values['query_payload'] = null;
        }

        return GeocodeCacheEntry::query()
            ->valid()
            ->whereKey($current->getKey())
            ->where('fetched_at', $current->getRawOriginal('fetched_at'))
            ->toBase()
            ->update($values) === 1;
    }
}
