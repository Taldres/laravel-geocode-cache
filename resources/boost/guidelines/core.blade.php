## Laravel Geocode Cache

This application uses `taldres/laravel-geocode-cache`, which turns addresses, places and postal codes into coordinates
and keeps them in a database table for a limited time. Expired entries must be deleted, so the
`geocode-cache:prune` command has to run on the scheduler. Use the `laravel-geocode-cache-development` skill before you
write or change code that geocodes, reads or deletes cached coordinates.

@verbatim
<code-snippet name="Schedule the pruning in routes/console.php" lang="php">
Schedule::command('geocode-cache:prune')->hourly()->onOneServer()->withoutOverlapping();
</code-snippet>
@endverbatim
