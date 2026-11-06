@php($moves = collect($sorting['moves'] ?? []))
@php($sortGid = $sortGid ?? ($draft->collection_gid ?? ''))
@php($sortTitle = $sortTitle ?? ($draft->desired['collections'][$sortGid]['title'] ?? $sortGid))
@php($sortEditing = $sortEditing ?? ($editing ?? []))
@php($isReviewer = (int) ($sorting['reviewer_id'] ?? 0) === (int) auth()->id())
@php($openRequest = ($sorting['awaiting'] ?? 0) > 0)
@if ($draft && $moves->isNotEmpty())
    <section class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm dark:border-amber-700 dark:bg-amber-950" data-sorting-panel data-sorting-collection="{{ $sortGid }}" aria-label="Sorting approval for {{ $sortTitle }}">
        <h2 class="font-semibold text-gray-900 dark:text-white">{{ $openRequest ? 'Sorting approval request' : 'Explicitly moved products' }} — {{ $sortTitle }}</h2>
        @if ($openRequest || !empty($sorting['changes_feedback']) || !empty($sorting['reviewer_name']))
            <dl class="mt-3 grid gap-2 text-xs sm:grid-cols-2">
                <div><dt class="font-medium text-gray-700 dark:text-gray-200">Submitted by</dt><dd>{{ $sorting['submitter_name'] ?: 'Not submitted' }}</dd></div>
                <div><dt class="font-medium text-gray-700 dark:text-gray-200">Assigned reviewer</dt><dd>{{ $sorting['reviewer_name'] ?: 'Not assigned' }}</dd></div>
                <div><dt class="font-medium text-gray-700 dark:text-gray-200">Submission timestamp</dt><dd>{{ $sorting['submitted_at'] ?: 'Not submitted' }}</dd></div>
                <div><dt class="font-medium text-gray-700 dark:text-gray-200">Explicitly moved products</dt><dd>{{ $sorting['moved_count'] ?? $moves->count() }}</dd></div>
                <div><dt class="font-medium text-gray-700 dark:text-gray-200">Current approval status</dt><dd>{{ $sorting['request_status'] ?? 'Not sorted' }}</dd></div>
            </dl>
        @endif
        @if (!empty($sorting['changes_feedback']))
            <p class="mt-3 text-xs text-gray-700 dark:text-gray-200">
                Changes requested by {{ $sorting['changes_requested_by'] ?: 'the reviewer' }}
                @if (!empty($sorting['changes_requested_at']))
                    at {{ $sorting['changes_requested_at'] }}
                @endif:
                {{ $sorting['changes_feedback'] }}
            </p>
        @endif
        <table class="mt-3 w-full text-left text-xs">
            <thead>
                <tr>
                    <th class="py-1 pr-2">Product</th>
                    <th class="py-1 pr-2">Previous position</th>
                    <th class="py-1 pr-2">Proposed position</th>
                    <th class="py-1">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($moves as $move)
                    <tr>
                        <td class="py-1 pr-2">{{ $move['product_name'] }}</td>
                        <td class="py-1 pr-2">{{ $move['previous_label'] }}</td>
                        <td class="py-1 pr-2">{{ $move['proposed_label'] }}</td>
                        <td class="py-1">{{ $move['status'] }}@if (!empty($move['sorter'])) · {{ $move['sorter'] }}@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="mt-3 flex flex-wrap gap-2">
            @if ($isReviewer && $openRequest)
                <x-filament::button type="button" size="sm" color="gray" wire:click="askToStartSorting({{ \Illuminate\Support\Js::from($sortGid) }})">Review / Edit</x-filament::button>
                @if (empty($sorting['approved']))
                    <x-filament::button type="button" size="sm" wire:click="approveAndPublishSorting({{ \Illuminate\Support\Js::from($sortGid) }})" wire:loading.attr="disabled" wire:target="approveAndPublishSorting">
                        <span wire:loading.remove wire:target="approveAndPublishSorting">Approve &amp; Publish Sorting</span>
                        <span wire:loading wire:target="approveAndPublishSorting">Publishing {{ $sortTitle }} sorting…</span>
                    </x-filament::button>
                @else
                    <x-filament::button type="button" size="sm" wire:click="approveAndPublishSorting({{ \Illuminate\Support\Js::from($sortGid) }})" wire:loading.attr="disabled" wire:target="approveAndPublishSorting">
                        <span wire:loading.remove wire:target="approveAndPublishSorting">Publish {{ $sortTitle }} sorting</span>
                        <span wire:loading wire:target="approveAndPublishSorting">Publishing {{ $sortTitle }} sorting…</span>
                    </x-filament::button>
                @endif
            @endif
            @if (($sortEditing['mine'] ?? false) && ($sorting['unsubmitted'] ?? 0) > 0 && ! ($sortEditing['blocked'] ?? false))
                @if ($confirmingSortSubmit && ($confirmingSortGid ?? null) === $sortGid)
                    <label class="block text-xs font-medium" for="syv-sort-reviewer-{{ md5($sortGid) }}">Reviewer for {{ $sortTitle }}</label>
                    <select id="syv-sort-reviewer-{{ md5($sortGid) }}" wire:model="sortReviewerId" class="block w-full max-w-sm rounded-lg border-gray-300 text-sm">
                        <option value="">Choose a reviewer</option>
                        @foreach ($sortReviewers as $reviewer)
                            <option value="{{ $reviewer->id }}">{{ $reviewer->name }}</option>
                        @endforeach
                    </select>
                    <x-filament::button type="button" size="sm" wire:click="submitSorting({{ \Illuminate\Support\Js::from($sortGid) }})">Request Sorting Approval</x-filament::button>
                @else
                    <x-filament::button type="button" size="sm" color="gray" wire:click="beginSortSubmit({{ \Illuminate\Support\Js::from($sortGid) }})">Request Sorting Approval</x-filament::button>
                @endif
            @endif
        </div>
        @if (($sortPublishError['gid'] ?? null) === $sortGid && filled($sortPublishError['message'] ?? null))
            <p role="alert" class="mt-3 text-sm font-medium text-danger-700 dark:text-danger-300" data-sort-publish-error>{{ $sortPublishError['message'] }}</p>
        @endif
        @if ($isReviewer && $openRequest)
            <div class="mt-3 max-w-xl">
                <label class="block text-xs font-medium" for="syv-sort-feedback-{{ md5($sortGid) }}">Review feedback for {{ $sortTitle }}</label>
                <textarea id="syv-sort-feedback-{{ md5($sortGid) }}" wire:model="sortChangeFeedback" maxlength="4000" class="mt-1 block w-full rounded-lg border-gray-300 text-sm" placeholder="Tell the sorter what to change"></textarea>
                @error('sortChangeFeedback')<p role="alert" class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                <x-filament::button type="button" size="sm" color="gray" class="mt-2" wire:click="requestSortingChanges({{ \Illuminate\Support\Js::from($sortGid) }})">Request Changes</x-filament::button>
            </div>
        @endif
    </section>
@endif
