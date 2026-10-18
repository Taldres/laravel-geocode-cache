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
            $table->char('query_hash', 64);
            $table->date('date');
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedInteger('lookups')->default(0);
            $table->unsignedInteger('refreshes')->default(0);

            $table->unique(['query_hash', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config()->string('geocode-cache.statistics.table');
    }
};
