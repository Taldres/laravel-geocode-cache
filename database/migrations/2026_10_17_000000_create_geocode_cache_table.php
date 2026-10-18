<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('geocode-cache.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->char('query_hash', 64)->unique();
            $table->string('source', 32);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('place_id', 512)->nullable()->index();
            $table->text('query_payload')->nullable();
            $table->dateTime('fetched_at');
            $table->dateTime('expires_at')->index();
            $table->dateTime('last_requested_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config()->string('geocode-cache.table');
    }
};
