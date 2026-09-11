<?php

namespace Tests\Feature;

use App\Models\Plan;

/** New clients can also send canonical periods; the old baseline only accepted aliases. */
class CanonicalPeriodContractTest extends LegacyApiContractTest
{
    public static function periods(): array
    {
        $cases = [];
        foreach (Plan::LEGACY_PERIOD_MAPPING as $canonical) {
            $cases[$canonical] = [$canonical, $canonical];
        }
        return $cases;
    }
}
