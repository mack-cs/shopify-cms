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
use Illuminate\Support\Facades\DB;
use Throwable;

final class DirectShopifyInventoryCorrectionReport
{
    public function __construct(private readonly ShopifyInventoryHistorySync $sync) {}

    /**
     * Email the previous complete Monday-Sunday once, on Mondays only.
     * A day is loaded from Shopify only when that day is not already stored.
     * This does not change stock.
     *
     * @return array{corrections:int,email_sent:bool,copied_users:int,errors:array<int, string>}
     */
    public function send(int $days = 7): array
    {
        $result = [
            'corrections' => 0,
            'email_sent' => false,
            'copied_users' => 0,
            'errors' => [],
        ];
        // Manual actions and old queued jobs must respect the weekly schedule too.
        if (! now('Africa/Johannesburg')->isMonday()) {
            return $result;
        }

        $until = now('Africa/Johannesburg')->startOfDay()->subDay();
        $from = $until->copy()->subDays(6);
        $previous = DB::table('direct_inventory_correction_reports')->where('week_start', $from->toDateString())->first();
        if ($previous) {
            if (in_array($previous->status, ['sending', 'needs_review'], true)) {
                $result['errors'][] = 'The weekly email has an unresolved delivery outcome. Review its receipt ledger before any manual action; no retry was sent.';
            }
            return $result;
        }
        $this->ensureStored($from, $until);
        $rows = $this->directRows($from, $until);
        if ($rows->isEmpty()) {
            return $result;
        }
        $unknown = DB::table('direct_inventory_correction_report_items')->whereIn('adjustment_id', $rows->pluck('adjustment_id'))
            ->whereIn('status', ['cutover_baseline', 'cutover_review'])->count();
        if ($unknown) {
            $result['errors'][] = $unknown.' historical correction(s) have no verified delivery receipt. Review the cutover ledger; none were silently marked sent or retried.';
            return $result;
        }

        $email = trim((string) config('services.shopify.direct_inventory_correction_email', 'shonayimack@gmail.com'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $result['errors'][] = 'DIRECT_INVENTORY_CORRECTION_EMAIL is not a valid address.';

            return $result;
        }

        $copies = $this->copyRecipients($email);
        [$reportId, $rows] = DB::transaction(function () use ($from, $rows): array {
            $claimed = DB::table('direct_inventory_correction_reports')->insertOrIgnore([
                'week_start' => $from->toDateString(), 'status' => 'sending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if (! $claimed) {
                return [null, collect()];
            }
            $reportId = DB::table('direct_inventory_correction_reports')->where('week_start', $from->toDateString())->value('id');
            $claimedRows = $rows->filter(function ($row) use ($reportId): bool {
                return DB::table('direct_inventory_correction_report_items')->insertOrIgnore([
                    'adjustment_id' => $row->adjustment_id, 'report_id' => $reportId,
                    'status' => 'reserved', 'created_at' => now(), 'updated_at' => now(),
                ]) === 1;
            })->values();
            return [$reportId, $claimedRows];
        });
        if (! $reportId) {
            return $result;
        }
        $result['corrections'] = $rows->count();
        if ($rows->isEmpty()) {
            DB::table('direct_inventory_correction_reports')->where('id', $reportId)->update(['status' => 'empty', 'updated_at' => now()]);
            return $result;
        }
        $result['copied_users'] = count($copies);
        $grouped = $this->grouped($rows);

        try {
            $pending = Mail::to($email);
            if ($copies !== []) {
                $pending->cc($copies);
            }
            $pending->send(new DirectShopifyInventoryCorrectionMail($grouped, $from, $until));
            DB::table('direct_inventory_correction_reports')->where('id', $reportId)->update(['status' => 'sent', 'updated_at' => now()]);
            DB::table('direct_inventory_correction_report_items')->where('report_id', $reportId)->update(['status' => 'sent', 'updated_at' => now()]);
            $result['email_sent'] = true;
        } catch (Throwable $exception) {
            // A transport exception may follow acceptance. Never automatically
            // replay an uncertain email and repeat all its corrections.
            DB::table('direct_inventory_correction_reports')->where('id', $reportId)->update(['status' => 'needs_review', 'error' => $exception->getMessage(), 'updated_at' => now()]);
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
        $until = now('Africa/Johannesburg')->startOfWeek(Carbon::MONDAY)->subDay();
        $from = $until->copy()->subDays(6);
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
            if ($logged && in_array($logged->status, ['succeeded', 'corrections'], true)
                && $logged->finished_at && $logged->finished_at->gte($day->copy()->endOfDay())) {
                continue;
            }
            $loaded = $this->sync->syncDay($day->copy(), null, 'correction');
            if (! in_array($loaded['status'], ['succeeded', 'corrections'], true)) {
                throw new \RuntimeException('Correction history is incomplete for '.$day->toDateString().'; the weekly report was not sent.');
            }
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
