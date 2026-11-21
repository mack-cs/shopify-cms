<?php

return [
    /*
     * Confirmed component photographs can fan out to dependent stacks.
     * COMPONENT_STACK_IMAGE_PROPAGATION remains the legacy automatic fan-out,
     * which runs only after a component photograph is confirmed on Shopify.
     * It stays off until staging and production verification are approved.
     *
     * COMPONENT_STACK_LOCAL_REBUILD rebuilds dependent stack galleries in the
     * CMS when component photographs or alt text change. It never publishes.
     *
     * COMPONENT_STACK_BLIND_PUBLICATION sends approved component photograph and
     * alt text changes to active stacks only. Draft and inactive stacks stay local.
     * Both automatic switches default off and are independent of manual Rebuild Stack.
     */
    'component_stack_propagation' => (bool) env('COMPONENT_STACK_IMAGE_PROPAGATION', false),

    'component_stack_local_rebuild' => (bool) env('COMPONENT_STACK_LOCAL_REBUILD', false),

    'component_stack_blind_publication' => (bool) env('COMPONENT_STACK_BLIND_PUBLICATION', false),
];
