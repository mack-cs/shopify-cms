<?php

namespace App\Console\Commands;

use App\Models\Image;
use App\Models\Product;
use App\Services\ComponentStackPropagation;
use App\Services\ImageOwnership;
use App\Services\StackImageSync;
use Illuminate\Console\Command;

class RebuildStackImageBaseline extends Command
{
    protected $signature = 'images:rebuild-stack-baseline {stack? : Stack product id} {--publish : Publish and read back only when this stack is already authorized}';

    protected $description = 'Rebuild a stack gallery from current component photographs and record a Shopify baseline only after read-back.';

    public function handle(ComponentStackPropagation $propagation, StackImageSync $sync): int
    {
        $query = Product::query()->where('is_bundle', true)->orderBy('id');
        if ($this->argument('stack')) {
            $query->whereKey((int) $this->argument('stack'));
        }
        $stacks = $query->get();
        if ($stacks->isEmpty()) {
            $this->warn('No stack products matched.');

            return self::SUCCESS;
        }
        foreach ($stacks as $stack) {
            $ambiguous = $stack->allImages()->whereNull('ownership')->count();
            if ($ambiguous > 0) {
                $this->warn("{$stack->handle}: {$ambiguous} image(s) have no ownership. They were left unchanged.");
            }
            $result = $propagation->stageStack($stack);
            $this->line($result['message']);
            foreach ($stack->allImages()->where('ownership', ImageOwnership::COMPONENT_INHERITED)->get() as $image) {
                $sync->note($stack, $image, Image::query()->find((int) $image->source_image_id), 'local_rebuilt', 'Legacy local rebuild. Shopify baseline is not verified.', false);
            }
            if (! $this->option('publish')) {
                $this->line("{$stack->handle}: local rebuild only. Baseline remains unverified until a Shopify read-back.");

                continue;
            }
            if (! $stack->isApprovedByTwo() && ! $sync->blindPublicationEnabled()) {
                $this->warn("{$stack->handle}: publication was not authorized. No Shopify baseline was recorded.");

                continue;
            }
            $plan = $sync->synchronizeStack((int) $stack->id);
            if (($plan['outcome'] ?? '') === 'verified' || ($plan['outcome'] ?? '') === 'no_changes_required') {
                $sync->note($stack, null, null, 'baseline_verified', 'Shopify read-back matched the authorized gallery.', true);
                $this->info("{$stack->handle}: baseline verified.");
            } else {
                $this->error("{$stack->handle}: baseline not verified ({$plan['outcome']}).");
            }
        }

        return self::SUCCESS;
    }
}
