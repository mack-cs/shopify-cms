<?php

namespace App\Services\Shopify;

use App\Mail\StaleStackReservationAlertMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class StackReservationStaleAlertService
{
    public function __construct(private readonly StackReservationFulfillmentAuditService $audit) {}

    /**
     * Detect leftover reservations Shopify does not show as open.
     * This never reserves, releases, or edits reservation rows.
     *
     * @return array{stale_rows:int, email_sent:bool, errors:array<int, string>}
     */
    public function send(): array
    {
        $report = $this->audit->report(strict: true);
        $rows = array_values(array_filter(
            $report['rows'],
            fn (array $row): bool => $row['issue'] !== StackReservationFulfillmentAuditService::ISSUE_OPEN,
        ));
        $result = ['stale_rows' => count($rows), 'email_sent' => false, 'errors' => []];
        if ($rows === []) {
            return $result;
        }

        $email = trim((string) config('services.shopify.stale_reservation_alert_email', 'shonayimack@gmail.com'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $result['errors'][] = 'STACK_RESERVATION_ALERT_EMAIL is not a valid address.';

            return $result;
        }

        try {
            Mail::to($email)->send(new StaleStackReservationAlertMail($rows));
            $result['email_sent'] = true;
        } catch (Throwable $exception) {
            $result['errors'][] = 'Email: '.$exception->getMessage();
            Log::error('Stale stack reservation alert email failed.', [
                'recipient' => $email,
                'exception' => $exception,
            ]);
        }

        return $result;
    }
}
