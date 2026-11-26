<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Persist and reload datetimes as UTC wall-clock values without shifting by app timezone.
 *
 * The database column stores `Y-m-d H:i:s` interpreted as UTC. This avoids the
 * two-hour SAST shift that Laravel's default datetime cast applies when
 * APP_TIMEZONE is Africa/Johannesburg.
 *
 * @implements CastsAttributes<CarbonImmutable|null, CarbonImmutable|string|null>
 */
final class UtcDateTimeCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if ($value instanceof CarbonImmutable) {
                return $value->utc();
            }

            $string = trim((string) $value);
            if ($string === '') {
                return null;
            }

            // Stored naive UTC wall clock from this cast.
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $string) === 1) {
                return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $string, 'UTC');
            }

            return CarbonImmutable::parse($string)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}
