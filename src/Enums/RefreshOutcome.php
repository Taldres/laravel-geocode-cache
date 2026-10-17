<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Enums;

enum RefreshOutcome
{
    case Refreshed;

    /**
     * The service found nothing. The old entry stays until it expires.
     */
    case NotFound;

    /**
     * Nothing was fetched: the entry was deleted or has expired meanwhile, or somebody else renewed it.
     */
    case Skipped;
}
