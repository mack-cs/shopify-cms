<?php

namespace App\Services\Shopify;

use App\Mail\InventoryPipelineAlertMail;
use App\Models\InventoryPipelineAlert;
use App\Models\InventoryPipelineIncident;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class InventoryPipelineNotifications
{
    /** Stable identity is condition + record, never a changing retry message. */
    public function observe(string $kind, string $source, string|int $id, bool $active, array $details = []): void
    {
        if (! config('inventory_monitor.enabled')) {
            return;
        }
        $fingerprint = hash('sha256', implode('|', [$kind, $source, $id]));
        DB::transaction(function () use ($fingerprint, $kind, $source, $id, $active, $details): void {
            if ($active) {
                // Unique index arbitrates concurrent first observations before the row lock.
                InventoryPipelineIncident::query()->insertOrIgnore([
                    'fingerprint' => $fingerprint, 'kind' => $kind, 'source_type' => $source, 'source_id' => (string) $id,
                    'generation' => 1, 'details' => '{}', 'detected_at' => now(), 'last_seen_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $incident = InventoryPipelineIncident::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();
            if (! $incident) {
                return;
            }
            if ($active) {
                if ($incident->resolved_at) {
                    $incident->generation++;
                    $incident->detected_at = now();
                    $incident->last_notification_at = null;
                }
                $incident->resolved_at = null;
                $incident->last_seen_at = now();
                $incident->details = $details + ['environment' => app()->environment(), 'kind' => $kind,
                    'source' => $source.':'.$id, 'detected_at' => $incident->detected_at->toIso8601String(),
                    'order_number' => null, 'order_id' => null, 'stack_skus' => [], 'component_skus' => [],
                    'fulfillment_id' => null, 'retry_attempts' => null, 'status' => null,
                    'error_message' => null, 'cms_url' => null];
                $incident->save();
                $prefix = $incident->id.':'.$incident->generation.':';
                $initial = InventoryPipelineAlert::query()->where('delivery_key', $prefix.'failure')->first();
                if (! $initial) {
                    $this->enqueue($incident, 'failure', $prefix.'failure');
                } elseif (! $initial->sent_at) {
                    $initial->update(['details' => $incident->details]);
                } elseif (($minutes = (int) config('inventory_monitor.reminder_minutes')) > 0
                    && $incident->last_notification_at?->lte(now()->subMinutes($minutes))
                    && ! InventoryPipelineAlert::query()->where('incident_id', $incident->id)->whereNull('sent_at')->exists()) {
                    $this->enqueue($incident, 'reminder', $prefix.'reminder:'.$incident->last_notification_at->timestamp);
                }
            } elseif (! $incident->resolved_at) {
                $incident->details = array_replace($incident->details, $details);
                $incident->resolved_at = now();
                $incident->save();
                // Preserve the failure/recovery sequence even if mail is temporarily down.
                $this->enqueue($incident, 'recovery', $incident->id.':'.$incident->generation.':recovery');
            }
        });
    }

    private function enqueue(InventoryPipelineIncident $incident, string $type, string $key): void
    {
        InventoryPipelineAlert::query()->firstOrCreate(['delivery_key' => $key], [
            'incident_id' => $incident->id, 'type' => $type, 'available_at' => now(),
            'details' => $incident->details + ($type === 'recovery' ? ['resolved_at' => now()->toIso8601String()] : []),
        ]);
    }

    /** Scheduler-owned durable outbox: no inventory queue worker is needed. */
    public function deliver(): array
    {
        $result = ['sent' => 0, 'failed' => 0];
        if (! config('inventory_monitor.enabled')) {
            return $result;
        }
        $ids = InventoryPipelineAlert::query()->whereNull('sent_at')->where('available_at', '<=', now())
            ->orderBy('id')->limit(config('inventory_monitor.delivery_batch', 50))->pluck('id');
        $deadline = microtime(true) + 45;
        foreach ($ids as $id) {
            if (microtime(true) >= $deadline) {
                break; // Leave the durable backlog for the next scheduler invocation.
            }
            $incidentId = InventoryPipelineAlert::query()->whereKey($id)->value('incident_id');
            DB::transaction(function () use ($id, $incidentId, &$result): void {
                // Same lock order as observe(): incident first, then outbox row.
                InventoryPipelineIncident::query()->whereKey($incidentId)->lockForUpdate()->firstOrFail();
                $alert = InventoryPipelineAlert::query()->lockForUpdate()->findOrFail($id);
                if ($alert->sent_at || $alert->available_at->isFuture()) {
                    return;
                }
                // Do not send recovery ahead of an undelivered failure, including across workers.
                if (InventoryPipelineAlert::query()->where('incident_id', $alert->incident_id)
                    ->where('id', '<', $alert->id)->whereNull('sent_at')->exists()) {
                    return;
                }
                try {
                    $recipient = config('inventory_monitor.recipient');
                    if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                        throw new \RuntimeException('INVENTORY_MONITOR_EMAIL is invalid.');
                    }
                    Mail::to($recipient)->send(new InventoryPipelineAlertMail($alert->details, $alert->type));
                    $alert->sent_at = now();
                    $alert->delivery_error = null;
                    InventoryPipelineIncident::query()->whereKey($alert->incident_id)->update(['last_notification_at' => now()]);
                    $result['sent']++;
                } catch (\Throwable $error) {
                    $alert->delivery_error = $error->getMessage();
                    $alert->available_at = now()->addMinutes(max(1, config('inventory_monitor.delivery_retry_minutes', 5)));
                    Log::error('Inventory pipeline alert delivery failed; retained for retry.', ['alert_id' => $id, 'error' => $error->getMessage()]);
                    $result['failed']++;
                }
                $alert->attempts++;
                $alert->save();
            });
        }

        return $result;
    }
}
