<?php

return [
    /*
     * Confirmed component photographs can fan out to dependent stacks.
     * This stays off until staging and production verification are approved.
     */
    'component_stack_propagation' => (bool) env('COMPONENT_STACK_IMAGE_PROPAGATION', false),
];
