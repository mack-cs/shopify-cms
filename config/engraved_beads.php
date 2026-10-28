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
];
