<?php

declare(strict_types=1);

arch()->preset()->php();

arch()->preset()->security();

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect(['dd', 'ddd', 'env', 'exit'])
    ->each->not->toBeUsed();

arch('the package source declares strict types')
    ->expect('Taldres\GeocodeCache')
    ->toUseStrictTypes();

arch('adapters implement the adapter contract')
    ->expect('Taldres\GeocodeCache\Adapters')
    ->toImplement('Taldres\GeocodeCache\Contracts\Adapter');

arch('value objects are immutable')
    ->expect('Taldres\GeocodeCache\Data')
    ->toBeReadonly();
