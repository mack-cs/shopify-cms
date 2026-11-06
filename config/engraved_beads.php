<?php

return [
    /*
    | Procurement eligibility can be enabled without inventory writes.
    | Inventory writes stay off until operations confirms the stock pool.
    */
    'enabled' => filter_var(env('ENGRAVED_BEADS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'inventory_writes_enabled' => filter_var(env('ENGRAVED_BEAD_INVENTORY_WRITES', false), FILTER_VALIDATE_BOOLEAN),
    // Remains false until actual location/channel behavior and checkout constraints are verified.
    'store_contract_verified' => filter_var(env('ENGRAVED_BEAD_STORE_CONTRACT_VERIFIED', false), FILTER_VALIDATE_BOOLEAN),
    /*
    | Safety buffers for 2-, 3-, and 4-bead products. A product becomes unsellable when
    | the family's effective available beads are less than or equal to its buffer.
    | Changing a buffer does not change how many physical beads a purchase consumes.
    | The 1-bead product keeps the threshold stored on its family member.
    |
    | After changing these values on a host that has run `php artisan config:cache`,
    | run that command again and then `php artisan queue:restart`. Long-running
    | workers keep the previous configuration until they restart. The every-minute
    | reconciler stores the new buffers and queues sellability only when both
    | inventory write flags are true, so a configuration change does not mutate
    | Shopify while those flags are false.
    */
    'thresholds' => [
        2 => \App\Services\EngravedBeads\EngravedBeadSafetyThresholds::normalize(env('ENGRAVED_BEAD_2_THRESHOLD', 2), 2),
        3 => \App\Services\EngravedBeads\EngravedBeadSafetyThresholds::normalize(env('ENGRAVED_BEAD_3_THRESHOLD', 3), 3),
        4 => \App\Services\EngravedBeads\EngravedBeadSafetyThresholds::normalize(env('ENGRAVED_BEAD_4_THRESHOLD', 4), 4),
    ],
    // Recent-order read for webhook gaps. Tests force this off. It never runs without both write flags and an active family.
    'missed_order_recovery' => filter_var(env('ENGRAVED_BEAD_MISSED_ORDER_RECOVERY', true), FILTER_VALIDATE_BOOLEAN),
];
