<x-filament-panels::page>
    <link rel="stylesheet" href="{{ asset('css/shop-your-vibe.css') }}?v={{ filemtime(public_path('css/shop-your-vibe.css')) }}">
    <div x-data="{
        dragging: null, formDirty: false,
        pending() { return this.formDirty || this.$el.dataset.pending === 'true'; },
        drop(event, type, target, gid = null) {
            event.preventDefault();
            if (!this.dragging || this.dragging === target) return;
            const grid = event.currentTarget.closest('[data-order-grid]');
            const ids = Array.from(grid.children).map(el => el.dataset.orderKey).filter(Boolean);
            if (!ids.includes(this.dragging)) return;
            ids.splice(ids.indexOf(this.dragging), 1);
            ids.splice(ids.indexOf(target), 0, this.dragging);
            this.dragging = null;
            if (type === 'cards') this.$wire.reorderCards(ids);
            else this.$wire.reorderProducts(gid, ids);
        },
        move(event, type, key, direction, gid = null) {
            const grid = event.currentTarget.closest('[data-order-grid]');
            const ids = Array.from(grid.children).map(el => el.dataset.orderKey).filter(Boolean);
            const index = ids.indexOf(key), next = index + direction;
            if (next < 0 || next >= ids.length) return;
            [ids[index], ids[next]] = [ids[next], ids[index]];
            if (type === 'cards') this.$wire.reorderCards(ids);
            else this.$wire.reorderProducts(gid, ids);
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
                <x-filament::button wire:click="$set('addingParent', true)">Add Shop Your Vibe</x-filament::button>
                <x-filament::button color="gray" wire:click="loadParents(true)" wire:loading.attr="disabled">Refresh from Shopify</x-filament::button>
            </div>
            <p class="text-sm text-gray-500">Manage collection previews. Changes stay in the CMS until you push them to Shopify.</p>
            <div class="syv-parent-grid">
                @forelse ($parents as $parent)
                    <x-filament::section class="syv-parent-card" wire:key="parent-{{ $parent['gid'] }}">
                        <h2 class="text-lg font-semibold">{{ $parent['title'] }}</h2>
                        <p class="text-sm text-gray-500">{{ $parent['handle'] }}</p>
                        <p class="my-3">{{ $parent['count'] }} Vibes</p>
                        @if ($pendingDrafts->has($parent['gid']))
                            <x-filament::badge color="warning">Pending Changes</x-filament::badge>
                        @else
                            <x-filament::badge color="success">Up to date at last refresh</x-filament::badge>
                        @endif
                        <x-filament::button class="syv-manage-button" wire:click="manage({{ \Illuminate\Support\Js::from($parent['gid']) }})" wire:target="manage" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="manage">Manage</span>
                            <span wire:loading wire:target="manage">Opening editor…</span>
                        </x-filament::button>
                    </x-filament::section>
                @empty
                    <p class="text-gray-500">No configured collections found. Add Shop Your Vibe to start a preview layout.</p>
                @endforelse
            </div>
        @else
            @if ($draft->status === 'pushing')
                <div wire:poll.3s="pollPush" role="status" class="rounded-xl border border-primary-300 p-4">
                    Pushing to Shopify… You can return later; your draft is saved. Editing is paused until this push finishes.
                </div>
            @endif
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-2xl font-bold">{{ $draft->desired['parent']['title'] }} — Shop Your Vibe</h2>
                    <p class="text-sm text-gray-500">Last confirmed with Shopify: {{ $draft->refreshed_at?->format('d M Y H:i') ?? 'Not yet refreshed' }}</p>
                </div>
                <x-filament::button color="gray" wire:click="back" wire:confirm="You may have changes that have not been pushed to Shopify. Leave this editor? Saved drafts will be kept.">Back to collections</x-filament::button>
            </div>
            <div x-show="formDirty" x-cloak role="status" class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-warning-900 dark:bg-warning-950 dark:text-warning-100">
                Pending field edits — not pushed to Shopify. Save the card to keep these edits in your draft.
            </div>
            @if ($draft->pending)
                <div role="status" class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-warning-900 dark:bg-warning-950 dark:text-warning-100">
                    <strong>{{ $draft->status === 'failed' ? 'Push failed — some changes are still pending' : 'Pending changes — not pushed to Shopify' }}</strong>
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
                <div class="flex flex-wrap gap-3">
                    <x-filament::button wire:click="reviewPush" x-bind:disabled="formDirty" :disabled="!$draft->pending" wire:loading.attr="disabled">Push Changes to Shopify</x-filament::button>
                    <x-filament::button color="gray" wire:click="refreshDraft(true)" wire:confirm="Discard your pending draft and reload the confirmed state from Shopify? This will not undo changes already pushed." wire:loading.attr="disabled">Discard Changes</x-filament::button>
                    <x-filament::button color="gray" wire:click="refreshDraft({{ $draft->pending ? 'true' : 'false' }})" wire:confirm="Refresh from Shopify? Any pending draft or unsaved field edits will be discarded." wire:loading.attr="disabled">Refresh from Shopify</x-filament::button>
                </div>
                @if ($confirmingPush)
                    <x-filament::section heading="Ready to push">
                        <dl class="space-y-2">@foreach ($summary as $label => $value)<div class="flex justify-between gap-4"><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach</dl>
                        <p class="my-3 text-sm text-gray-500">Only the final saved draft will be sent. Product changes affect the collections represented by these vibes.</p>
                        <x-filament::button wire:click="pushChanges" x-bind:disabled="formDirty" wire:loading.attr="disabled">Push Changes</x-filament::button>
                        <x-filament::button color="gray" wire:click="$set('confirmingPush', false)">Cancel</x-filament::button>
                    </x-filament::section>
                @endif
                <div class="flex items-center justify-between gap-3">
                    <div><h3 class="text-lg font-semibold">Vibes</h3><p class="text-sm text-gray-500">Drag the grip to reorder, or use the arrow buttons. Reordering saves a pending draft.</p></div>
                    <x-filament::button wire:click="$set('addingCard', true)">Add Vibe</x-filament::button>
                </div>
                <div data-order-grid class="syv-card-grid">
                    @foreach ($draft->desired['cards'] as $vibe)
                        <article wire:key="vibe-{{ $vibe['key'] }}" data-order-key="{{ $vibe['key'] }}"
                            x-on:dragover.prevent x-on:drop="drop($event, 'cards', {{ \Illuminate\Support\Js::from($vibe['key']) }})"
                            class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                            <button type="button" draggable="true" x-on:dragstart="dragging = {{ \Illuminate\Support\Js::from($vibe['key']) }}; $event.dataTransfer.setData('text/plain', dragging)" x-on:dragend="dragging = null" class="mb-2 cursor-grab text-gray-500" aria-label="Drag {{ $vibe['name'] }}">⠿ Drag</button>
                            <button type="button" wire:click="editCard({{ \Illuminate\Support\Js::from($vibe['key']) }})" x-on:click="if (formDirty && !confirm('Discard unsaved card fields and open this vibe?')) $event.stopImmediatePropagation(); else formDirty = false" class="block w-full text-left">
                                @if ($vibe['image_url'])<img src="{{ $vibe['image_url'] }}" alt="" class="syv-vibe-image" loading="lazy">@else<div class="syv-vibe-image syv-image-placeholder">Choose an image</div>@endif
                                <h4 class="my-3 font-semibold">{{ $vibe['name'] }}</h4>
                            </button>
                            <div class="flex flex-wrap gap-2">
                                <x-filament::button size="xs" color="gray" x-on:click="move($event, 'cards', {{ \Illuminate\Support\Js::from($vibe['key']) }}, -1)" aria-label="Move vibe earlier">←</x-filament::button>
                                <x-filament::button size="xs" color="gray" x-on:click="move($event, 'cards', {{ \Illuminate\Support\Js::from($vibe['key']) }}, 1)" aria-label="Move vibe later">→</x-filament::button>
                                <x-filament::button size="xs" color="gray" wire:click="editCard({{ \Illuminate\Support\Js::from($vibe['key']) }})" x-on:click="if (formDirty &amp;&amp; !confirm('Discard unsaved card fields and open this vibe?')) $event.stopImmediatePropagation(); else formDirty = false">Edit</x-filament::button>
                                <x-filament::button size="xs" color="danger" wire:click="removeCard({{ \Illuminate\Support\Js::from($vibe['key']) }})" wire:confirm="Remove this vibe from the preview layout? Its Shopify card will be kept. Product edits for this vibe will be dropped if no other card represents the same collection.">Remove</x-filament::button>
                            </div>
                        </article>
                    @endforeach
                </div>
                @if ($card)
                    <x-filament::section :heading="$card['name']">
                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="space-y-1"><span>Name</span><x-filament::input.wrapper><x-filament::input wire:model="cardForm.name" x-on:input="formDirty = true" /></x-filament::input.wrapper></label>
                            <label class="space-y-1"><span>Link</span><x-filament::input.wrapper><x-filament::input wire:model="cardForm.link" x-on:input="formDirty = true" /></x-filament::input.wrapper></label>
                            <div>
                                @if ($cardForm['image_url'] ?? null)<img src="{{ $cardForm['image_url'] }}" alt="Card preview" class="syv-edit-image">@endif
                                <x-filament::button color="gray" wire:click="findImages" wire:loading.attr="disabled">Choose image from Shopify</x-filament::button>
                            </div>
                        </div>
                        <x-filament::button class="mt-4" wire:click="saveCard" wire:loading.attr="disabled">Save card to draft</x-filament::button>
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
                                <p class="text-sm text-gray-500">This card’s link has no loaded collection. Save a link to a synchronized collection, then reopen the vibe to load its products.</p>
                            @else
                                <h4 class="font-semibold">{{ $collection['title'] }}</h4>
                                <p>Current Shopify sort: {{ str_replace('_', ' ', ucwords(strtolower($collection['sort']), '_')) }}</p>
                                @if (!$collection['manual_supported'])
                                    <x-filament::badge color="gray">Manual sorting not supported</x-filament::badge>
                                @elseif ($collection['sort'] === 'MANUAL')
                                    <x-filament::badge color="success">Manual sorting: ON</x-filament::badge>
                                @elseif ($collection['enable_manual'])
                                    <x-filament::badge color="warning">Manual sorting requested — pending push</x-filament::badge>
                                @else
                                    <x-filament::badge color="gray">Manual sorting: OFF</x-filament::badge>
                                    <x-filament::button color="gray" wire:click="enableManual({{ \Illuminate\Support\Js::from($collection['gid']) }})" wire:confirm="This will change the Shopify collection’s product sorting mode to Manual when you push changes. Continue?">Enable Manual Sorting</x-filament::button>
                                @endif
                                @if (!$collection['membership_supported'])<p class="text-sm text-gray-500">Shopify rules control this automated collection’s products. Adding and removing products is unavailable.</p>@endif
                                @php($canSort = $collection['manual_supported'] && ($collection['sort'] === 'MANUAL' || $collection['enable_manual']))
                                <div data-order-grid class="syv-card-grid">
                                    @foreach ($collection['products'] as $product)
                                        <article wire:key="vibe-product-{{ $collection['gid'] }}-{{ $product['id'] }}" data-order-key="{{ $product['id'] }}" class="rounded-lg border border-gray-200 p-3 dark:border-gray-700"
                                            @if ($canSort) x-on:dragover.prevent x-on:drop="drop($event, 'products', {{ \Illuminate\Support\Js::from($product['id']) }}, {{ \Illuminate\Support\Js::from($collection['gid']) }})" @endif>
                                            @if ($canSort)<button type="button" draggable="true" x-on:dragstart="dragging = {{ \Illuminate\Support\Js::from($product['id']) }}; $event.dataTransfer.setData('text/plain', dragging)" x-on:dragend="dragging = null" class="mb-2 cursor-grab text-gray-500" aria-label="Drag {{ $product['title'] }}">⠿ Drag</button>@endif
                                            @if ($product['image'])<img src="{{ $product['image'] }}" alt="" class="syv-product-image" loading="lazy">@endif
                                            <h5 class="mt-2 font-medium">{{ $product['title'] }}</h5>
                                            @if ($product['sku'])<p class="mb-2 text-xs text-gray-500">SKU: {{ $product['sku'] }}</p>@endif
                                            <div class="mt-2 flex gap-2">
                                                @if ($canSort)
                                                    <x-filament::button size="xs" color="gray" x-on:click="move($event, 'products', {{ \Illuminate\Support\Js::from($product['id']) }}, -1, {{ \Illuminate\Support\Js::from($collection['gid']) }})" aria-label="Move product earlier">←</x-filament::button>
                                                    <x-filament::button size="xs" color="gray" x-on:click="move($event, 'products', {{ \Illuminate\Support\Js::from($product['id']) }}, 1, {{ \Illuminate\Support\Js::from($collection['gid']) }})" aria-label="Move product later">→</x-filament::button>
                                                @endif
                                                @if ($collection['membership_supported'])<x-filament::button size="xs" color="danger" wire:click="removeProduct({{ \Illuminate\Support\Js::from($collection['gid']) }}, {{ \Illuminate\Support\Js::from($product['id']) }})">Remove</x-filament::button>@endif
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
            </fieldset>
        @endif

        @if ($addingParent || $addingCard)
            <x-filament::section :heading="$addingParent ? 'Add Shop Your Vibe' : 'Choose the collection represented by this vibe'">
                <x-filament::input.wrapper><x-filament::input wire:model.live.debounce.300ms="collectionSearch" placeholder="Search collection title or handle" aria-label="Search collections" /></x-filament::input.wrapper>
                <p class="my-3 text-sm text-gray-500">Choose from synchronized collections. Showing up to 60 matches; narrow your search if needed.</p>
                <div class="max-h-96 space-y-2 overflow-y-auto">
                    @forelse ($collections as $option)
                        <div class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                            <span>{{ $option->title }}<small class="block text-gray-500">{{ $option->handle }}</small></span>
                            <x-filament::button size="sm" wire:click="{{ $addingParent ? 'manage' : 'addCard' }}({{ \Illuminate\Support\Js::from($option->shopify_id) }})" wire:loading.attr="disabled">Select</x-filament::button>
                        </div>
                    @empty<p>No eligible synchronized collections match your search.</p>@endforelse
                </div>
                <x-filament::button class="mt-3" color="gray" wire:click="$set('{{ $addingParent ? 'addingParent' : 'addingCard' }}', false)">Cancel</x-filament::button>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
