<?php

namespace App\Services;

use App\Enums\RolesEnum;
use App\Models\User;
use RuntimeException;

final class ImageRecoveryOperator
{
    public function authorize(mixed $id): User
    {
        if (! ctype_digit((string) $id)) {
            throw new RuntimeException('An operator user id is required.');
        }
        $user = User::query()->useWritePdo()->find((int) $id);
        if (! $user || ! $user->is_active || ! $user->hasAnyRole([RolesEnum::Admin->value, RolesEnum::SuperAdmin->value])) {
            throw new RuntimeException('Recovery requires an active Admin or SuperAdmin operator.');
        }

        return $user;
    }

    public function reason(mixed $reason): string
    {
        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 20 || mb_strlen($reason) > 180) {
            throw new RuntimeException('Documented recovery reasons must contain 20 to 180 characters.');
        }

        return $reason;
    }
}
