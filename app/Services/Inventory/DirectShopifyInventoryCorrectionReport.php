<?php

namespace App\Services\Inventory;

use App\Mail\DirectShopifyInventoryCorrectionMail;
use App\Models\ShopifyInventoryAdjustment;
use App\Models\ShopifyInventoryHistorySync as ShopifyInventoryHistorySyncLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class DirectShopifyInventoryCorrectionReport
{
    public function __construct(private readonly ShopifyInventoryHistorySync $sync) {}

    /**
     * Read the last 7 days of stored correction history and email direct Shopify edits.
     * A day is loaded from Shopify only when that day is not already stored.
     * This does not change stock.
     *
     * @return array{corrections:int,email_sent:bool,copied_users:int,errors:array<int, string>}
     */
    public function send(int $days = 7): array
    {
        $days = max(1, min(7, $days));
        $until = now()->startOfDay();
        $from = $until->copy()->subDays($days - 1);
        $this->ensureStored($from, $until);

        $rows = $this->directRows($from, $until);

        $result = [
            'corrections' => $rows->count(),
            'email_sent' => false,
            'copied_users' => 0,
            'errors' => [],
        ];
        if ($rows->isEmpty()) {
            return $result;
        }

        $email = trim((string) config('services.shopify.direct_inventory_correction_email', 'shonayimack@gmail.com'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $result['errors'][] = 'DIRECT_INVENTORY_CORRECTION_EMAIL is not a valid address.';

            return $result;
        }

        $copies = $this->copyRecipients($email);
        $result['copied_users'] = count($copies);
        $grouped = $this->grouped($rows);

        try {
            $pending = Mail::to($email);
            if ($copies !== []) {
                $pending->cc($copies);
            }
            $pending->send(new DirectShopifyInventoryCorrectionMail($grouped, $from, $until));
            $result['email_sent'] = true;
        } catch (Throwable $exception) {
            $result['errors'][] = 'Email: '.$exception->getMessage();
            Log::error('Direct Shopify inventory correction email failed.', [
                'recipient' => $email,
                'exception' => $exception,
            ]);
        }

        return $result;
    }

    /**
     * The on-screen snapshot. This reads stored rows and does not call Shopify.
     *
     * @return array{from:Carbon,until:Carbon,corrections:int,groups:array<int, array{user:string,rows:array<int, array<string, mixed>>}>}
     */
    public function preview(int $days = 7): array
    {
        $days = max(1, min(7, $days));
        $until = now()->startOfDay();
        $from = $until->copy()->subDays($days - 1);
        $rows = $this->directRows($from, $until);

        return [
            'from' => $from,
            'until' => $until,
            'corrections' => $rows->count(),
            'groups' => $this->grouped($rows),
        ];
    }

    /**
     * @return Collection<int, ShopifyInventoryAdjustment>
     */
    private function directRows(Carbon $from, Carbon $until): Collection
    {
        return ShopifyInventoryAdjustment::query()
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $until->copy()->endOfDay()])
            ->whereRaw('lower(reason) = ?', ['correction'])
            ->orderBy('occurred_at')
            ->get()
            ->filter(fn (ShopifyInventoryAdjustment $row): bool => $this->isDirect($row))
            ->values();
    }

    private function ensureStored(Carbon $from, Carbon $until): void
    {
        for ($day = $from->copy(); $day->lte($until); $day->addDay()) {
            $logged = ShopifyInventoryHistorySyncLog::query()
                ->whereDate('history_date', $day->toDateString())
                ->first();
            if ($logged && in_array($logged->status, ['succeeded', 'corrections'], true)) {
                continue;
            }
            $this->sync->syncDay($day->copy(), null, 'correction');
            if ($day->copy()->addDay()->lte($until)) {
                sleep(2);
            }
        }
    }

    private function isDirect(ShopifyInventoryAdjustment $row): bool
    {
        $app = strtolower(trim((string) $row->app_name));
        if ($app === 'la-cms-access') {
            return false;
        }
        $reference = strtolower((string) $row->reference_document_uri);
        foreach (['la-cms-access', 'leigh-avenue-cms', 'la-cms', 'logistics://shopify-editor'] as $marker) {
            if ($reference !== '' && str_contains($reference, $marker)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  Collection<int, ShopifyInventoryAdjustment>  $rows
     * @return array<int, array{user:string,rows:array<int, array<string, mixed>>}>
     */
    private function grouped(Collection $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $user = trim((string) $row->staff_name);
            if ($user === '') {
                $user = 'Shopify did not name a user';
            }
            $groups[$user][] = [
                'occurred_at' => optional($row->occurred_at)->timezone(config('app.timezone'))->format('M j, Y H:i') ?? '',
                'sku' => (string) ($row->sku ?? ''),
                'product' => (string) ($row->product_title ?? ''),
                'delta' => (int) $row->delta,
                'state' => (string) ($row->inventory_state ?? ''),
                'app' => (string) ($row->app_name ?? ''),
                'location' => (string) ($row->location_name ?? ''),
            ];
        }

        $report = [];
        foreach ($groups as $user => $userRows) {
            $report[] = ['user' => $user, 'rows' => $userRows];
        }

        return $report;
    }

    /**
     * @return array<int, string>
     */
    private function copyRecipients(string $primary): array
    {
        return User::query()
            ->where('is_active', true)
            ->pluck('email')
            ->map(fn ($email): string => trim((string) $email))
            ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) && strcasecmp($email, $primary) !== 0)
            ->unique(fn (string $email): string => strtolower($email))
            ->values()
            ->all();
    }
}
