<?php

namespace App\Services;

use App\Mail\ImagePublicationReminderMail;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** One consolidated reminder, then one follow-up a day later. The delivery row is claimed before the mailer is called. */
final class ImagePublicationReminderService
{
    /**
     * @return array{sent:bool, kind:?string, products:int, error:?string}
     */
    public function sendDue(?string $recipient = null): array
    {
        if ($this->claimWouldBeRolledBack()) {
            return ['sent' => false, 'kind' => null, 'products' => 0, 'error' => 'A reminder cannot be sent inside an open database transaction.'];
        }
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
            $initialState = $this->deliveryState($row, 'initial');
            if (! in_array($initialState, ['accepted', 'sending', 'uncertain'], true)) {
                $initial[] = $row;

                continue;
            }
            if ($initialState !== 'accepted') {
                continue;
            }
            $sentAt = DB::table('image_publication_reminders')
                ->where('product_id', $row['product_id'])
                ->where('attention_since', $since)
                ->where('kind', 'initial')
                ->where('status', 'accepted')
                ->value('sent_at');
            $followState = $this->deliveryState($row, 'follow_up');
            if (! in_array($followState, ['accepted', 'sending', 'uncertain'], true)
                && $sentAt !== null
                && \Illuminate\Support\Carbon::parse($sentAt)->lte(now()->subHours(24))) {
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
        $claimed = [];
        foreach ($batch as $row) {
            if ($this->claim($row, $kind)) {
                $claimed[] = $row;
            }
        }
        if ($claimed === []) {
            return ['sent' => false, 'kind' => null, 'products' => 0, 'error' => null];
        }
        try {
            Mail::to($recipient)->send(new ImagePublicationReminderMail($claimed, $kind));
        } catch (Throwable $exception) {
            foreach ($claimed as $row) {
                $this->mark($row, $kind, 'uncertain', $exception->getMessage());
            }
            Log::error('Image publication reminder outcome is uncertain.', ['exception' => $exception]);

            return ['sent' => false, 'kind' => $kind, 'products' => count($claimed), 'error' => $exception->getMessage()];
        }
        foreach ($claimed as $row) {
            $this->mark($row, $kind, 'accepted', null);
        }

        return ['sent' => true, 'kind' => $kind, 'products' => count($claimed), 'error' => null];
    }

    /** A caller transaction would roll the claim back after the mailer has already sent. */
    private function claimWouldBeRolledBack(): bool
    {
        return DB::connection()->transactionLevel() > 0;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function deliveryState(array $row, string $kind): ?string
    {
        $status = DB::table('image_publication_reminders')
            ->where('product_id', $row['product_id'])
            ->where('attention_since', $row['attention_since'])
            ->where('kind', $kind)
            ->value('status');

        return $status === null ? null : (string) $status;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function claim(array $row, string $kind): bool
    {
        try {
            return DB::transaction(function () use ($row, $kind): bool {
                $existing = DB::table('image_publication_reminders')
                    ->where('product_id', $row['product_id'])
                    ->where('attention_since', $row['attention_since'])
                    ->where('kind', $kind)
                    ->lockForUpdate()
                    ->first();
                if ($existing && in_array($existing->status, ['accepted', 'sending', 'uncertain'], true)) {
                    return false;
                }
                if ($existing) {
                    DB::table('image_publication_reminders')->where('id', $existing->id)->update([
                        'status' => 'sending',
                        'detail' => null,
                        'sent_at' => null,
                        'updated_at' => now(),
                    ]);

                    return true;
                }
                DB::table('image_publication_reminders')->insert([
                    'product_id' => $row['product_id'],
                    'attention_since' => $row['attention_since'],
                    'kind' => $kind,
                    'status' => 'sending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return true;
            });
        } catch (QueryException $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, 'UNIQUE') || str_contains($message, 'Duplicate') || str_contains($message, 'Deadlock') || str_contains($message, '1213')) {
                return false;
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function mark(array $row, string $kind, string $status, ?string $detail): void
    {
        DB::table('image_publication_reminders')
            ->where('product_id', $row['product_id'])
            ->where('attention_since', $row['attention_since'])
            ->where('kind', $kind)
            ->where('status', 'sending')
            ->update([
                'status' => $status,
                'detail' => $detail,
                'sent_at' => $status === 'accepted' ? now() : null,
                'updated_at' => now(),
            ]);
    }
}
