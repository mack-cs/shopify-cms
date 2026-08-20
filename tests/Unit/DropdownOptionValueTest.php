<?php

use App\Models\DropdownOption;
use App\Services\HeaderStore;

it('matches materials values across html spaces and multiline formatting', function (): void {
    $stored = "• Japanese Miyuki beads\n• Elasticated 720mm";
    $shopify = '• Japanese Miyuki beads&#x20; • Elasticated 720mm';

    expect(DropdownOption::canonicalValue(HeaderStore::MATERIALS_AND_DIMENSIONS, $stored))
        ->toBe(DropdownOption::canonicalValue(HeaderStore::MATERIALS_AND_DIMENSIONS, $shopify));
});
