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
            class="mt-3"
            x-cloak
            x-show="! isCollapsed"
        >
            {{ $this->form }}
        </div>
    </x-filament::section>
</div>
