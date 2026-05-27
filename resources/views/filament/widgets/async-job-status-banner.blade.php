<div>
    @if($isRunning ?? false)
        @php
            $color = $color ?? 'primary';
            $borderClass = match ($color) {
                'success' => 'border-success-200 bg-success-50 text-success-900',
                'warning' => 'border-warning-200 bg-warning-50 text-warning-900',
                'danger' => 'border-danger-200 bg-danger-50 text-danger-900',
                default => 'border-primary-200 bg-primary-50 text-primary-900',
            };
            $iconClass = match ($color) {
                'success' => 'text-success-600',
                'warning' => 'text-warning-600',
                'danger' => 'text-danger-600',
                default => 'text-primary-600',
            };
        @endphp

        <div class="mb-3 rounded-xl border p-4 text-sm {{ $borderClass }}">
            <div class="flex items-start gap-3">
                <svg class="mt-0.5 h-5 w-5 animate-spin {{ $iconClass }}" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="4"></circle>
                    <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="4" stroke-linecap="round"></path>
                </svg>
                <div>
                    <div class="font-semibold">{{ $title ?? 'Background job in progress' }}</div>
                    <div class="mt-1">
                        {{ $body ?? 'A background job is still running.' }}
                        @if(!empty($startedAt))
                            Last update {{ $startedAt }}.
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
