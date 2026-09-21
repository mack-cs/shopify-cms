<?php

use App\Models\DropdownOption;
use App\Services\HeaderStore;

it('matches materials values across html spaces and multiline formatting', function (): void {
    $stored = "• Japanese Miyuki beads\n• Elasticated 720mm";
    $shopify = '• Japanese Miyuki beads&#x20; • Elasticated 720mm';

    expect(DropdownOption::canonicalValue(HeaderStore::MATERIALS_AND_DIMENSIONS, $stored))
        ->toBe(DropdownOption::canonicalValue(HeaderStore::MATERIALS_AND_DIMENSIONS, $shopify));
});

it('matches materials values across bullet and html list formatting', function (): void {
    $approved = "• Japanese Miyuki beads\n• Wax coated cord\n• 16cm (on shortest length) and up to 24cm (with extension)";
    $shopify = '<ul><li>Japanese Miyuki beads</li><li>Wax coated cord</li><li>16cm (on shortest length) and up to 24cm (with extension)</li></ul>';

    expect(DropdownOption::canonicalValue(HeaderStore::MATERIALS_AND_DIMENSIONS, $approved))
        ->toBe(DropdownOption::canonicalValue(HeaderStore::MATERIALS_AND_DIMENSIONS, $shopify));
});
