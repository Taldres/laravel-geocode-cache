<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Taldres\GeocodeCache\Tests\ConnectionTestCase;
use Taldres\GeocodeCache\Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(ConnectionTestCase::class)
    ->in('Connection');

pest()->extend(TestCase::class)
    ->in('Unit');
