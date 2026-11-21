<?php

namespace App\Services;

use App\Mail\ImagePublicationReminderMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** One consolidated reminder, then one follow-up a day later. Delivery is recorded only after the mailer accepts it. */
final class ImagePublicationReminderService
{
    /**
     * @return array{sent:bool, kind:?string, products:int, error:?string}
     */
    public function sendDue(?string $recipient = null): array
    {
        $rows = app(StackImageSync::class)->outstandingReminderRows();
        $recipient = trim((string) ($recipient ?: config('services.slack.missing_alt_recipient_email')));
        if ($rows === []) {
            return ['sent' => false, 'kind' => null, 'products' => 0, 'error' => null];
        }
        $initial = [];
        $follow = [];
        foreach ($rows as $row) {
            $since = \Illuminate\Support\Carbon::parse($row['attention_since'])->format('Y-m-d H:i:s');
            $row['attention_since'] = $since;
            $initialSent = DB::table('image_publication_reminders')
                ->where('product_id', $row['product_id'])
                ->where('attention_since', $since)
                ->where('kind', 'initial')
                ->where('status', 'accepted')
                ->first();
            if (! $initialSent) {
                $initial[] = $row;

                continue;
            }
            $followSent = DB::table('image_publication_reminders')
                ->where('product_id', $row['product_id'])
                ->where('attention_since', $since)
                ->where('kind', 'follow_up')
                ->where('status', 'accepted')
                ->exists();
            if (! $followSent && $initialSent->sent_at !== null && \Illuminate\Support\Carbon::parse($initialSent->sent_at)->lte(now()->subHours(24))) {
                $follow[] = $row;
            }
        }
        $kind = $initial !== [] ? 'initial' : ($follow !== [] ? 'follow_up' : null);
        $batch = $kind === 'initial' ? $initial : $follow;
        if ($kind === null || $batch === []) {
            return ['sent' => false, 'kind' => null, 'products' => 0, 'error' => null];
        }
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return ['sent' => false, 'kind' => $kind, 'products' => count($batch), 'error' => 'MISSING_ALT_RECIPIENT_EMAIL is not a valid address.'];
        }
        try {
            Mail::to($recipient)->send(new ImagePublicationReminderMail($batch, $kind));
        } catch (Throwable $exception) {
            foreach ($batch as $row) {
                $this->record($row, $kind, 'failed', $exception->getMessage());
            }
            Log::error('Image publication reminder was not accepted.', ['exception' => $exception]);

            return ['sent' => false, 'kind' => $kind, 'products' => count($batch), 'error' => $exception->getMessage()];
        }
        foreach ($batch as $row) {
            $this->record($row, $kind, 'accepted', null);
        }

        return ['sent' => true, 'kind' => $kind, 'products' => count($batch), 'error' => null];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function record(array $row, string $kind, string $status, ?string $detail): void
    {
        $existing = DB::table('image_publication_reminders')
            ->where('product_id', $row['product_id'])
            ->where('attention_since', $row['attention_since'])
            ->where('kind', $kind)
            ->first();
        $payload = [
            'status' => $status,
            'detail' => $detail,
            'sent_at' => $status === 'accepted' ? now() : null,
            'updated_at' => now(),
        ];
        if ($existing) {
            if ($existing->status === 'accepted') {
                return;
            }
            DB::table('image_publication_reminders')->where('id', $existing->id)->update($payload);

            return;
        }
        DB::table('image_publication_reminders')->insert(array_merge($payload, [
            'product_id' => $row['product_id'],
            'attention_since' => $row['attention_since'],
            'kind' => $kind,
            'created_at' => now(),
        ]));
    }
}
