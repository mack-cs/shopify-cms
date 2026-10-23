@once
    @push('styles')
        @php($shopYourVibeCss = public_path('css/shop-your-vibe.css'))
        @php($shopYourVibeCssVersion = is_file($shopYourVibeCss) ? '?v='.filemtime($shopYourVibeCss) : '')
        <link rel="stylesheet" href="{{ asset('css/shop-your-vibe.css') }}{{ $shopYourVibeCssVersion }}">
    @endpush
@endonce

<x-filament-panels::page>
    <script>
        window.syvEditor = function () {
            return {
        formDirty: false, savingOrder: false, localPending: false, productQuery: '', orderEpoch: 0, orderSession: (window.crypto && crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2)), orderError: '', persistChain: Promise.resolve(), statusTimer: null, statusMode: 'idle', wasLoading: false, renderingProducts: false, statusStartedAt: 0, loadNotice: false, loadNoticeText: '', loadNoticeTimer: null, productDrag: null,
        pending() { return this.formDirty || this.localPending || this.$el.dataset.pending === 'true'; },
        canPush() { return this.localPending || this.$root.dataset.pending === 'true'; },
        destroy() { this.stopStatusTimer(); },
        stopStatusTimer() {
            if (this.statusTimer) {
                clearInterval(this.statusTimer);
                this.statusTimer = null;
            }
        },
        initStatus() {
            this.$watch('productQuery', () => {
                this.$el.querySelectorAll('[data-order-type=products]').forEach((grid) => {
                    grid.sortable?.option('disabled', true);
                });
            });
            this.syncHydration();
            if (window.Livewire?.hook) {
                Livewire.hook('commit', ({ succeed }) => {
                    succeed(() => {
                        if (!this.$el?.isConnected) return;
                        this.syncHydration();
                    });
                });
            }
        },
        syncHydration() {
            this.$nextTick(() => this.bindProductDrag());
            const refreshing = this.$el.dataset.refresh === '1';
            const needsPoll = refreshing || (this.$el.dataset.poll === '1' && this.$el.dataset.productsLoaded !== '1');
            if (!needsPoll) {
                if (this.$el.dataset.productsLoaded === '1') this.finishLoaded(false);
                else this.stopStatusTimer();
                return;
            }
            this.wasLoading = true;
            this.statusMode = 'loading';
            this.statusStartedAt = this.statusStartedAt || Date.now();
            if (this.statusTimer) return;
            this.pollStatus();
            this.statusTimer = setInterval(() => this.pollStatus(), 2000);
        },
        applyProgress(status) {
            const banner = this.$refs.statusBanner;
            if (!banner) return;
            const stage = banner.querySelector('[data-status-stage]');
            const count = banner.querySelector('[data-status-count]');
            const bar = banner.querySelector('[data-status-progress]');
            const indeterminate = banner.querySelector('[data-status-indeterminate]');
            const preparing = status.phase === 'ready_to_render';
            const refreshing = this.$el.dataset.refresh === '1' || status.phase === 'refreshing';
            const waiting = !refreshing && (status.phase === 'queued' || status.phase === 'not_loaded');
            const total = status.total || 0;
            if (stage) {
                stage.textContent = preparing
                    ? 'Preparing product display…'
                    : (refreshing
                        ? (status.stage || 'Refreshing collection products')
                        : (waiting ? 'Waiting to load collection products…' : (status.stage || 'Loading this collection')));
            }
            if (count) {
                count.textContent = !preparing && total > 0
                    ? ((status.done || 0) + ' of ' + total + ' · ' + (status.percent != null ? status.percent : Math.floor(((status.done || 0) / total) * 100)) + '%')
                    : '';
            }
            if (bar) {
                bar.hidden = preparing || total < 1;
                if (!bar.hidden) {
                    bar.max = total;
                    bar.value = status.done || 0;
                }
            }
            if (indeterminate) indeterminate.hidden = !preparing && total > 0;
        },
        finishLoaded(announce) {
            this.stopStatusTimer();
            this.statusMode = 'idle';
            if (announce && this.wasLoading) {
                const count = this.$el.dataset.productCount || '';
                this.loadNoticeText = count !== '' ? (count + ' products loaded successfully') : 'Collection loaded successfully.';
                this.loadNotice = true;
                clearTimeout(this.loadNoticeTimer);
                this.loadNoticeTimer = setTimeout(() => { this.loadNotice = false; }, 6000);
            }
            this.wasLoading = false;
        },
        async pollStatus() {
            const refreshing = this.$el.dataset.refresh === '1';
            if (this.$el.dataset.productsLoaded === '1' && !refreshing) {
                this.finishLoaded(this.wasLoading);
                return;
            }
            if (this.statusStartedAt && Date.now() - this.statusStartedAt > 15 * 60 * 1000) {
                this.statusMode = 'failed';
                this.stopStatusTimer();
                if (refreshing) {
                    await this.$wire.expireRefresh();
                    return;
                }
                const stale = this.$refs.statusBanner?.querySelector('[data-status-stale]');
                if (stale) stale.hidden = false;
                return;
            }
            const status = await this.$wire.statusSnapshot();
            if (!status) return;
            if (status.gid && this.$el.dataset.collection && status.gid !== this.$el.dataset.collection) return;
            if (status.phase === 'refresh_complete' || status.phase === 'refresh_failed' || status.phase === 'refresh_skipped') {
                const announceRefresh = this.wasLoading || refreshing;
                if (!this.renderingProducts) {
                    this.renderingProducts = true;
                    try {
                        await this.$wire.pollPush();
                    } finally {
                        this.renderingProducts = false;
                    }
                    this.$nextTick(() => this.bindProductDrag());
                }
                if (status.phase === 'refresh_complete') {
                    this.wasLoading = announceRefresh;
                    this.finishLoaded(true);
                } else {
                    this.stopStatusTimer();
                    this.statusMode = status.phase === 'refresh_failed' ? 'failed' : 'idle';
                    this.wasLoading = false;
                }
                return;
            }
            if ((status.phase === 'failed' || status.state === 'failed') && status.phase !== 'ready_to_render') {
                if (this.statusMode !== 'failed' && !this.renderingProducts) {
                    this.statusMode = 'failed';
                    this.renderingProducts = true;
                    try {
                        await this.$wire.pollPush();
                    } finally {
                        this.renderingProducts = false;
                    }
                    this.$nextTick(() => this.bindProductDrag());
                }
                if (this.$el.dataset.productsLoaded === '1') {
                    this.finishLoaded(true);
                }
                return;
            }
            this.statusMode = 'loading';
            this.applyProgress(status);
            if (status.changed && !this.renderingProducts) {
                this.renderingProducts = true;
                try {
                    await this.$wire.pollPush();
                } finally {
                    this.renderingProducts = false;
                }
                this.$nextTick(() => this.bindProductDrag());
            }
            if (this.$el.dataset.productsLoaded === '1') {
                this.finishLoaded(true);
            }
        },
        cardVisible(el) {
            const query = this.productQuery.trim().toLowerCase();
            if (query === '') return true;
            return (el.dataset.search || '').includes(query);
        },
        bindProductDrag() {
            this.$el.querySelectorAll('[data-order-type=products]').forEach((grid) => {
                const sample = grid.querySelector(':scope > [data-order-key]');
                const height = sample ? Math.ceil(sample.getBoundingClientRect().height) : 0;
                if (height > 0) grid.style.setProperty('--syv-card-size', height + 'px');
                grid.sortable?.option('disabled', true);
                grid.__syvDragAbort?.abort();
                const controller = new AbortController();
                grid.__syvDragAbort = controller;
                grid.addEventListener('dragstart', (event) => event.preventDefault(), { signal: controller.signal });
                grid.addEventListener('pointerdown', (event) => this.startProductDrag(event, grid), { signal: controller.signal });
            });
        },
        startProductDrag(event, grid) {
            if (this.productDrag || (event.button !== undefined && event.button !== 0) || this.productQuery.trim() !== '') return;
            const handle = event.target.closest('[x-sortable-handle]');
            if (!handle || handle.disabled || !grid.contains(handle) || grid.closest('fieldset')?.disabled) return;
            const card = handle.closest('[data-order-key]');
            const cards = Array.from(grid.querySelectorAll(':scope > [data-order-key]'));
            const from = cards.indexOf(card);
            if (!card || from < 0) return;
            event.preventDefault();
            event.stopPropagation();
            const slots = cards.map((item) => {
                const rect = item.getBoundingClientRect();
                return { x: rect.left + window.scrollX, y: rect.top + window.scrollY, w: rect.width, h: rect.height };
            });
            const rect = card.getBoundingClientRect();
            const spacer = document.createElement('div');
            spacer.className = 'syv-drag-spacer';
            spacer.style.height = rect.height + 'px';
            card.before(spacer);
            document.body.appendChild(card);
            card.classList.add('syv-dragging');
            card.style.position = 'fixed';
            card.style.left = rect.left + 'px';
            card.style.top = rect.top + 'px';
            card.style.width = rect.width + 'px';
            card.style.margin = '0';
            card.style.zIndex = '70';
            document.body.classList.add('syv-drag-lock');
            const move = (pointer) => this.queueProductDragMove(pointer);
            const up = (pointer) => this.finishProductDrag(pointer.pointerId, true);
            const cancel = (pointer) => this.finishProductDrag(pointer.pointerId, false);
            this.productDrag = { grid, card, cards, slots, from, index: from, spacer, pointerId: event.pointerId, originX: event.clientX, originY: event.clientY, frame: 0, lastScroll: 0, move, up, cancel };
            document.addEventListener('pointermove', move);
            document.addEventListener('pointerup', up);
            document.addEventListener('pointercancel', cancel);
        },
        queueProductDragMove(event) {
            const drag = this.productDrag;
            if (!drag || event.pointerId !== drag.pointerId) return;
            drag.latest = { clientX: event.clientX, clientY: event.clientY };
            if (drag.frame) return;
            drag.frame = requestAnimationFrame(() => {
                if (this.productDrag !== drag) return;
                drag.frame = 0;
                this.updateProductDrag(drag.latest);
            });
        },
        updateProductDrag(event) {
            const drag = this.productDrag;
            if (!drag || !event) return;
            drag.card.style.transform = 'translate(' + (event.clientX - drag.originX) + 'px,' + (event.clientY - drag.originY) + 'px)';
            const now = performance.now();
            const edge = 56;
            if (now - drag.lastScroll > 90 && event.clientY >= 0 && event.clientY <= window.innerHeight) {
                if (event.clientY < edge) {
                    window.scrollBy(0, -48);
                    drag.lastScroll = now;
                } else if (event.clientY > window.innerHeight - edge) {
                    window.scrollBy(0, 48);
                    drag.lastScroll = now;
                }
            }
            const x = event.clientX + window.scrollX;
            const y = event.clientY + window.scrollY;
            let index = drag.from;
            let best = Infinity;
            drag.slots.forEach((slot, slotIndex) => {
                const dx = x - (slot.x + slot.w / 2);
                const dy = y - (slot.y + slot.h / 2);
                const distance = dx * dx + dy * dy;
                if (distance < best) {
                    best = distance;
                    index = slotIndex;
                }
            });
            if (index === drag.index) return;
            drag.cards[drag.index]?.classList.remove('syv-drop-target');
            drag.index = index;
            if (index !== drag.from) drag.cards[index].classList.add('syv-drop-target');
        },
        finishProductDrag(pointerId, commit) {
            const drag = this.productDrag;
            if (!drag || pointerId !== drag.pointerId) return;
            this.productDrag = null;
            if (drag.frame) cancelAnimationFrame(drag.frame);
            document.removeEventListener('pointermove', drag.move);
            document.removeEventListener('pointerup', drag.up);
            document.removeEventListener('pointercancel', drag.cancel);
            document.body.classList.remove('syv-drag-lock');
            drag.cards.forEach((card) => card.classList.remove('syv-drop-target'));
            drag.card.classList.remove('syv-dragging');
            drag.card.style.position = '';
            drag.card.style.left = '';
            drag.card.style.top = '';
            drag.card.style.width = '';
            drag.card.style.margin = '';
            drag.card.style.zIndex = '';
            drag.card.style.transform = '';
            if (!commit || drag.index === drag.from) {
                if (drag.spacer.isConnected) drag.spacer.replaceWith(drag.card);
                else drag.grid.appendChild(drag.card);
                drag.spacer.remove();
                return;
            }
            const target = drag.cards[drag.index];
            if (drag.index > drag.from) target.after(drag.card);
            else target.before(drag.card);
            drag.spacer.remove();
            this.saveOrder(drag.grid, 'products', drag.grid.dataset.orderGid);
        },
        renumber(grid) {
            grid.querySelectorAll(':scope > [data-order-key]').forEach((el, index) => {
                const input = el.querySelector('[data-position-input]');
                if (input) input.value = String(index + 1);
            });
        },
        applySavedOrder(grid, gid, ids) {
            const current = grid?.isConnected ? grid : this.$el.querySelector('[data-order-gid=\'' + CSS.escape(gid) + '\']');
            if (!current || !Array.isArray(ids)) return;
            const nodes = new Map(Array.from(current.querySelectorAll(':scope > [data-order-key]')).map((el) => [el.dataset.orderKey, el]));
            const present = ids.filter((id) => nodes.has(id));
            const existing = Array.from(nodes.keys());
            if (existing.length === present.length && existing.every((id, index) => id === present[index])) {
                this.renumber(current);
                return;
            }
            const display = current.style.display;
            current.style.display = 'none';
            void current.offsetWidth;
            const fragment = document.createDocumentFragment();
            present.forEach((id) => fragment.appendChild(nodes.get(id)));
            current.appendChild(fragment);
            current.style.display = display;
            this.renumber(current);
        },
        queueProductOrder(grid, gid) {
            if (this.productQuery.trim() !== '') return;
            const epoch = ++this.orderEpoch;
            this.persistChain = this.persistChain.then(async () => {
                if (epoch !== this.orderEpoch) return;
                const order = Array.from(grid.querySelectorAll(':scope > [data-order-key]')).map((el) => el.dataset.orderKey).filter(Boolean);
                this.savingOrder = true;
                this.orderError = '';
                try {
                    const result = await this.$wire.reorderProducts(gid, order, epoch, this.orderSession);
                    if (epoch !== this.orderEpoch) return;
                    if (result?.ids) this.applySavedOrder(grid, gid, result.ids);
                    if (result?.applied) {
                        this.localPending = true;
                        this.$el.dataset.pending = 'true';
                    } else if ((result?.ids || []).join('|') !== order.join('|')) {
                        this.orderError = 'The product order was not saved. The list matches the saved draft.';
                    }
                } catch (error) {
                    if (epoch !== this.orderEpoch) return;
                    const ids = await this.$wire.savedProductOrder(gid);
                    if (epoch === this.orderEpoch) this.applySavedOrder(grid, gid, ids);
                    this.orderError = 'The product order was not saved. The list matches the saved draft.';
                } finally {
                    if (epoch === this.orderEpoch) this.savingOrder = false;
                }
            });
        },
        commitPosition(input) {
            const card = input.closest('[data-order-key]');
            const grid = input.closest('[data-order-grid]');
            if (!card || !grid || grid.closest('fieldset')?.disabled) return;
            if (this.productQuery.trim() !== '') {
                this.orderError = 'Clear the product search before changing positions.';
                this.renumber(grid);
                return;
            }
            const cards = Array.from(grid.querySelectorAll(':scope > [data-order-key]'));
            const total = cards.length;
            const position = Number.parseInt(input.value, 10);
            if (!Number.isInteger(position) || position < 1 || position > total) {
                this.orderError = 'Enter a position from 1 to ' + total + '.';
                this.renumber(grid);
                return;
            }
            this.orderError = '';
            const from = cards.indexOf(card);
            const to = position - 1;
            if (from === to) return;
            if (from < to) cards[to].after(card);
            else cards[to].before(card);
            this.renumber(grid);
            this.queueProductOrder(grid, grid.dataset.orderGid);
        },
        saveOrder(grid, type, gid = null, ids = null) {
            if (grid.closest('fieldset')?.disabled) return;
            if (type === 'cards') {
                if (this.savingOrder) return;
                const order = ids ?? Array.from(grid.children).map(el => el.dataset.orderKey).filter(Boolean);
                this.persistChain = this.persistChain.then(async () => {
                    this.savingOrder = true;
                    try {
                        await this.$wire.reorderCards(order);
                        this.localPending = true;
                        this.$el.dataset.pending = 'true';
                    } finally {
                        this.savingOrder = false;
                    }
                });
                return;
            }
            if (this.productQuery.trim() !== '') return;
            this.renumber(grid);
            this.queueProductOrder(grid, gid);
        },
        move(element, type, key, direction, gid = null) {
            const grid = element.closest('[data-order-grid]');
            const ids = Array.from(grid.children).map(el => el.dataset.orderKey).filter(Boolean);
            const index = ids.indexOf(key), next = index + direction;
            if (next < 0 || next >= ids.length) return;
            [ids[index], ids[next]] = [ids[next], ids[index]];
            this.saveOrder(grid, type, gid, ids);
        }
            };
        };
    </script>
    <div x-data="syvEditor()"
        data-pending="{{ $draft?->pending ? 'true' : 'false' }}"
        data-saved-pending="{{ $draft?->pending ? '1' : '0' }}"
        data-poll="{{ !empty($statusPoll) ? '1' : '0' }}"
        data-products-loaded="{{ !empty($productsLoaded) ? '1' : '0' }}"
        data-refresh="{{ !empty($refreshingCollection) ? '1' : '0' }}"
        data-product-count="{{ $productsLoaded ? $productTotal : '' }}"
        data-collection="{{ $draft?->collection_gid ?? ($selectedCollection ?? '') }}"
        data-hydration="{{ $hydrationState ?? '' }}"
        x-init="initStatus()"
        x-on:vibe-form-saved.window="formDirty = false; localPending = false"
        x-on:vibe-draft-saved.window="formDirty = false; localPending = true; $el.dataset.pending = 'true'"
        x-on:beforeunload.window="if (pending()) { $event.preventDefault(); $event.returnValue = ''; }"
        x-on:click.window.capture="if (pending() && $event.target.closest('a[href]') && !$event.target.closest('a[href]').getAttribute('href').startsWith('#') && !confirm('You have changes that have not been pushed to Shopify. Leave anyway?')) { $event.preventDefault(); $event.stopImmediatePropagation(); }"
        class="syv-workflow space-y-6"
        x-on:vibe-editor-opened.window="formDirty = false; statusStartedAt = Date.now(); $nextTick(() => { syncHydration(); $el.scrollIntoView({ behavior: 'smooth', block: 'start' }) })"
        x-on:vibe-refresh-started.window="statusStartedAt = Date.now(); $nextTick(() => syncHydration())"
        x-on:vibe-products-ready.window="$nextTick(() => { bindProductDrag(); if ($el.dataset.productsLoaded === '1') finishLoaded(true); })"
        x-on:livewire:navigating.window="stopStatusTimer()">
        @if ($loadError && ! ($addingCard || $addingParent || $uploadingMappings || $confirmingAdd))
            <div role="alert" class="rounded-xl border border-danger-300 bg-danger-50 p-4 text-danger-800 dark:bg-danger-950 dark:text-danger-200">{{ $loadError }}</div>
        @endif

        @if (!$draft && ! $hydratingCollection)
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
                        @php($operation = $operationDrafts->get($parent['gid']))
                        @if ($operation && $operation->status === 'creating')
                            <x-filament::badge color="info">Creating Vibe</x-filament::badge>
                        @elseif ($operation && $operation->status === 'deleting')
                            <x-filament::badge color="info">Deleting Vibe</x-filament::badge>
                        @elseif ($operation && $operation->status === 'pushing')
                            <x-filament::badge color="info">Pushing Changes</x-filament::badge>
                        @elseif ($operation && $operation->status === 'failed' && ! $operation->pending)
                            <x-filament::badge color="danger">Operation Failed</x-filament::badge>
                        @elseif ($pendingDrafts->has($parent['gid']))
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
        @elseif ($hydratingCollection && ! $draft)
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-2xl font-bold">{{ $hydratingParent['title'] ?? 'Collection' }} - Shop Your Vibe</h2>
                    <p class="text-sm text-gray-500">Opening from the local catalogue.</p>
                </div>
                <x-filament::button color="gray" wire:click="back">Back to collections</x-filament::button>
            </div>
            @if (($syvProgress['state'] ?? null) === 'failed')
                <div id="syv-status-banner" role="status" class="syv-load syv-load-failed">
                    <strong>Could not load collection products</strong>
                    <p>{{ $syvProgress['message'] ?? \App\Services\ShopYourVibeWorkflow::HYDRATION_FAILED_MESSAGE }}</p>
                    <div class="mt-3"><x-filament::button size="sm" wire:click="retryHydration" wire:loading.attr="disabled">Retry</x-filament::button></div>
                </div>
            @else
                @include('filament.pages.partials.shop-your-vibe-loading-banner', [
                    'title' => 'Loading collection products',
                    'detail' => \App\Services\ShopYourVibeWorkflow::LOADING_PRODUCTS_MESSAGE,
                    'stage' => $progressStage,
                    'count' => $progressCount,
                    'done' => $progressDone,
                    'total' => $progressTotal,
                ])
            @endif
            <div class="syv-card-grid syv-product-grid" aria-hidden="true">
                @foreach (range(1, 8) as $skeleton)
                    <div class="syv-product-card syv-product-skeleton rounded-lg border border-gray-200 p-3"></div>
                @endforeach
            </div>
        @else
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-2xl font-bold">{{ $draft->desired['parent']['title'] }} - Shop Your Vibe</h2>
                    <p class="text-sm text-gray-500">
                        @if ($productsLoaded)
                            Last confirmed with Shopify: {{ $draft->refreshed_at?->format('d M Y H:i') ?? 'Not yet refreshed' }}
                        @else
                            Products have not been loaded from Shopify yet.
                        @endif
                    </p>
                </div>
                <x-filament::button color="gray" wire:click="back" wire:confirm="You may have changes that have not been pushed to Shopify. Leave this editor? Saved drafts will be kept.">Back to collections</x-filament::button>
            </div>
            <div class="syv-tabs flex w-fit rounded-xl border border-gray-200 p-1 dark:border-gray-700" role="tablist" aria-label="Collection content">
                <button type="button" wire:key="syv-tab-products" wire:click="setActiveTab('products')" role="tab" aria-selected="{{ $activeTab === 'products' ? 'true' : 'false' }}"
                    class="rounded-lg px-4 py-2 text-sm font-medium {{ $activeTab === 'products' ? 'bg-primary-600 text-white' : 'text-gray-600 dark:text-gray-300' }}">
                    Products ({{ $productCountLabel }})
                </button>
                <button type="button" wire:key="syv-tab-vibes" wire:click="setActiveTab('vibes')" role="tab" aria-selected="{{ $activeTab === 'vibes' ? 'true' : 'false' }}"
                    class="rounded-lg px-4 py-2 text-sm font-medium {{ $activeTab === 'vibes' ? 'bg-primary-600 text-white' : 'text-gray-600 dark:text-gray-300' }}">
                    Shop Your Vibes ({{ $vibeCountLabel }})
                </button>
            </div>
            @if (filled($draft->desired['unavailable_collections'] ?? null))
                <div role="status" class="syv-load syv-load-failed">
                    <strong>A linked collection could not be loaded</strong>
                    @foreach ($draft->desired['unavailable_collections'] as $missing)
                        <p class="syv-load-copy">{{ $missing['message'] }}</p>
                    @endforeach
                    <p class="syv-load-copy">The other products are shown below.</p>
                    <div class="mt-3">
                        <x-filament::button size="sm" color="gray" wire:click="dismissUnavailableNotice" wire:target="dismissUnavailableNotice" wire:loading.attr="disabled">Dismiss</x-filament::button>
                    </div>
                </div>
            @endif
            <div x-show="formDirty" x-cloak role="status" class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-warning-900 dark:bg-warning-950 dark:text-warning-100">
                Pending field edits - not pushed to Shopify. Save the card to keep these edits in your draft.
            </div>
            @php($operationBusy = in_array($draft->status, ['creating', 'deleting', 'pushing'], true))
            @php($operationFailed = $draft->status === 'failed' && ! $draft->pending && ! empty($draft->operation))
            @php($hydrationFailed = ! $productsLoaded && ($syvProgress['state'] ?? null) === 'failed')
            @php($hydrationLoading = ! $productsLoaded && ! $hydrationFailed && ! $draft->pending && ! $operationBusy)
            @php($refreshLoading = $refreshingCollection && $productsLoaded && ! $draft->pending && ! $operationBusy && ! in_array($hydrationState, ['success', 'failed', 'skipped'], true))
            @php($refreshFailed = filled($refreshError))
            @if ($refreshFailed)
                <div id="syv-status-banner" role="status" class="syv-load syv-load-failed">
                    <strong>Could not refresh collection products</strong>
                    <p class="syv-load-copy">{{ $refreshError }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-filament::button size="sm" color="gray" wire:click="dismissRefreshNotice" wire:target="dismissRefreshNotice" wire:loading.attr="disabled">Dismiss</x-filament::button>
                        <x-filament::button size="sm" wire:click="refreshDraft" wire:loading.attr="disabled">Retry</x-filament::button>
                    </div>
                </div>
            @endif
            @if (! $refreshFailed && $hydrationFailed)
                <div id="syv-status-banner" x-ref="statusBanner" role="status" class="rounded-xl border border-danger-300 p-4">
                    <strong>Could not load collection products</strong>
                    <p>{{ $syvProgress['message'] ?? \App\Services\ShopYourVibeWorkflow::HYDRATION_FAILED_MESSAGE }}</p>
                    <div class="mt-3"><x-filament::button size="sm" wire:click="retryHydration" wire:loading.attr="disabled">Retry</x-filament::button></div>
                </div>
            @elseif ($hydrationLoading || $refreshLoading)
                @include('filament.pages.partials.shop-your-vibe-loading-banner', [
                    'title' => $refreshLoading ? 'Refreshing collection products' : 'Loading collection products',
                    'detail' => \App\Services\ShopYourVibeWorkflow::LOADING_PRODUCTS_MESSAGE,
                    'stage' => $progressStage,
                    'count' => $progressCount,
                    'done' => $progressDone,
                    'total' => $progressTotal,
                ])
            @elseif ($operationBusy || $operationFailed || $draft->pending)
                <div id="syv-status-banner" role="status" class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    @if ($draft->status === 'creating')
                        Creating Vibe... Shopify is creating the collection, image, and preview card. Editing is paused until this finishes.
                    @elseif ($draft->status === 'deleting')
                        Deleting Vibe... Shopify is applying the removal. Editing is paused until this finishes.
                    @elseif ($draft->status === 'pushing')
                        Pushing to Shopify... You can return later; your draft is saved. Editing is paused until this push finishes.
                    @elseif ($operationFailed)
                        <strong>Operation Failed</strong>
                        <p class="mt-2">{{ $draft->last_error ?: 'Shopify did not confirm this Vibe operation.' }}</p>
                        <p class="mt-2">Some changes could not be synchronized. Retry is available.</p>
                        @if ($draft->progress)
                            <ul class="mt-2 list-inside list-disc">@foreach ($draft->progress as $message)<li>{{ $message }}</li>@endforeach</ul>
                        @endif
                        <div class="mt-3 flex flex-wrap gap-2">
                            <x-filament::button size="sm" wire:click="retryImmediateOperation" wire:loading.attr="disabled">Retry operation</x-filament::button>
                            <x-filament::button size="sm" color="gray" wire:click="refreshDraft(true)" wire:confirm="Refresh from Shopify and clear this failed operation? Changes already saved in Shopify will stay there.">Refresh and review</x-filament::button>
                        </div>
                    @else
                        <strong>{{ $draft->status === 'failed' ? 'Push failed - some changes are still pending' : 'Pending changes - not pushed to Shopify' }}</strong>
                        <p>Your saved draft contains changes that have not been confirmed by Shopify.</p>
                        @if (! $productsLoaded)
                            <p class="mt-2">This collection's products have not been loaded. Pending edits were kept, so Shopify was not asked to replace them.</p>
                        @endif
                        @if ($draft->last_error)<p class="mt-2">{{ $draft->last_error }}</p>@endif
                        @if ($draft->progress)
                            <ul class="mt-2 list-inside list-disc">@foreach ($draft->progress as $message)<li>{{ $message }}</li>@endforeach</ul>
                        @endif
                    @endif
                </div>
            @endif
            <div x-show="loadNotice" x-cloak role="status" class="syv-load syv-load-success">
                <div class="syv-load-heading">
                    <span class="syv-load-check" aria-hidden="true">✓</span>
                    <strong x-text="loadNoticeText">Collection loaded successfully.</strong>
                </div>
                <p class="syv-load-copy">The product grid appears automatically. No page refresh is required.</p>
            </div>
            <p x-show="savingOrder" x-cloak>Saving your product order...</p>
            <p x-show="localPending && !formDirty && $root.dataset.savedPending !== '1'" x-cloak><strong>Pending changes - not pushed to Shopify</strong></p>
            <p x-show="orderError" x-text="orderError" x-cloak class="text-danger-600"></p>
            <div class="space-y-6">
                <div class="flex flex-wrap gap-3">
                    @if ($draft->status === 'pushing')
                        <x-filament::button wire:click="retryPush" wire:loading.attr="disabled" wire:target="retryPush">Retry / Check Push</x-filament::button>
                    @else
                        <button type="button" wire:click="reviewPush" x-bind:disabled="!canPush()" class="fi-btn relative inline-grid grid-flow-col items-center justify-center gap-1.5 rounded-lg bg-custom-600 px-3 py-2 text-sm font-semibold text-white shadow-sm outline-none transition duration-75 hover:bg-custom-500 focus-visible:ring-2 focus-visible:ring-custom-500/50 disabled:pointer-events-none disabled:opacity-70" style="--c-400:var(--primary-400);--c-500:var(--primary-500);--c-600:var(--primary-600);">Push Changes to Shopify</button>
                    @endif
                    <x-filament::button color="gray" wire:click="refreshDraft(true)" wire:confirm="Discard your pending draft and reload the confirmed state from Shopify? This will not undo changes already pushed." wire:loading.attr="disabled">Discard Changes</x-filament::button>
                    <x-filament::button color="gray" wire:click="refreshDraft(true)" wire:confirm="Refresh from Shopify? Any pending draft or unsaved field edits will be discarded." wire:loading.attr="disabled">Refresh from Shopify</x-filament::button>
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
                @if ($activeTab === 'products')
                <div class="space-y-6" wire:key="syv-products-panel">
                    <div class="flex items-center justify-between gap-3">
                        <div><h3 class="text-lg font-semibold">Products</h3><p class="text-sm text-gray-500">Manage one or several Shop Your Vibe assignments for each product.</p></div>
                        <input type="search" x-model.debounce.150ms="productQuery"
                            placeholder="Search product name or SKU" aria-label="Search products by name or SKU"
                            class="block w-full max-w-sm rounded-lg border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900" />
                    </div>
                    @php($parentCollection = $draft->desired['collections'][$draft->collection_gid] ?? null)
                    @php($parentSort = $parentCollection['sort'] ?? null)
                    @php($parentManualSupported = $parentCollection && (($parentCollection['manual_supported'] ?? true) !== false || $parentSort !== 'UNSUPPORTED'))
                    @php($parentCanSort = $draft->status !== 'pushing' && $parentManualSupported)
                    <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm dark:border-gray-700 dark:bg-gray-900">
                        @if ($parentCanSort)
                            <p class="font-semibold text-gray-900 dark:text-white">Product order — {{ $parentSort === 'MANUAL' ? 'Manual sorting enabled' : 'Manual sorting will be requested' }}</p>
                            <p class="mt-1 text-xs text-gray-500">Drag products or enter a position number to reorder. Changes are saved to the draft.</p>
                            @if ($parentSort !== 'MANUAL')
                                <p class="mt-1 text-xs text-gray-500">Pushing changes will ask Shopify to use manual sorting for this collection.</p>
                            @endif
                        @elseif (! $parentCollection && $draft->pending)
                            <p class="font-semibold text-gray-900 dark:text-white">Product order</p>
                            <p class="mt-1 text-xs text-gray-500">Pending edits were kept, so this collection was not reloaded from Shopify.</p>
                        @elseif (! $parentCollection && ($syvProgress['state'] ?? null) === 'failed')
                            <p class="font-semibold text-gray-900 dark:text-white">Product order</p>
                            <p class="mt-1 text-xs text-gray-500">Retry loading to get this collection's sort mode.</p>
                        @elseif (! $parentCollection)
                            <p class="font-semibold text-gray-900 dark:text-white">Product order</p>
                            <p class="mt-1 text-xs text-gray-500">Sorting will be available when the products finish loading.</p>
                        @else
                            <p class="font-semibold text-gray-900 dark:text-white">Product order — manual sorting is not available</p>
                            <p class="mt-1 text-xs text-gray-500">Shopify reports this collection as not manually sortable, so products cannot be reordered here.</p>
                        @endif
                    </div>
                    @php($confirmedParentProductsVersion = md5((string) ($draft->desired['collections'][$draft->collection_gid]['retrieval_started_at'] ?? $draft->refreshed_at)))
                    @if ($productsLoaded && $parentCanSort)
                    <div data-order-grid data-order-type="products" data-order-gid="{{ $draft->collection_gid }}" class="syv-card-grid syv-product-grid"
                        wire:key="parent-products-{{ $draft->id }}-{{ $productMembership }}-{{ $confirmedParentProductsVersion }}"
                        wire:ignore
                        x-sortable data-sortable-animation-duration="0"
                        x-on:end.stop="if ($event.oldIndex !== $event.newIndex && productQuery.trim() === '') saveOrder($el, 'products', {{ \Illuminate\Support\Js::from($draft->collection_gid) }})">
                    @elseif ($productsLoaded)
                    <div data-order-grid data-order-type="products" data-order-gid="{{ $draft->collection_gid }}" class="syv-card-grid syv-product-grid"
                        wire:key="parent-products-{{ $draft->id }}-fixed-{{ $productMembership }}-{{ $confirmedParentProductsVersion }}"
                        wire:ignore>
                    @else
                    <div data-order-grid data-order-type="products" data-order-gid="{{ $draft->collection_gid }}" class="syv-card-grid syv-product-grid"
                        wire:key="parent-products-{{ $draft->id }}-loading">
                    @endif
                        @if (count($filteredParentProducts) > 0)
                        @foreach ($filteredParentProducts as $product)
                                        <article wire:key="parent-product-{{ md5($product['id']) }}" data-order-key="{{ $product['id'] }}" data-search="{{ mb_strtolower($product['title'].' '.($product['sku'] ?? '')) }}" x-show="cardVisible($el)" x-sortable-item="{{ md5($product['id']) }}" class="syv-product-card rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                @include('filament.pages.partials.shop-your-vibe-card-tools', ['canSort' => $parentCanSort, 'title' => $product['title'], 'position' => $loop->iteration, 'max' => $loop->count])
                                <div class="syv-product-image-wrap">
                                    @if ($product['image'])<img src="{{ $product['image'] }}" alt="" draggable="false" class="syv-product-image" loading="lazy">@endif
                                    @include('filament.pages.partials.shop-your-vibe-product-badges', ['product' => $product])
                                </div>
                                <h4 class="mt-2 font-medium">{{ $product['title'] }}</h4>
                                <p class="text-xs text-gray-500">SKU: {{ $product['sku'] ?: 'No SKU' }}</p>
                                @if (!empty($product['is_prelaunch_draft']))
                                    <div class="my-3 rounded-md border border-warning-200 bg-warning-50 p-2 text-xs text-warning-900 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-100">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-filament::badge color="warning">{{ $product['status'] ?: 'DRAFT' }}</x-filament::badge>
                                            <span>Placement: {{ $product['placement_complete_count'] ?? 0 }}/{{ $product['placement_total_count'] ?? 0 }} complete</span>
                                        </div>
                                        @if (!empty($product['collection_placements']))
                                            <div class="mt-2 flex flex-wrap gap-1">
                                                @foreach ($product['collection_placements'] as $placement)
                                                    <span>{{ $placement['complete'] ? 'Complete' : 'Needs positioning' }}: {{ $placement['name'] }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endif
                                <div class="syv-finish-row">
                                    <span class="syv-finish-label">Material Colour Finish</span>
                                    @if (filled($product['bead_colour_finish'] ?? null))
                                        <span class="syv-finish-badge">{{ $product['bead_colour_finish'] }}</span>
                                    @else
                                        <span class="syv-finish-badge syv-finish-badge-empty">None</span>
                                    @endif
                                </div>
                                <div class="my-3 syv-vibe-badge-list">
                                    <p class="text-xs font-semibold uppercase text-gray-500">Shop Your Vibes</p>
                                    <div class="syv-vibe-badges">
                                    @forelse (($product['vibe_assignments'] ?? []) as $assignment)
                                        <x-filament::badge color="info">{{ $assignment }}</x-filament::badge>
                                    @empty
                                        <span class="syv-empty-state">None</span>
                                    @endforelse
                                    </div>
                                </div>
                                <div class="my-3 syv-vibe-badge-list">
                                    <p class="text-xs font-semibold uppercase text-gray-500">Siblings</p>
                                    <div class="syv-vibe-badges">
                                    @forelse (($product['siblings'] ?? []) as $sibling)
                                        <span class="syv-sibling-badge">{{ strtoupper($sibling['label']) }}</span>
                                    @empty
                                        @if ($siblingsResolved)
                                            <span class="syv-empty-state" data-sibling-state="empty">None</span>
                                        @else
                                            <span class="syv-empty-state" data-sibling-state="unknown">Not loaded</span>
                                        @endif
                                    @endforelse
                                    </div>
                                </div>
                                <div class="my-3 syv-vibe-badge-list syv-complementary-section">
                                    @php($complementaryCount = (int) ($product['complementary_count'] ?? 0))
                                    <span class="syv-complementary-status {{ $complementaryCount > 0 ? 'syv-complementary-status-active' : 'syv-complementary-status-empty' }}">
                                        {{ $complementaryCount > 0 ? $complementaryCount : 'None' }} Complementary
                                    </span>
                                </div>
                                <div class="syv-vibe-actions">
                                    <button type="button" class="syv-card-action syv-vibe-button" wire:click.stop="openProductAssignments({{ \Illuminate\Support\Js::from($product['id']) }})">Vibes</button>
                                    <button type="button" class="syv-card-action syv-sibling-button" wire:click="openProductSiblings({{ \Illuminate\Support\Js::from($product['id']) }})">Siblings</button>
                                    <button type="button" class="syv-card-action syv-tag-button" wire:click="openProductTags({{ \Illuminate\Support\Js::from($product['id']) }})">Tags</button>
                                    <button type="button" class="syv-card-action syv-complementary-button" wire:click="openProductComplementary({{ \Illuminate\Support\Js::from($product['id']) }})">Complementary</button>
                                </div>
                            </article>
                        @endforeach
                        @elseif ($productsLoaded)
                            <p class="text-gray-500">No products belong to this Shopify collection.</p>
                        @endif
                    </div>
                </div>
                @endif
                @if ($activeTab === 'vibes')
                <div class="space-y-6" wire:key="syv-vibes-panel">
                <div class="flex items-center justify-between gap-3">
                    <div><h3 class="text-lg font-semibold">Vibes</h3><p class="text-sm text-gray-500">Drag the grip to reorder. Reordering saves a pending draft.</p></div>
                    <div class="flex flex-wrap gap-2">
                        <x-filament::button color="gray" wire:click="openMappingUpload" wire:loading.attr="disabled" wire:target="openMappingUpload">Bulk Upload Mappings</x-filament::button>
                        <button type="button" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 disabled:opacity-60" wire:click.stop="openCollectionPicker(true)" wire:loading.attr="disabled" wire:target="openCollectionPicker">Add Vibe</button>
                    </div>
                </div>
                @php($hasUnavailableVibes = collect($draft->desired['cards'])->contains(fn ($vibe) => in_array($vibe['unavailable_collection_gid'] ?? $vibe['collection_gid'] ?? null, $unavailableCollectionIds, true)))
                <div data-order-grid class="syv-card-grid"
                    wire:key="vibe-grid-{{ $draft->id }}-{{ in_array($draft->status, ['pushing', 'creating', 'deleting'], true) ? 'locked' : 'editable' }}"
                    @if (! $hasUnavailableVibes && ! in_array($draft->status, ['pushing', 'creating', 'deleting'], true))
                        x-sortable data-sortable-animation-duration="200"
                        x-on:end.stop="if ($event.oldIndex !== $event.newIndex) saveOrder($el, 'cards')"
                    @endif>
                    @foreach ($draft->desired['cards'] as $vibe)
                        @php($vibeUnavailable = in_array($vibe['unavailable_collection_gid'] ?? $vibe['collection_gid'] ?? null, $unavailableCollectionIds, true))
                        <article wire:key="vibe-{{ md5($vibe['key']) }}" data-order-key="{{ $vibe['key'] }}" x-sortable-item="{{ md5($vibe['key']) }}"
                            data-collection-unavailable="{{ $vibeUnavailable ? 'true' : 'false' }}"
                            class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900 {{ $activeCard === $vibe['key'] ? 'syv-vibe-card-active' : '' }}">
                            @if ($activeCard === $vibe['key'])
                                <span class="syv-vibe-active-pill">Editing</span>
                            @endif
                            @if ($vibeUnavailable)
                                <p role="status" class="mb-3 text-sm font-semibold text-danger-600">Collection no longer exists in Shopify</p>
                                <p class="mb-3 text-xs text-gray-500">Editing is disabled. Historical data and existing draft changes are preserved.</p>
                            @endif
                            <button type="button" x-sortable-handle x-bind:disabled="savingOrder || {{ $hasUnavailableVibes ? 'true' : 'false' }}" class="syv-drag-handle" aria-label="Drag {{ $vibe['name'] }}">Drag</button>
                            <button type="button" @disabled($vibeUnavailable) wire:click="editCard({{ \Illuminate\Support\Js::from($vibe['key']) }})" x-on:click="if (formDirty && !confirm('Discard unsaved card fields and open this vibe?')) $event.stopImmediatePropagation(); else formDirty = false" class="block w-full text-left">
                                @if ($vibe['image_url'])<img src="{{ $vibe['image_url'] }}" alt="" draggable="false" class="syv-vibe-image" loading="lazy">@else<div class="syv-vibe-image syv-image-placeholder">Choose an image</div>@endif
                                <h4 class="my-3 font-semibold">{{ $vibe['name'] }}</h4>
                                @php($vibeMapping = collect($vibeMappings)->firstWhere('shopify_collection_id', $vibe['collection_gid']))
                                @php($vibeCollection = $draft->desired['collections'][$vibe['collection_gid'] ?? ''] ?? null)
                                @php($fallbackHandle = $vibeCollection['handle'] ?? basename((string) parse_url($vibe['link'] ?? '', PHP_URL_PATH)))
                                @php($fallbackTag = $vibeCollection['detected_membership_tag'] ?? null)
                                @php($fallbackProductCount = $vibeCollection ? (($vibeCollection['product_count'] ?? null) ?? count($vibeCollection['products'] ?? [])) : null)
                                @if ($vibeMapping || $fallbackHandle || $fallbackTag || $fallbackProductCount !== null)
                                    <dl class="space-y-1 text-xs text-gray-500">
                                        <div><dt class="inline font-semibold">Handle:</dt> <dd class="inline">{{ $vibeMapping['collection_handle'] ?? $fallbackHandle }}</dd></div>
                                        <div><dt class="inline font-semibold">Membership Tag:</dt> <dd class="inline">{{ ($vibeMapping['membership_tag'] ?? null) ?: ($fallbackTag ?: 'Requires configuration') }}</dd></div>
                                        @if (filled($vibeMapping['design_value'] ?? null))<div><dt class="inline font-semibold">Design:</dt> <dd class="inline">{{ $vibeMapping['design_value'] }}</dd></div>@endif
                                        @if (filled($vibeMapping['colour_style_value'] ?? null))<div><dt class="inline font-semibold">Colour Style:</dt> <dd class="inline">{{ $vibeMapping['colour_style_value'] }}</dd></div>@endif
                                        <div><dd>{{ ($vibeMapping['product_count'] ?? $fallbackProductCount ?? 0) }} Products</dd></div>
                                    </dl>
                                @endif
                            </button>
                            <div class="flex flex-wrap gap-2">
                                <x-filament::button size="xs" color="gray" :disabled="$hasUnavailableVibes" x-on:click="move($el, 'cards', {{ \Illuminate\Support\Js::from($vibe['key']) }}, -1)" aria-label="Move vibe earlier">Up</x-filament::button>
                                <x-filament::button size="xs" color="gray" :disabled="$hasUnavailableVibes" x-on:click="move($el, 'cards', {{ \Illuminate\Support\Js::from($vibe['key']) }}, 1)" aria-label="Move vibe later">Down</x-filament::button>
                                <x-filament::button size="xs" color="gray" :disabled="$vibeUnavailable" wire:click="editCard({{ \Illuminate\Support\Js::from($vibe['key']) }})" x-on:click="if (formDirty &amp;&amp; !confirm('Discard unsaved card fields and open this vibe?')) $event.stopImmediatePropagation(); else formDirty = false">Edit</x-filament::button>
                                <x-filament::button size="xs" color="danger" :disabled="$vibeUnavailable" wire:click="mountAction('removeVibe', {{ \Illuminate\Support\Js::from(['key' => $vibe['key']]) }})">Remove</x-filament::button>
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
                                @if ($cardImageUpload)
                                    <img src="{{ $cardImageUpload->temporaryUrl() }}" alt="Uploaded card preview" class="syv-edit-image">
                                @elseif ($cardForm['image_url'] ?? null)
                                    <img src="{{ $cardForm['image_url'] }}" alt="Card preview" class="syv-edit-image">
                                @else
                                    <div class="syv-edit-image syv-image-placeholder">Choose an image</div>
                                @endif
                                <div class="flex flex-wrap items-center gap-3">
                                    <label class="inline-flex cursor-pointer items-center rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">
                                        <input type="file" class="sr-only" wire:model="cardImageUpload" accept="image/jpeg,image/png,image/webp">
                                        Upload image
                                    </label>
                                    <x-filament::button color="gray" wire:click="findImages" wire:loading.attr="disabled">Choose image from Shopify</x-filament::button>
                                    <x-filament::button color="gray" wire:click="uploadCardImage" wire:loading.attr="disabled" wire:target="cardImageUpload,uploadCardImage" :disabled="$cardImageUpload === null">Save and send to Shopify</x-filament::button>
                                </div>
                                <div wire:loading wire:target="cardImageUpload" class="mt-2 text-sm text-gray-500">Uploading image...</div>
                                @error('cardImageUpload')<p class="mt-2 text-sm text-danger-600">{{ $message }}</p>@enderror
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
                                @php($vibeProductMembership = md5(implode('|', array_column($collection['products'], 'id'))))
                                @php($confirmedVibeProductsVersion = md5((string) ($collection['retrieval_started_at'] ?? $draft->refreshed_at)))
                                @if ($canSort)
                                <div data-order-grid data-order-type="products" data-order-gid="{{ $collection['gid'] }}" class="syv-card-grid syv-product-grid"
                                    wire:key="vibe-products-{{ md5($collection['gid']) }}-sortable-{{ $vibeProductMembership }}-{{ $confirmedVibeProductsVersion }}"
                                        wire:ignore
                                        x-sortable data-sortable-animation-duration="0"
                                        x-on:end.stop="if ($event.oldIndex !== $event.newIndex && productQuery.trim() === '') saveOrder($el, 'products', {{ \Illuminate\Support\Js::from($collection['gid']) }})">
                                @else
                                <div data-order-grid data-order-type="products" data-order-gid="{{ $collection['gid'] }}" class="syv-card-grid syv-product-grid"
                                    wire:key="vibe-products-{{ md5($collection['gid']) }}-fixed-{{ $vibeProductMembership }}-{{ $confirmedVibeProductsVersion }}"
                                    wire:ignore>
                                @endif
                                    @foreach ($collection['products'] as $product)
                                        @php($displayProduct = $product)
                                        @php($inParentCollection = isset($parentProductIds[$product['id']]))
                                        <article wire:key="vibe-product-{{ md5($collection['gid'].'|'.$product['id']) }}" data-order-key="{{ $product['id'] }}" data-search="{{ mb_strtolower($displayProduct['title'].' '.($displayProduct['sku'] ?? '')) }}" x-show="cardVisible($el)" x-sortable-item="{{ md5($product['id']) }}" class="syv-product-card rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                            @include('filament.pages.partials.shop-your-vibe-card-tools', ['canSort' => $canSort, 'title' => $displayProduct['title'], 'position' => $loop->iteration, 'max' => $loop->count])
                                            <div class="syv-product-image-wrap">
                                                @if ($displayProduct['image'])<img src="{{ $displayProduct['image'] }}" alt="" draggable="false" class="syv-product-image" loading="lazy">@endif
                                                @include('filament.pages.partials.shop-your-vibe-product-badges', ['product' => $displayProduct])
                                            </div>
                                            <h4 class="mt-2 font-medium">{{ $displayProduct['title'] }}</h4>
                                            <p class="text-xs text-gray-500">SKU: {{ $displayProduct['sku'] ?: 'No SKU' }}</p>
                                            @if (!empty($displayProduct['is_prelaunch_draft']))
                                                <div class="my-3 rounded-md border border-warning-200 bg-warning-50 p-2 text-xs text-warning-900 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-100">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <x-filament::badge color="warning">{{ $displayProduct['status'] ?: 'DRAFT' }}</x-filament::badge>
                                                        <span>Placement: {{ $displayProduct['placement_complete_count'] ?? 0 }}/{{ $displayProduct['placement_total_count'] ?? 0 }} complete</span>
                                                    </div>
                                                    @if (!empty($displayProduct['collection_placements']))
                                                        <div class="mt-2 flex flex-wrap gap-1">
                                                            @foreach ($displayProduct['collection_placements'] as $placement)
                                                                <span>{{ $placement['complete'] ? 'Complete' : 'Needs positioning' }}: {{ $placement['name'] }}</span>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </div>
                                            @endif
                                            <div class="syv-finish-row">
                                                <span class="syv-finish-label">Material Colour Finish</span>
                                                @if (filled($displayProduct['bead_colour_finish'] ?? null))
                                                    <span class="syv-finish-badge">{{ $displayProduct['bead_colour_finish'] }}</span>
                                                @else
                                                    <span class="syv-finish-badge syv-finish-badge-empty">None</span>
                                                @endif
                                            </div>
                                            <div class="my-3 syv-vibe-badge-list">
                                                <p class="text-xs font-semibold uppercase text-gray-500">Shop Your Vibes</p>
                                                <div class="syv-vibe-badges">
                                                @forelse (($displayProduct['vibe_assignments'] ?? []) as $assignment)
                                                    <x-filament::badge color="info">{{ $assignment }}</x-filament::badge>
                                                @empty
                                                    <span class="syv-empty-state">None</span>
                                                @endforelse
                                                </div>
                                            </div>
                                            <div class="my-3 syv-vibe-badge-list">
                                                <p class="text-xs font-semibold uppercase text-gray-500">Siblings</p>
                                                <div class="syv-vibe-badges">
                                                @forelse (($displayProduct['siblings'] ?? []) as $sibling)
                                                    <span class="syv-sibling-badge">{{ strtoupper($sibling['label']) }}</span>
                                                @empty
                                                    @if ($siblingsResolved)
                                                        <span class="syv-empty-state" data-sibling-state="empty">None</span>
                                                    @else
                                                        <span class="syv-empty-state" data-sibling-state="unknown">Not loaded</span>
                                                    @endif
                                                @endforelse
                                                </div>
                                            </div>
                                            <div class="my-3 syv-vibe-badge-list syv-complementary-section">
                                                @php($complementaryCount = (int) ($displayProduct['complementary_count'] ?? 0))
                                                <span class="syv-complementary-status {{ $complementaryCount > 0 ? 'syv-complementary-status-active' : 'syv-complementary-status-empty' }}">
                                                    {{ $complementaryCount > 0 ? $complementaryCount : 'None' }} Complementary
                                                </span>
                                            </div>
                                            <div class="syv-vibe-actions">
                                                @if ($inParentCollection)
                                                    <button type="button" class="syv-card-action syv-vibe-button" wire:click.stop="openProductAssignments({{ \Illuminate\Support\Js::from($product['id']) }})">Vibes</button>
                                                    <button type="button" class="syv-card-action syv-sibling-button" wire:click="openProductSiblings({{ \Illuminate\Support\Js::from($product['id']) }})">Siblings</button>
                                                    <button type="button" class="syv-card-action syv-tag-button" wire:click="openProductTags({{ \Illuminate\Support\Js::from($product['id']) }})">Tags</button>
                                                    <button type="button" class="syv-card-action syv-complementary-button" wire:click="openProductComplementary({{ \Illuminate\Support\Js::from($product['id']) }})">Complementary</button>
                                                @endif
                                                @if ($collection['membership_supported'])
                                                    <x-filament::button size="xs" color="danger" class="syv-card-action" wire:click="removeProduct({{ \Illuminate\Support\Js::from($collection['gid']) }}, {{ \Illuminate\Support\Js::from($product['id']) }})">Remove</x-filament::button>
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
                @endif
            </div>
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
                    <p class="my-3 text-sm text-gray-500">Confirming creates the collection in Shopify, applies its image, creates the preview card, and adds it to this collection.</p>
                    <div data-new-collection-form>
                        {{ $this->newCollectionForm }}
                    </div>
                @else
                    <p class="my-3 text-sm text-gray-500">Search by collection title or handle inside the dropdown. Already configured collections are excluded when adding Shop Your Vibe.</p>
                    {{ $this->collectionPickerForm }}
                @endif
            @endif
            <x-slot name="footer">
                @if ($addingCard && $collectionMode === 'new')
                    <x-filament::button wire:click="createCollectionVibe" wire:loading.attr="disabled" wire:target="createCollectionVibe" :disabled="$vibeNameBlocked">Add Vibe</x-filament::button>
                @else
                    <x-filament::button wire:click="confirmCollectionSelection" wire:loading.attr="disabled" :disabled="blank($selectedCollectionGid)">{{ $addingCard ? 'Add Vibe' : 'Continue' }}</x-filament::button>
                @endif
                <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'shop-your-vibe-collections' })">Cancel</x-filament::button>
            </x-slot>
        </x-filament::modal>

        <x-filament::modal id="confirm-add-vibe" width="lg" heading="Add Vibe to Shopify?">
            @if ($confirmingAdd)
                @if ($loadError)<p role="alert" class="mb-3 rounded-lg border border-danger-300 bg-danger-50 p-3 text-danger-800">{{ $loadError }}</p>@endif
                @if (($pendingVibe['mode'] ?? '') === 'existing')
                    <p>Are you sure you want to create this Vibe in Shopify? This will create the Shop Your Vibe card for <strong>{{ $pendingVibe['preview_title'] ?? 'the selected collection' }}</strong> and add it to the parent collection. The existing collection and its products will not be changed.</p>
                @else
                    <p>Are you sure you want to create this Vibe in Shopify? This will create the required Shopify collection, upload or assign its image, create the Shop Your Vibe card, and add it to the parent collection.</p>
                @endif
                @if (! empty($pendingVibe['preview_name']))
                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-300"><strong>{{ $pendingVibe['preview_name'] }}</strong>@if (! empty($pendingVibe['preview_handle'])) — /collections/{{ $pendingVibe['preview_handle'] }}@endif</p>
                @endif
            @endif
            <x-slot name="footer">
                <x-filament::button color="gray" wire:click="cancelAddVibe">Cancel</x-filament::button>
                <x-filament::button wire:click="confirmAddVibe" wire:loading.attr="disabled" wire:target="confirmAddVibe">Yes, Add to Shopify</x-filament::button>
            </x-slot>
        </x-filament::modal>

        <x-filament::modal id="manage-vibe-assignments" width="2xl" heading="Manage Shop Your Vibes">
            @if ($managedProduct)
                <p class="font-semibold">{{ $managedProduct['title'] }}</p>
                <p class="mb-4 text-sm text-gray-500">SKU: {{ $managedProduct['sku'] ?: 'No SKU' }}</p>
                <div class="space-y-3">
                    @foreach ($vibeMappings as $mapping)
                        @continue(in_array($mapping['shopify_collection_id'], $unavailableCollectionIds, true))
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

        <x-filament::modal id="manage-sibling-assignments" width="2xl" heading="Manage Siblings">
            @if ($siblingProduct)
                <p class="font-semibold">{{ $siblingProduct['title'] }}</p>
                <p class="mb-4 text-sm text-gray-500">SKU: {{ $siblingProduct['sku'] ?: 'No SKU' }}</p>
                <div class="space-y-3">
                    @forelse ($siblingOptions as $option)
                        <label class="flex items-start gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                            <input type="checkbox" wire:model="selectedSiblings" value="{{ $option['tag'] }}">
                            <span><strong>{{ $option['label'] }}</strong><small class="block text-gray-500">{{ $option['tag'] }}</small></span>
                        </label>
                    @empty
                        <p class="text-sm text-gray-500">No sibling collections were found for this main collection.</p>
                    @endforelse
                </div>
                <p class="mt-4 text-sm text-gray-500">Saving immediately updates only the sibling tags for this product in Shopify. Unrelated tags are preserved.</p>
            @endif
            <x-slot name="footer">
                <x-filament::button wire:click="saveProductSiblings" wire:loading.attr="disabled">Save siblings</x-filament::button>
                <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'manage-sibling-assignments' })">Cancel</x-filament::button>
            </x-slot>
        </x-filament::modal>

        <x-filament::modal id="manage-tag-assignments" width="2xl" heading="Manage Product Tags">
            @if ($tagProduct)
                <p class="font-semibold">{{ $tagProduct['title'] }}</p>
                <p class="mb-4 text-sm text-gray-500">SKU: {{ $tagProduct['sku'] ?: 'No SKU' }}</p>
                {{ $this->productTagForm }}
                <p class="mt-4 text-sm text-gray-500">This queues a Shopify metafield update, then mirrors the local product and new-product draft values when the job finishes.</p>
            @endif
            <x-slot name="footer">
                <x-filament::button wire:click="saveProductTags" wire:loading.attr="disabled">Queue Shopify update</x-filament::button>
                <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'manage-tag-assignments' })">Cancel</x-filament::button>
            </x-slot>
        </x-filament::modal>

        <x-filament::modal id="manage-complementary-products" width="4xl" heading="Manage Complementary Products">
            @if ($complementaryProduct)
                <p class="font-semibold">{{ $complementaryProduct['title'] }}</p>
                <p class="mb-4 text-sm text-gray-500">SKU: {{ $complementaryProduct['sku'] ?: 'No SKU' }}</p>

                @if ($complementaryErrors)
                    <div class="mb-4 rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-warning-800">
                        @foreach ($complementaryErrors as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <div class="syv-complementary-modal-grid">
                    <section class="syv-complementary-panel syv-complementary-selected-panel">
                        <div class="syv-complementary-panel-heading">
                            <h3>Selected complementary products</h3>
                            <span>{{ count($complementarySelected) }} selected</span>
                        </div>
                        <div class="syv-complementary-selected-grid">
                        @forelse ($complementarySelected as $index => $item)
                            <div class="syv-complementary-card">
                                <div class="syv-complementary-thumb-wrap">
                                    @if ($item['image'])
                                        <img src="{{ $item['image'] }}" alt="" class="syv-complementary-thumb" loading="lazy">
                                    @else
                                        <div class="syv-complementary-thumb-placeholder"></div>
                                    @endif
                                    <span class="syv-complementary-rank">{{ $index + 1 }}</span>
                                </div>
                                <div class="syv-complementary-card-body">
                                    <p class="syv-complementary-title">{{ $item['title'] }}</p>
                                    <p class="syv-complementary-meta">SKU: {{ $item['sku'] ?: 'No SKU' }}</p>
                                    <span class="syv-complementary-availability {{ $item['available'] ? 'syv-complementary-availability-active' : 'syv-complementary-availability-muted' }}">
                                        {{ $item['availability_label'] }}
                                    </span>
                                </div>
                                <div class="syv-complementary-card-actions">
                                    <button type="button" class="syv-mini-action" wire:click="moveComplementaryProduct({{ $index }}, -1)" @disabled($index === 0)>Up</button>
                                    <button type="button" class="syv-mini-action" wire:click="moveComplementaryProduct({{ $index }}, 1)" @disabled($index === count($complementarySelected) - 1)>Down</button>
                                    <button type="button" class="syv-mini-action syv-mini-action-danger" wire:click="removeComplementaryProduct({{ \Illuminate\Support\Js::from($item['id']) }})">Remove</button>
                                </div>
                            </div>
                        @empty
                            <p class="syv-complementary-empty">No complementary products selected.</p>
                        @endforelse
                        </div>
                    </section>

                    <section class="syv-complementary-panel syv-complementary-add-panel">
                        <div class="syv-complementary-panel-heading">
                            <h3>Add products</h3>
                        </div>
                        <label class="syv-complementary-field">
                            <span>Search by product name or SKU</span>
                            <x-filament::input.wrapper>
                                <x-filament::input wire:model.live.debounce.300ms="complementarySearch" placeholder="Search product name or SKU" />
                            </x-filament::input.wrapper>
                        </label>
                        @if ($complementarySearch !== '')
                            <div class="syv-complementary-results">
                                @forelse ($complementarySearchResults as $result)
                                    <button type="button" class="syv-complementary-result" wire:click="addComplementaryProduct({{ \Illuminate\Support\Js::from($result['id']) }})">
                                        @if ($result['image'])
                                            <img src="{{ $result['image'] }}" alt="" class="syv-complementary-result-thumb" loading="lazy">
                                        @else
                                            <div class="syv-complementary-result-thumb syv-complementary-thumb-placeholder"></div>
                                        @endif
                                        <span>
                                            <strong>{{ $result['title'] }}</strong>
                                            <small>{{ $result['sku'] ?: $result['handle'] }}</small>
                                        </span>
                                    </button>
                                @empty
                                    <p class="syv-complementary-empty">No matching products found.</p>
                                @endforelse
                            </div>
                        @endif
                    </section>
                </div>
            @endif
            <x-slot name="footer">
                <p class="syv-complementary-footer-note">You can select multiple complementary products. Shopify will receive the first 3 that are currently sellable.</p>
                <x-filament::button wire:click="saveProductComplementary" wire:loading.attr="disabled">Save &amp; update Shopify</x-filament::button>
                <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'manage-complementary-products' })">Cancel</x-filament::button>
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
    <script>
        window.syvVibeSeparator = /[\s\-–—:\/|]+/;
        window.syvEscapeRegExp = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        window.syvNormalizeVibe = (value, parentTitle) => {
            let name = String(value || '').trim().toUpperCase();
            let removed = false;
            const words = String(parentTitle || '').trim().toUpperCase().split(window.syvVibeSeparator).filter(Boolean);
            if (words.length) {
                const separator = '[\\s\\-–—:\\/|]+';
                const body = words.map(window.syvEscapeRegExp).join(separator);
                const match = name.match(new RegExp('^' + body + '(?:' + separator + '([\\s\\S]*))?$'));
                if (match) {
                    removed = true;
                    name = String(match[1] || '').trim().replace(/^[\s\-–—:\/|]+/, '');
                }
            }
            name = name.replace(/\s+/g, ' ').trim().replace(/\bAND\b/g, '&').replace(/\s+/g, ' ').trim();
            return { name, removed, empty: name === '' };
        };
        window.syvSlug = (value) => String(value || '').toLowerCase().replace(/&/g, ' and ').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        window.syvCollectionTitle = (parentTitle, name) => {
            const parent = String(parentTitle || '').trim().toUpperCase();
            return (parent + (name ? ' ' + name : '')).trim();
        };
        window.syvHandle = (parentHandle, name) => [window.syvSlug(parentHandle), window.syvSlug(name)].filter(Boolean).join('-');
        window.syvSetPreview = (form, selector, value) => {
            const field = form.querySelector(selector);
            if (!field || field.value === value) {
                return;
            }
            field.value = value;
            field.dispatchEvent(new Event('input', { bubbles: true }));
        };
        window.syvUpdateVibePreview = (el) => {
            const form = el.closest('[data-new-collection-form]');
            if (!form) {
                return;
            }
            const normalized = window.syvNormalizeVibe(el.value, el.dataset.parentTitle || '');
            window.syvSetPreview(form, '[data-generated-title]', window.syvCollectionTitle(el.dataset.parentTitle || '', normalized.name));
            window.syvSetPreview(form, '[data-generated-handle]', window.syvHandle(el.dataset.parentHandle || '', normalized.name));
        };
        window.syvVibeInput = (el, wire) => {
            const start = el.selectionStart;
            const end = el.selectionEnd;
            const next = el.value.toUpperCase();
            if (next !== el.value) {
                el.value = next;
                if (typeof start === 'number' && typeof end === 'number') {
                    el.setSelectionRange(start, end);
                }
            }
            window.syvUpdateVibePreview(el);
            if (el.dataset.vibeNotice === '1') {
                el.dataset.vibeNotice = '0';
                wire.clearVibeNameNotice();
            }
        };
        window.syvVibeBlur = (el, wire) => {
            const raw = el.value;
            const normalized = window.syvNormalizeVibe(raw, el.dataset.parentTitle || '');
            el.dataset.vibeNotice = '0';
            if (el.value !== normalized.name) {
                el.value = normalized.name;
                el.dispatchEvent(new Event('input', { bubbles: true }));
            }
            window.syvUpdateVibePreview(el);
            if (normalized.removed && normalized.name !== '') {
                el.dataset.vibeNotice = '1';
            }
            wire.commitNewVibeName(raw);
        };
    </script>
</x-filament-panels::page>
