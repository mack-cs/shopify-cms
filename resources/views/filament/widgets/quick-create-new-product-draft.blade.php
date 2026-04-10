<div
    x-data="{
        isCollapsed: $persist(false).as('quick-add-draft-is-collapsed'),
    }"
>
    <x-filament::section heading="Quick Add Draft">
        <x-slot name="headerEnd">
            <x-filament::icon-button
                color="gray"
                icon="heroicon-m-chevron-down"
                icon-alias="quick-add-draft.collapse-button"
                x-on:click.stop="isCollapsed = ! isCollapsed"
                x-bind:class="{ 'rotate-180': ! isCollapsed }"
            />
        </x-slot>

        <div
            class="relative mt-3"
            x-cloak
            x-show="! isCollapsed"
        >
            <div
                class="absolute inset-0 z-10 hidden items-center justify-center rounded-xl border border-primary-200 bg-white/80 p-4 backdrop-blur-sm"
                wire:loading.delay.flex
                wire:target="mountFormComponentAction,callMountedFormComponentAction,createDraft"
            >
                <div class="flex max-w-sm items-start gap-3 rounded-xl border border-primary-200 bg-primary-50 px-4 py-3 text-sm text-primary-900 shadow-sm">
                    <svg class="mt-0.5 h-5 w-5 animate-spin text-primary-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="4"></circle>
                        <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="4" stroke-linecap="round"></path>
                    </svg>
                    <div>
                        <div class="font-semibold">Creating draft</div>
                        <div class="mt-1">
                            Saving the new product draft now. Wait for the confirmation before starting another one.
                        </div>
                    </div>
                </div>
            </div>

            <div
                wire:loading.class="pointer-events-none opacity-60"
                wire:target="mountFormComponentAction,callMountedFormComponentAction,createDraft"
            >
                {{ $this->form }}
            </div>
        </div>
    </x-filament::section>
</div>
