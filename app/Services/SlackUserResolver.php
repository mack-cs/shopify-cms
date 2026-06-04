<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class SlackUserResolver
{
    public function mentionForUser(?User $user): ?string
    {
        if (!$user instanceof User || !$user->slack_notifications_enabled) {
            return null;
        }

        $slackUserId = trim((string) $user->slack_user_id);

        if ($slackUserId === '' && (bool) config('services.slack.lookup_users_by_email')) {
            $slackUserId = $this->lookupUserIdByEmail((string) $user->email) ?? '';

            if ($slackUserId !== '') {
                $user->forceFill(['slack_user_id' => $slackUserId])->save();
            }
        }

        return $this->mention($slackUserId);
    }

    public function mentionOrEmailForEmail(string $email): string
    {
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
            ->first();

        if ($user instanceof User) {
            return $this->mentionForUser($user) ?? $this->escape($email);
        }

        if ((bool) config('services.slack.lookup_users_by_email')) {
            $slackUserId = $this->lookupUserIdByEmail($email);
            $mention = $this->mention((string) $slackUserId);

            if ($mention !== null) {
                return $mention;
            }
        }

        return $this->escape($email);
    }

    public function escape(mixed $value): string
    {
        return str_replace(
            ['&', '<', '>'],
            ['&amp;', '&lt;', '&gt;'],
            trim((string) $value)
        );
    }

    private function mention(string $slackUserId): ?string
    {
        $slackUserId = trim($slackUserId);

        if (!preg_match('/^[UW][A-Z0-9]+$/', $slackUserId)) {
            return null;
        }

        return "<@{$slackUserId}>";
    }

    private function lookupUserIdByEmail(string $email): ?string
    {
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return Cache::remember('slack_user_id:' . sha1($email), now()->addHours(12), function () use ($email): ?string {
            $token = trim((string) config('services.slack.notifications.bot_user_oauth_token'));

            if ($token === '') {
                return null;
            }

            $response = Http::withToken($token)
                ->acceptJson()
                ->get('https://slack.com/api/users.lookupByEmail', [
                    'email' => $email,
                ]);

            if (!$response->ok() || !$response->json('ok')) {
                return null;
            }

            $slackUserId = data_get($response->json(), 'user.id');

            return is_string($slackUserId) ? $slackUserId : null;
        });
    }
}
