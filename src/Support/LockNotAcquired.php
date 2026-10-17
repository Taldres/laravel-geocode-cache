<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Support;

use RuntimeException;

/**
 * Thrown inside the package when the lock of a query stays taken for longer than lock.wait. It is no
 * LockTimeoutException, because an adapter may throw that one itself, for a lock of its own.
 *
 * @internal
 */
final class LockNotAcquired extends RuntimeException {}
