@once
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/shop-your-vibe.css') }}?v={{ filemtime(public_path('css/shop-your-vibe.css')) }}">
    @endpush
@endonce

<x-filament-panels::page>
    <div x-data="{
        formDirty: false, savingOrder: false,
        pending() { return this.formDirty || this.$el.dataset.pending === 'true'; },
        async saveOrder(grid, type, gid = null, ids = null) {
            if (this.savingOrder || grid.closest('fieldset')?.disabled) return;
            const order = ids ?? Array.from(grid.children).map(el => el.dataset.orderKey).filter(Boolean);
            const grids = Array.from(grid.closest('.syv-workflow').querySelectorAll('[data-order-grid]'));
            this.savingOrder = true;
            grids.forEach(item => item.sortable?.option('disabled', true));
            try {
                if (type === 'cards') await this.$wire.reorderCards(order);
                else await this.$wire.reorderProducts(gid, order);
            } finally {
                this.savingOrder = false;
                grids.forEach(item => item.sortable?.option('disabled', !!item.closest('fieldset')?.disabled));
            }
        },
        move(element, type, key, direction, gid = null) {
            const grid = element.closest('[data-order-grid]');
            const ids = Array.from(grid.children).map(el => el.dataset.orderKey).filter(Boolean);
            const index = ids.indexOf(key), next = index + direction;
            if (next < 0 || next >= ids.length) return;
            [ids[index], ids[next]] = [ids[next], ids[index]];
            this.saveOrder(grid, type, gid, ids);
        }
    }"
        data-pending="{{ $draft?->pending ? 'true' : 'false' }}"
        x-on:vibe-form-saved.window="formDirty = false"
        x-on:beforeunload.window="if (pending()) { $event.preventDefault(); $event.returnValue = ''; }"
        x-on:click.window.capture="if (pending() && $event.target.closest('a[href]') && !$event.target.closest('a[href]').getAttribute('href').startsWith('#') && !confirm('You have changes that have not been pushed to Shopify. Leave anyway?')) { $event.preventDefault(); $event.stopImmediatePropagation(); }"
        class="syv-workflow space-y-6"
        x-on:vibe-editor-opened.window="formDirty = false; $nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'start' }))">
        @if ($loadError)
            <div role="alert" class="rounded-xl border border-danger-300 bg-danger-50 p-4 text-danger-800 dark:bg-danger-950 dark:text-danger-200">{{ $loadError }}</div>
        @endif

        @if (!$draft)
            <div class="flex flex-wrap items-center gap-3">
                <x-filament::input.wrapper><x-filament::input wire:model.live.debounce.300ms="search" placeholder="Search configured collections" aria-label="Search configured collections" /></x-filament::input.wrapper>
                <x-filament::button wire:click="openCollectionPicker" wire:loading.attr="disabled">Add Shop Your Vibe</x-filament::button>
                <x-filament::button color="info" wire:click="openMappingUpload" wire:loading.attr="disabled">Bulk Upload Mappings</x-filament::button>
                <x-filament::button color="gray" wire:click="loadParents(true)" wire:loading.attr="disabled">Refresh from Shopify</x-filament::button>
            </div>
            <p class="text-sm text-gray-500">Vibe cards and sorting use a reviewable draft. Product assignments update their configured membership tags, Design and Colour Style immediately after confirmation.</p>
            <div class="syv-parent-grid">
                @forelse ($parents as $parent)
                    <x-filament::section class="syv-parent-card" wire:key="parent-{{ md5($parent['gid']) }}">
                        <h2 class="text-lg font-semibold">{{ $parent['title'] }}</h2>
                        <p class="text-sm text-gray-500">{{ $parent['handle'] }}</p>
                        <p class="mt-3 font-medium">{{ $parent['product_count'] ?? 0 }} Products</p>
                        <p class="mb-3">{{ $parent['count'] }} Shop Your Vibes</p>
                        @if ($pendingDrafts->has($parent['gid']))
                            <x-filament::badge color="warning">Pending Changes</x-filament::badge>
                        @else
                            <x-filament::badge color="success">Up to date at last refresh</x-filament::badge>
                        @endif
                        <x-filament::button class="syv-manage-button" wire:click="manage({{ \Illuminate\Support\Js::from($parent['gid']) }})" wire:target="manage" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="manage">Manage</span>
                            <span wire:loading wire:target="manage">Opening editor...</span>
                        </x-filament::button>
                    </x-filament::section>
                @empty
                    <p class="text-gray-500">No configured collections found. Add Shop Your Vibe to start a preview layout.</p>
                @endforelse
            </div>
        @else
            @if ($draft->status === 'pushing')
                <div wire:poll.3s="pollPush" role="status" class="rounded-xl border border-primary-300 p-4">
                    Pushing to Shopify... You can return later; your draft is saved. Editing is paused until this push finishes.
                </div>
            @endif
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-2xl font-bold">{{ $draft->desired['parent']['title'] }} - Shop Your Vibe</h2>
                    <p class="text-sm text-gray-500">Last confirmed with Shopify: {{ $draft->refreshed_at?->format('d M Y H:i') ?? 'Not yet refreshed' }}</p>
                </div>
                <x-filament::button color="gray" wire:click="back" wire:confirm="You may have changes that have not been pushed to Shopify. Leave this editor? Saved drafts will be kept.">Back to collections</x-filament::button>
            </div>
            <div class="syv-tabs flex w-fit rounded-xl border border-gray-200 p-1 dark:border-gray-700" role="tablist" aria-label="Collection content">
                <button type="button" wire:key="syv-tab-products" wire:click="setActiveTab('products')" role="tab" aria-selected="{{ $activeTab === 'products' ? 'true' : 'false' }}"
                    class="rounded-lg px-4 py-2 text-sm font-medium {{ $activeTab === 'products' ? 'bg-primary-600 text-white' : 'text-gray-600 dark:text-gray-300' }}">
                    Products ({{ count($parentProducts) }})
                </button>
                <button type="button" wire:key="syv-tab-vibes" wire:click="setActiveTab('vibes')" role="tab" aria-selected="{{ $activeTab === 'vibes' ? 'true' : 'false' }}"
                    class="rounded-lg px-4 py-2 text-sm font-medium {{ $activeTab === 'vibes' ? 'bg-primary-600 text-white' : 'text-gray-600 dark:text-gray-300' }}">
                    Shop Your Vibes ({{ count($draft->desired['cards']) }})
                </button>
            </div>
            <div x-show="formDirty" x-cloak role="status" class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-warning-900 dark:bg-warning-950 dark:text-warning-100">
                Pending field edits - not pushed to Shopify. Save the card to keep these edits in your draft.
            </div>
            @if ($draft->pending)
                <div role="status" class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-warning-900 dark:bg-warning-950 dark:text-warning-100">
                    <strong>{{ $draft->status === 'failed' ? 'Push failed - some changes are still pending' : 'Pending changes - not pushed to Shopify' }}</strong>
                    <p>Your saved draft contains changes that have not been confirmed by Shopify.</p>
                    @if ($draft->last_error)<p class="mt-2">{{ $draft->last_error }}</p>@endif
                    @if ($draft->progress)
                        <ul class="mt-2 list-inside list-disc">@foreach ($draft->progress as $message)<li>{{ $message }}</li>@endforeach</ul>
                    @endif
                </div>
            @else
                <x-filament::badge color="success">Up to date with Shopify</x-filament::badge>
            @endif
            <fieldset @disabled($draft->status === 'pushing') class="space-y-6 disabled:opacity-60">
                <p x-show="savingOrder" x-cloak role="status" class="syv-order-status">Saving order to draft - not pushed to Shopify...</p>
                <div class="flex flex-wrap gap-3">
                    <x-filament::button wire:click="reviewPush" x-bind:disabled="formDirty" :disabled="!$draft->pending" wire:loading.attr="disabled">Push Changes to Shopify</x-filament::button>
                    <x-filament::button color="gray" wire:click="refreshDraft(true)" wire:confirm="Discard your pending draft and reload the confirmed state from Shopify? This will not undo changes already pushed." wire:loading.attr="disabled">Discard Changes</x-filament::button>
                    <x-filament::button color="gray" wire:click="refreshDraft({{ $draft->pending ? 'true' : 'false' }})" wire:confirm="Refresh from Shopify? Any pending draft or unsaved field edits will be discarded." wire:loading.attr="disabled">Refresh from Shopify</x-filament::button>
                </div>
                @if ($confirmingPush)
                    <x-filament::section heading="Ready to push">
                        <dl class="space-y-2">@foreach ($summary as $label => $value)<div class="flex justify-between gap-4"><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach</dl>
                        <div class="syv-push-scope">
                            <p><strong>Preview layout:</strong> Card names, images, links and card order update the preview only.</p>
                            <p><strong>Linked collections:</strong> Product additions, removals and sorting update the actual Shopify collections and can affect the live storefront.</p>
                            <p><strong>Vibe assignments:</strong> Automated-collection membership tags are confirmed and applied separately when an assignment is saved.</p>
                            @if (!empty($draft->desired['new_collections']))<p><strong>New collections:</strong> These will be created with their handles and images and published to the live Online Store.</p>@endif
                            @if (!empty($draft->desired['delete_cards']))
                                <p><strong>Permanent Shopify deletions:</strong> The following vibe cards will be deleted. This cannot be undone. Products and images will be kept.</p>
                                <ul>@foreach ($draft->desired['delete_cards'] as $deletion)<li>{{ $deletion['card']['name'] }}</li>@endforeach</ul>
                            @endif
                            @if (!empty($draft->desired['delete_collections']))
                                <p><strong>Collections to delete from Shopify:</strong> Their collection pages will be removed. All products stay in Shopify and in their other collections. This cannot be undone.</p>
                                <ul>@foreach ($draft->desired['delete_collections'] as $deletion)<li>{{ $deletion['title'] }} - /collections/{{ $deletion['handle'] }}</li>@endforeach</ul>
                            @endif
                        </div>
                        <x-filament::button wire:click="pushChanges" x-bind:disabled="formDirty" wire:loading.attr="disabled">Push Changes</x-filament::button>
                        <x-filament::button color="gray" wire:click="$set('confirmingPush', false)">Cancel</x-filament::button>
                    </x-filament::section>
                @endif
                <div @class(['space-y-6', 'hidden' => $activeTab !== 'products']) wire:key="syv-products-panel">
                    <div class="flex items-center justify-between gap-3">
                        <div><h3 class="text-lg font-semibold">Products</h3><p class="text-sm text-gray-500">Manage one or several Shop Your Vibe assignments for each product.</p></div>
                        <input type="search" wire:model.live.debounce.300ms="assignmentProductSearch"
                            placeholder="Search product name or SKU" aria-label="Search products by name or SKU"
                            class="block w-full max-w-sm rounded-lg border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900" />
                    </div>
                    @php($parentCollection = $draft->desired['collections'][$draft->collection_gid] ?? null)
                    @php($parentSort = $parentCollection['sort'] ?? null)
                    @php($parentSortLabel = $parentSort ? str_replace('_', ' ', ucwords(strtolower($parentSort), '_')) : 'Unknown')
                    @php($parentIsAutomated = $parentCollection && ! ($parentCollection['membership_supported'] ?? true))
                    @php($parentManualSupported = $parentCollection && (($parentCollection['manual_supported'] ?? true) !== false || $parentSort !== 'UNSUPPORTED'))
                    @php($parentCanSort = $draft->status !== 'pushing' && $parentManualSupported)
                    <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm dark:border-gray-700 dark:bg-gray-900">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-gray-900 dark:text-white">Main collection sorting:</span>
                            @if ($parentCollection)
                                <span class="text-gray-600 dark:text-gray-300">Current Shopify sort: {{ $parentSortLabel }}</span>
                                <x-filament::badge :color="$parentIsAutomated ? 'gray' : 'info'">{{ $parentIsAutomated ? 'Automated collection' : 'Manual membership collection' }}</x-filament::badge>
                            @else
                                <span class="text-gray-600 dark:text-gray-300">Sorting data is not loaded for this draft.</span>
                            @endif
                            @if ($parentCanSort)
                                @if ($parentSort === 'MANUAL')
                                    <x-filament::badge color="success">Sortable manually</x-filament::badge>
                                @elseif ($parentCollection['enable_manual'] ?? false)
                                    <x-filament::badge color="warning">Manual sorting requested</x-filament::badge>
                                @else
                                    <x-filament::badge color="warning">Dragging will request Manual sorting</x-filament::badge>
                                @endif
                            @elseif ($parentCollection)
                                <x-filament::badge color="gray">Manual sorting not supported</x-filament::badge>
                            @endif
                        </div>
                        @if ($parentCanSort)
                            <p class="mt-1 text-xs text-gray-500">Drag handles appear on product cards below. Pushing changes will send this order to Shopify.</p>
                        @elseif (! $parentCollection)
                            <p class="mt-1 text-xs text-gray-500">Refresh from Shopify to reload the main collection sort mode before sorting products.</p>
                        @else
                            <p class="mt-1 text-xs text-gray-500">Shopify reports this collection as not manually sortable, so drag handles are hidden.</p>
                        @endif
                    </div>
                    @if ($parentCanSort)
                    <div data-order-grid class="syv-card-grid syv-product-grid"
                        wire:key="parent-products-{{ $draft->id }}-sortable-{{ md5($draft->collection_gid) }}"
                        x-sortable data-sortable-animation-duration="200"
                        x-on:end.stop="if ($event.oldIndex !== $event.newIndex) saveOrder($el, 'products', {{ \Illuminate\Support\Js::from($draft->collection_gid) }})">
                    @else
                    <div data-order-grid class="syv-card-grid syv-product-grid"
                        wire:key="parent-products-{{ $draft->id }}-fixed-{{ md5($draft->collection_gid) }}">
                    @endif
                        @if (count($filteredParentProducts) > 0)
                        @foreach ($filteredParentProducts as $product)
                            @php($productTags = collect($product['tags'])->map(fn ($tag) => mb_strtolower(trim($tag))))
                            @php($assignments = collect($vibeMappings)->filter(fn ($mapping) => filled($mapping['membership_tag'] ?? null) && $productTags->contains(mb_strtolower(trim($mapping['membership_tag'])))))
                                        <article wire:key="parent-product-{{ md5($product['id']) }}" data-order-key="{{ $product['id'] }}" x-sortable-item="{{ md5($product['id']) }}" class="syv-product-card rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                @if ($parentCanSort)<button type="button" x-sortable-handle x-bind:disabled="savingOrder" class="syv-drag-handle" aria-label="Drag {{ $product['title'] }}">Drag</button>@endif
                                @include('filament.pages.partials.shop-your-vibe-product-badges', ['product' => $product])
                                <div class="syv-product-image-wrap">
                                    @if ($product['image'])<img src="{{ $product['image'] }}" alt="" draggable="false" class="syv-product-image" loading="lazy">@endif
                                </div>
                                <h4 class="mt-2 font-medium">{{ $product['title'] }}</h4>
                                <p class="text-xs text-gray-500">SKU: {{ $product['sku'] ?: 'No SKU' }}</p>
                                <div class="my-3 syv-vibe-badge-list">
                                    <p class="text-xs font-semibold uppercase text-gray-500">Shop Your Vibes</p>
                                    <div class="syv-vibe-badges">
                                    @forelse ($assignments as $assignment)
                                        <x-filament::badge color="info">{{ $assignment['collection_name'] }}</x-filament::badge>
                                    @empty
                                        <span class="text-sm text-gray-500">None</span>
                                    @endforelse
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-2 syv-vibe-actions">
                                    <x-filament::button size="xs" color="warning" wire:click="openProductAssignments({{ \Illuminate\Support\Js::from($product['id']) }})">Manage Vibes</x-filament::button>
                                </div>
                            </article>
                        @endforeach
                        @else
                            <p class="text-gray-500">{{ $assignmentProductSearch !== '' ? 'No products match your search.' : 'No products belong to this Shopify collection.' }}</p>
                        @endif
                    </div>
                </div>
                <div @class(['space-y-6', 'hidden' => $activeTab !== 'vibes']) wire:key="syv-vibes-panel">
                <div class="flex items-center justify-between gap-3">
                    <div><h3 class="text-lg font-semibold">Vibes</h3><p class="text-sm text-gray-500">Drag the grip to reorder. Reordering saves a pending draft.</p></div>
                    <div class="flex flex-wrap gap-2">
                        <x-filament::button color="gray" wire:click="openMappingUpload" wire:loading.attr="disabled">Bulk Upload Mappings</x-filament::button>
                        <x-filament::button wire:click="openCollectionPicker(true)" wire:loading.attr="disabled">Add Vibe</x-filament::button>
                    </div>
                </div>
                <div data-order-grid class="syv-card-grid"
                    wire:key="vibe-grid-{{ $draft->id }}-{{ $draft->status === 'pushing' ? 'locked' : 'editable' }}"
                    @if ($draft->status !== 'pushing')
                        x-sortable data-sortable-animation-duration="200"
                        x-on:end.stop="if ($event.oldIndex !== $event.newIndex) saveOrder($el, 'cards')"
                    @endif>
                    @foreach ($draft->desired['cards'] as $vibe)
                        <article wire:key="vibe-{{ md5($vibe['key']) }}" data-order-key="{{ $vibe['key'] }}" x-sortable-item="{{ md5($vibe['key']) }}"
                            class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                            <button type="button" x-sortable-handle x-bind:disabled="savingOrder" class="syv-drag-handle" aria-label="Drag {{ $vibe['name'] }}">Drag</button>
                            <button type="button" wire:click="editCard({{ \Illuminate\Support\Js::from($vibe['key']) }})" x-on:click="if (formDirty && !confirm('Discard unsaved card fields and open this vibe?')) $event.stopImmediatePropagation(); else formDirty = false" class="block w-full text-left">
                                @if ($vibe['image_url'])<img src="{{ $vibe['image_url'] }}" alt="" draggable="false" class="syv-vibe-image" loading="lazy">@else<div class="syv-vibe-image syv-image-placeholder">Choose an image</div>@endif
                                <h4 class="my-3 font-semibold">{{ $vibe['name'] }}</h4>
                                @php($vibeMapping = collect($vibeMappings)->firstWhere('shopify_collection_id', $vibe['collection_gid']))
                                @if ($vibeMapping)
                                    <dl class="space-y-1 text-xs text-gray-500">
                                        <div><dt class="inline font-semibold">Handle:</dt> <dd class="inline">{{ $vibeMapping['collection_handle'] }}</dd></div>
                                        <div><dt class="inline font-semibold">Membership Tag:</dt> <dd class="inline">{{ ($vibeMapping['membership_tag'] ?? null) ?: 'Requires configuration' }}</dd></div>
                                        @if (filled($vibeMapping['design_value'] ?? null))<div><dt class="inline font-semibold">Design:</dt> <dd class="inline">{{ $vibeMapping['design_value'] }}</dd></div>@endif
                                        @if (filled($vibeMapping['colour_style_value'] ?? null))<div><dt class="inline font-semibold">Colour Style:</dt> <dd class="inline">{{ $vibeMapping['colour_style_value'] }}</dd></div>@endif
                                        <div><dd>{{ $vibeMapping['product_count'] ?? 0 }} Products</dd></div>
                                    </dl>
                                @endif
                            </button>
                            <div class="flex flex-wrap gap-2">
                                <x-filament::button size="xs" color="gray" x-on:click="move($el, 'cards', {{ \Illuminate\Support\Js::from($vibe['key']) }}, -1)" aria-label="Move vibe earlier">Up</x-filament::button>
                                <x-filament::button size="xs" color="gray" x-on:click="move($el, 'cards', {{ \Illuminate\Support\Js::from($vibe['key']) }}, 1)" aria-label="Move vibe later">Down</x-filament::button>
                                <x-filament::button size="xs" color="gray" wire:click="editCard({{ \Illuminate\Support\Js::from($vibe['key']) }})" x-on:click="if (formDirty &amp;&amp; !confirm('Discard unsaved card fields and open this vibe?')) $event.stopImmediatePropagation(); else formDirty = false">Edit</x-filament::button>
                                <x-filament::button size="xs" color="danger" wire:click="mountAction('removeVibe', {{ \Illuminate\Support\Js::from(['key' => $vibe['key']]) }})">Remove</x-filament::button>
                            </div>
                        </article>
                    @endforeach
                </div>
                @if ($card)
                    <x-filament::section :heading="$card['name']">
                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="space-y-1"><span>Name</span><x-filament::input.wrapper><x-filament::input wire:model="cardForm.name" x-on:input="formDirty = true" /></x-filament::input.wrapper></label>
                            <label class="space-y-1"><span>Link</span><x-filament::input.wrapper><x-filament::input wire:model="cardForm.link" :disabled="str_starts_with($card['collection_gid'] ?? '', 'new:')" x-on:input="formDirty = true" /></x-filament::input.wrapper></label>
                            <div>
                                @if ($cardForm['image_url'] ?? null)<img src="{{ $cardForm['image_url'] }}" alt="Card preview" class="syv-edit-image">@endif
                                <x-filament::button color="gray" wire:click="findImages" wire:loading.attr="disabled">Choose image from Shopify</x-filament::button>
                            </div>
                            @if (!empty($mappingForm['id']))
                                <label class="space-y-1"><span>Membership Tag</span><x-filament::input.wrapper><x-filament::input wire:model="mappingForm.membership_tag" x-on:input="formDirty = true" placeholder="Requires configuration" /></x-filament::input.wrapper><small class="text-gray-500">The Shopify tag that controls automated collection membership.</small></label>
                                <label class="space-y-1"><span>Design</span><x-filament::input.wrapper><x-filament::input wire:model="mappingForm.design_value" x-on:input="formDirty = true" /></x-filament::input.wrapper><small class="text-gray-500">Generic CMS Design value; this is not assumed to be a tag.</small></label>
                                <label class="space-y-1"><span>Colour Style</span><x-filament::input.wrapper><x-filament::input wire:model="mappingForm.colour_style_value" x-on:input="formDirty = true" /></x-filament::input.wrapper><small class="text-gray-500">Generic CMS Colour Style value; this is not assumed to be a tag.</small></label>
                            @endif
                        </div>
                        <x-filament::button class="mt-4" wire:click="saveCard" wire:loading.attr="disabled">Save card and mapping</x-filament::button>
                        @if ($choosingImage)
                            <div class="mt-4 space-y-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                <div class="flex gap-3"><x-filament::input.wrapper><x-filament::input wire:model="imageSearch" placeholder="Search image filenames" aria-label="Search image filenames" /></x-filament::input.wrapper><x-filament::button wire:click="findImages">Search images</x-filament::button><x-filament::button color="gray" wire:click="$set('choosingImage', false)">Cancel</x-filament::button></div>
                                <div class="syv-image-grid">@foreach ($images as $image)<button type="button" wire:click="chooseImage({{ \Illuminate\Support\Js::from($image['id']) }})"><img src="{{ data_get($image, 'image.url') }}" alt="{{ $image['alt'] ?: 'Choose this image' }}" class="syv-product-image" loading="lazy"></button>@endforeach</div>
                                @if ($imageAfter)<x-filament::button color="gray" wire:click="findImages(true)">Load more images</x-filament::button>@endif
                                @if (!$images)<p>No matching images found.</p>@endif
                            </div>
                        @endif
                        <div class="mt-6 space-y-4">
                            <h3 class="text-lg font-semibold">Products</h3>
                            @if (!$collection)
                                <p class="text-sm text-gray-500">This card's link has no loaded collection. Save a link to a synchronized collection, then reopen the vibe to load its products.</p>
                            @else
                                <h4 class="font-semibold">{{ $collection['title'] }}</h4>
                                @if (str_starts_with($collection['gid'], 'new:'))<p class="text-sm text-gray-500">New collection - will be created and published when you push. Add and sort its products below.</p>@else<p>Current Shopify sort: {{ str_replace('_', ' ', ucwords(strtolower($collection['sort']), '_')) }}</p>@endif
                                @php($collectionManualSupported = ($collection['manual_supported'] ?? true) !== false || ($collection['sort'] ?? null) !== 'UNSUPPORTED')
                                @if (!$collectionManualSupported)
                                    <x-filament::badge color="gray">Manual sorting not supported</x-filament::badge>
                                @elseif ($collection['sort'] === 'MANUAL')
                                    <x-filament::badge color="success">Manual sorting: ON</x-filament::badge>
                                @elseif ($collection['enable_manual'])
                                    <x-filament::badge color="warning">Manual sorting requested - pending push</x-filament::badge>
                                @else
                                    <x-filament::badge color="gray">Manual sorting: OFF</x-filament::badge>
                                    <x-filament::button color="gray" wire:click="enableManual({{ \Illuminate\Support\Js::from($collection['gid']) }})" wire:confirm="This will change the Shopify collection's product sorting mode to Manual when you push changes. Continue?">Enable Manual Sorting</x-filament::button>
                                @endif
                                @if (!$collection['membership_supported'])
                                    <p class="syv-membership-note">This is an automated collection. Shop Your Vibe assignments are managed by adding or removing only its configured membership tag.</p>
                                @else
                                    <p class="syv-membership-note">Remove takes a product out of this linked Shopify collection when you push changes. It does not delete the product. This can also affect the live storefront.</p>
                                @endif
                                @php($canSort = $draft->status !== 'pushing' && $collectionManualSupported)
                                @if ($canSort)
                                <div data-order-grid class="syv-card-grid syv-product-grid"
                                    wire:key="vibe-products-{{ md5($collection['gid']) }}-sortable"
                                        x-sortable data-sortable-animation-duration="200"
                                        x-on:end.stop="if ($event.oldIndex !== $event.newIndex) saveOrder($el, 'products', {{ \Illuminate\Support\Js::from($collection['gid']) }})">
                                @else
                                <div data-order-grid class="syv-card-grid syv-product-grid"
                                    wire:key="vibe-products-{{ md5($collection['gid']) }}-fixed">
                                @endif
                                    @foreach ($collection['products'] as $product)
                                        <article wire:key="vibe-product-{{ md5($collection['gid'].'|'.$product['id']) }}" data-order-key="{{ $product['id'] }}" x-sortable-item="{{ md5($product['id']) }}" class="syv-product-card rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                            @if ($canSort)<button type="button" x-sortable-handle x-bind:disabled="savingOrder" class="syv-drag-handle" aria-label="Drag {{ $product['title'] }}">Drag</button>@endif
                                            @include('filament.pages.partials.shop-your-vibe-product-badges', ['product' => $product])
                                            <div class="syv-product-image-wrap">
                                                @if ($product['image'])<img src="{{ $product['image'] }}" alt="" draggable="false" class="syv-product-image" loading="lazy">@endif
                                            </div>
                                            <h5 class="mt-2 font-medium">{{ $product['title'] }}</h5>
                                            @if ($product['sku'])<p class="mb-2 text-xs text-gray-500">SKU: {{ $product['sku'] }}</p>@endif
                                            <div class="mt-2 flex gap-2">
                                                @if ($collection['membership_supported'])
                                                    <x-filament::button size="xs" color="danger" wire:click="removeProduct({{ \Illuminate\Support\Js::from($collection['gid']) }}, {{ \Illuminate\Support\Js::from($product['id']) }})">Remove</x-filament::button>
                                                @endif
                                                @if (collect($parentProducts)->contains('id', $product['id']))
                                                    <x-filament::button size="xs" color="warning" wire:click="openProductAssignments({{ \Illuminate\Support\Js::from($product['id']) }})">Manage Vibes</x-filament::button>
                                                @endif
                                            </div>
                                        </article>
                                    @endforeach
                                </div>
                                @if ($collection['membership_supported'])<x-filament::button wire:click="$set('addingProducts', true)">Add Products</x-filament::button>@endif
                                @if ($addingProducts)
                                    <div class="space-y-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                        <x-filament::input.wrapper><x-filament::input wire:model.live.debounce.300ms="productSearch" placeholder="Search product title or SKU" aria-label="Search product title or SKU" /></x-filament::input.wrapper>
                                        <p class="text-sm text-gray-500">Showing up to 60 matches. Narrow your search to find more products.</p>
                                        <div class="grid max-h-96 gap-3 overflow-y-auto sm:grid-cols-2">
                                            @foreach ($products as $product)
                                                <label class="flex items-center gap-3 rounded border border-gray-200 p-2 dark:border-gray-700">
                                                    <input type="checkbox" wire:model="selectedProducts" value="{{ $product->shopify_id }}">
                                                    @if ($product->images->first()?->src)<img src="{{ $product->images->first()->src }}" alt="" class="syv-product-thumbnail" loading="lazy">@endif
                                                    <span>{{ $product->title }}<small class="block text-gray-500">{{ $product->variants->first()?->sku }}</small></span>
                                                </label>
                                            @endforeach
                                        </div>
                                        <x-filament::button wire:click="addProducts({{ \Illuminate\Support\Js::from($collection['gid']) }})">Add selected products to draft</x-filament::button>
                                        <x-filament::button color="gray" wire:click="$set('addingProducts', false)">Cancel</x-filament::button>
                                    </div>
                                @endif
                            @endif
                        </div>
                    </x-filament::section>
                @endif
                </div>
            </fieldset>
        @endif

        <x-filament::modal id="shop-your-vibe-collections" width="3xl"
            :heading="$addingCard ? 'Add Vibe' : 'Add Shop Your Vibe'"
            x-on:modal-closed.stop="$wire.closeCollectionPicker()">
            @if ($addingParent || $addingCard)
                @if ($loadError)<p role="alert" class="syv-picker-error">{{ $loadError }}</p>@endif
                @if ($addingCard)
                    <div class="syv-collection-mode" role="group" aria-label="Collection source">
                        <label><input type="radio" wire:model.live="collectionMode" value="existing"> Select an existing collection</label>
                        <label><input type="radio" wire:model.live="collectionMode" value="new"> Create a new collection</label>
                    </div>
                @endif
                @if ($addingCard && $collectionMode === 'new')
                    <p class="my-3 text-sm text-gray-500">Save a new collection to this draft. Pushing changes creates it in Shopify, applies its image and publishes it to the Online Store.</p>
                    {{ $this->newCollectionForm }}
                @else
                    <p class="my-3 text-sm text-gray-500">Search by collection title or handle inside the dropdown. Already configured collections are excluded when adding Shop Your Vibe.</p>
                    {{ $this->collectionPickerForm }}
                @endif
            @endif
            <x-slot name="footer">
                @if ($addingCard && $collectionMode === 'new')
                    <x-filament::button wire:click="createCollectionVibe" wire:loading.attr="disabled">Add new collection to draft</x-filament::button>
                @else
                    <x-filament::button wire:click="confirmCollectionSelection" wire:loading.attr="disabled" :disabled="blank($selectedCollectionGid)">{{ $addingCard ? 'Add Vibe' : 'Continue' }}</x-filament::button>
                @endif
                <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'shop-your-vibe-collections' })">Cancel</x-filament::button>
            </x-slot>
        </x-filament::modal>

        <x-filament::modal id="manage-vibe-assignments" width="2xl" heading="Manage Shop Your Vibes">
            @if ($managedProduct)
                <p class="font-semibold">{{ $managedProduct['title'] }}</p>
                <p class="mb-4 text-sm text-gray-500">SKU: {{ $managedProduct['sku'] ?: 'No SKU' }}</p>
                <div class="space-y-3">
                    @foreach ($vibeMappings as $mapping)
                        <label class="flex items-start gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                            <input type="checkbox" wire:model="selectedVibes" value="{{ $mapping['shopify_collection_id'] }}" @disabled(blank($mapping['membership_tag'] ?? null))>
                            <span><strong>{{ $mapping['collection_name'] }}</strong><small class="block text-gray-500">{{ ($mapping['membership_tag'] ?? null) ?: 'Membership tag requires configuration' }}</small></span>
                        </label>
                    @endforeach
                </div>
                <p class="mt-4 text-sm text-gray-500">Saving verifies the live Shopify tags and updates the mapped membership tags, Design and Colour Style. Values still required by another assigned vibe are preserved.</p>
            @endif
            <x-slot name="footer">
                <x-filament::button wire:click="reviewProductAssignments" wire:loading.attr="disabled">Save assignments</x-filament::button>
                <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'manage-vibe-assignments' })">Cancel</x-filament::button>
            </x-slot>
        </x-filament::modal>

        <x-filament::modal id="confirm-vibe-assignments" width="lg" heading="Update Shopify assignments?">
            @if ($confirmingAssignments && $managedProduct)
                <p><strong>{{ $managedProduct['title'] }}</strong></p>
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">Clicking <strong>Yes, update Shopify</strong> will immediately add or remove the membership tags and any configured Design and Colour Style values in Shopify.</p>
                @if ($assignmentAdds->isNotEmpty())
                    <div class="mt-4">
                        <strong>Add to:</strong>
                        <ul class="mt-1 list-inside list-disc space-y-1">
                            @foreach ($assignmentAddDetails as $change)
                                <li>
                                    {{ $change['name'] }}
                                    <span class="text-sm text-gray-500">- tag: <code>{{ $change['membership_tag'] }}</code></span>
                                    @if (filled($change['design_value']))
                                        <span class="text-sm text-gray-500">; Bracelet Design: <code>{{ $change['design_value'] }}</code></span>
                                    @endif
                                    @if (filled($change['colour_style_value']))
                                        <span class="text-sm text-gray-500">; Colour Style: <code>{{ $change['colour_style_value'] }}</code></span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if ($assignmentRemovals->isNotEmpty())
                    <div class="mt-4"><strong>Remove from:</strong><ul class="list-inside list-disc">@foreach ($assignmentRemovals as $name)<li>{{ $name }}</li>@endforeach</ul></div>
                @endif
                @if ($assignmentAdds->isEmpty() && $assignmentRemovals->isEmpty())
                    <p class="mt-4 text-sm text-gray-500">No assignment changes were selected.</p>
                @endif
            @endif
            <x-slot name="footer">
                <x-filament::button color="danger" wire:click="saveProductAssignments" wire:loading.attr="disabled">Yes, update Shopify</x-filament::button>
                <x-filament::button color="gray" wire:click="cancelProductAssignmentConfirmation">Go back</x-filament::button>
            </x-slot>
        </x-filament::modal>

        <x-filament::modal id="bulk-vibe-mappings" width="2xl" heading="Bulk Upload Shop Your Vibe Mappings"
            x-on:modal-closed.stop="$wire.closeMappingUpload()">
            @if ($uploadingMappings)
                @if ($loadError)<p role="alert" class="mb-4 rounded-lg border border-danger-300 bg-danger-50 p-3 text-danger-800">{{ $loadError }}</p>@endif
                <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">Upload one CSV for all Shop Your Vibe collections. The existing membership tag detected from Shopify is never changed. Design and Colour Style may be blank; identical repeated rows are ignored.</p>
                <div class="mb-4 overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                    <table class="w-full text-left text-sm">
                        <thead><tr><th class="p-2">Handle</th><th class="p-2">Design</th><th class="p-2">Colour Style</th></tr></thead>
                        <tbody><tr><td class="p-2">bracelets-gold</td><td class="p-2">Beaded</td><td class="p-2">Solid</td></tr></tbody>
                    </table>
                </div>
                {{ $this->mappingUploadForm }}
            @endif
            <x-slot name="footer">
                <x-filament::button wire:click="importMappingUpload" wire:loading.attr="disabled">Upload and Apply Mappings</x-filament::button>
                <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'bulk-vibe-mappings' })">Cancel</x-filament::button>
            </x-slot>
        </x-filament::modal>
    </div>
</x-filament-panels::page>
