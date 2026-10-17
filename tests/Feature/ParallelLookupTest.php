<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Taldres\GeocodeCache\Data\GeocodeQuery;

/**
 * Real processes instead of a lock that the test holds by hand: each one has its own application, and they share an
 * SQLite file, a file cache for the locks and a file that counts the calls of the adapter.
 *
 * @param  list<string>  $searches  One process per entry.
 * @return array{processes: list<Process>, calls: list<string>, rows: int}
 */
function runInParallel(string $directory, array $searches): array
{
    $worker = __DIR__.'/../Fixtures/parallel-worker.php';

    $migrate = new Process([PHP_BINARY, $worker, $directory, 'migrate']);
    $migrate->mustRun();

    $processes = array_map(fn (string $search): Process => new Process([PHP_BINARY, $worker, $directory, 'lookup', $search], timeout: 120), $searches);

    foreach ($processes as $process) {
        $process->start();
    }

    // Wait until every process is booted, so they ask at the same moment and not one after the other.
    $deadline = microtime(true) + 60;

    while (count(File::glob("{$directory}/ready-*")) < count($processes)) {
        foreach ($processes as $process) {
            if (! $process->isRunning() && ! $process->isSuccessful()) {
                throw new RuntimeException('A process stopped before the start: '.$process->getErrorOutput().$process->getOutput());
            }
        }

        if (microtime(true) > $deadline) {
            throw new RuntimeException('The processes did not start in time.');
        }

        usleep(10_000);
    }

    File::put("{$directory}/go", '');

    foreach ($processes as $process) {
        $process->wait();
    }

    $calls = is_file("{$directory}/calls.log") ? array_values(array_filter(explode("\n", File::get("{$directory}/calls.log")))) : [];
    $rows = (int) (new PDO("sqlite:{$directory}/database.sqlite"))->query('select count(*) from geocode_cache')->fetchColumn();

    return ['processes' => $processes, 'calls' => $calls, 'rows' => $rows];
}

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/geocode-cache-parallel-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists("{$this->directory}/cache");
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

it('asks the service once per query when processes look the same query up at the same time', function () {
    $run = runInParallel($this->directory, ['Berlin', 'Berlin', 'Berlin', 'Berlin', 'Hamburg', 'Hamburg']);

    foreach ($run['processes'] as $process) {
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and(json_decode($process->getOutput(), true))->toBe(['latitude' => 52.52, 'source' => 'google']);
    }

    // One call per query, although four and two processes asked at the same moment.
    expect($run['calls'])->toEqualCanonicalizing([
        GeocodeQuery::make('Berlin')->hash('google'),
        GeocodeQuery::make('Hamburg')->hash('google'),
    ])
        ->and($run['rows'])->toBe(2);
})->group('parallel');
