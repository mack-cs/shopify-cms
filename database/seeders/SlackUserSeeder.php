<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class SlackUserSeeder extends Seeder
{
    /**
     * Add email values here when you know the live user emails. Email matching is
     * safest; name matching is only a fallback for the current small team.
     *
     * @var array<int, array{name:string, email:?string, slack_user_id:string}>
     */
    private array $slackUsers = [
        ['name' => 'Nick Wonfor', 'email' => 'nick@leighavenue.co.za', 'slack_user_id' => 'U0B5DM894DS'],
        ['name' => 'Freddy', 'email' => 'freddy@leighavenue.co.za', 'slack_user_id' => 'U0B581CAM54'],
        ['name' => 'Doron', 'email' => 'doron@leighavenue.co.za', 'slack_user_id' => 'U0B4UH3VD6K'],
        ['name' => 'Leanne', 'email' => 'leanne@leighavenue.co.za', 'slack_user_id' => 'U0B5A38UUKU'],
        ['name' => 'Mack Shonayi', 'email' => 'mack@mackscs.com', 'slack_user_id' => 'U0B5A3AA5MG'],
    ];

    public function run(): void
    {
        if (
            !Schema::hasColumn('users', 'slack_user_id')
            || !Schema::hasColumn('users', 'slack_notifications_enabled')
        ) {
            $this->command?->warn('Skipping Slack user seeding because the user Slack columns do not exist yet.');

            return;
        }

        foreach ($this->slackUsers as $slackUser) {
            $name = $slackUser['name'];
            $email = $slackUser['email'];
            $slackUserId = $slackUser['slack_user_id'];
            $user = $this->findUser($name, $email);

            if (!$user instanceof User) {
                $this->command?->warn("No unique user found for Slack seed name '{$name}'. Skipped.");

                continue;
            }

            $user->forceFill([
                'slack_user_id' => $slackUserId,
                'slack_notifications_enabled' => true,
            ])->save();

            $this->command?->info("Seeded Slack ID for {$user->name} ({$user->email}).");
        }
    }

    private function findUser(string $name, ?string $email): ?User
    {
        $email = is_string($email) ? strtolower(trim($email)) : '';

        if ($email !== '') {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            if ($user instanceof User) {
                return $user;
            }
        }

        return $this->findUserByName($name);
    }

    private function findUserByName(string $name): ?User
    {
        $normalized = strtolower(trim($name));

        $exact = User::query()
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->first();

        if ($exact instanceof User) {
            return $exact;
        }

        $matches = User::query()
            ->whereRaw('LOWER(name) LIKE ?', [$normalized . ' %'])
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
