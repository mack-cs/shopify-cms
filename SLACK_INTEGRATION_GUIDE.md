# Slack Integration Guide

This guide adds Slack to this Laravel 12 / Filament project without using the README. It is written for the current codebase, where:

- New product draft assignments are created in `app/Services/NewProductDraftAssignmentService.php`.
- The current bulk action is `sendAssignmentEmail` in `app/Filament/Resources/NewProductDraftResource.php`.
- Assignment delivery currently runs through `app/Jobs/SendNewProductDraftAssignmentEmailJob.php`.
- Complementary-product audit flags are stored in `shopify_audits` by `app/Services/ComplementaryProductMaintenanceService.php`.
- Scheduled tasks already live in `routes/console.php`.

The implementation below keeps the existing email pieces available, but moves day-to-day attention into Slack:

- Assign selected drafts to application users instead of only typing email addresses.
- Send an immediate Slack channel message that mentions the assigned person.
- Send delayed Slack reminders for product partial approval requests, grouped after 30 minutes so multiple pending requests do not spam the channel.
- Send a configurable three-times-per-day Slack reminder report for open assignments, complementary-product Shopify gaps, product/image errors, and failed queue jobs.
- Optionally send critical Laravel log messages to Slack through the existing `config/logging.php` Slack channel.

## How The Slack Pieces Fit Together

Use this section as the mental model for this implementation and for future Slack work in the app.

### `config/services.php`

This file is the central place where Laravel stores third-party service config. For Slack, it reads values from `.env` and gives the rest of the app stable config keys:

- `services.slack.notifications.bot_user_oauth_token` is the bot token used by Laravel's Slack notification channel.
- `services.slack.channels.assignments` is where new assignment messages go.
- `services.slack.channels.partial_approvals` is where delayed partial approval reminders go.
- `services.slack.channels.audits` is where Shopify audit alerts can go.
- `services.slack.channels.reminders` is where the three-times-per-day pending-work report goes.
- `services.slack.lookup_users_by_email` controls whether the app may call Slack's API to find a Slack user by email.
- `services.slack.reminder_times` controls the daily reminder schedule.

Why this matters: every other file should read Slack settings with `config('services.slack...')` instead of reading `.env` directly. That keeps Slack setup reusable and easier to change.

### `.env`

`.env` stores the real Slack token and channel IDs for the current environment. Local, staging, and production can each point to different Slack channels without code changes.

Why this matters: secrets like `SLACK_BOT_USER_OAUTH_TOKEN` must not be hard-coded in PHP files.

### Slack Migration

The migration `database/migrations/2026_06_04_084426_add_slack_fields_to_users_and_new_product_draft_assignments.php` adds the storage needed for Slack notification routing and reminder tracking.

On `users`, it adds:

- `slack_user_id`: the Slack member ID, for example `U0B5A3AA5MG`.
- `slack_notifications_enabled`: lets a user be skipped from Slack mentions without deleting their Slack ID.

On `new_product_draft_assignments`, it adds:

- `assigned_user_ids`: which app users the task is waiting on.
- `notification_channel`: the Slack channel used for that assignment.
- `work_status`: whether the assignment is still `open` or `completed`.
- `completed_at`: when the work was marked complete.
- `last_slack_notified_at`: when Slack was last told about it.

The backfill marks old assignments with `sent_at` as `completed`. That protects the existing assignment history from suddenly appearing as pending Slack work.

Why this matters: Slack is not just a one-time message sender here. It also needs enough database state to know who owns a task, whether it is still pending, and whether reminders should keep mentioning it.

### `app/Models/User.php`

The `User` model stores the new Slack fields in `$fillable` and casts `slack_notifications_enabled` to a boolean. It also has:

```php
public function routeNotificationForSlack(LaravelNotification $notification): mixed
{
    return config('services.slack.channels.assignments');
}
```

That method tells Laravel where to send Slack notifications when a `User` is used as the notifiable model.

Why this matters: if another workflow needs to mention users in Slack, it can use the same `slack_user_id` field and the same user notification route.

### `database/seeders/SlackUserSeeder.php`

This seeder attaches Slack member IDs to existing users. It does not create users.

It tries to find each user by email first, because email is the safest unique match. If email matching fails, it falls back to a unique name match.

Before running it in live, confirm the email domains match the real `users.email` values. This project commonly uses `leighavenue.co.za`, so a typo in the seeder email would make it fall back to name matching.

Why this matters: in live, users already exist. This seeder is only a repeatable way to fill `slack_user_id` and enable Slack notifications without manually editing every account. If the team is small, manual editing in Filament is also fine.

### `app/Services/SlackUserResolver.php`

This service is the reusable Slack identity helper. It answers one main question: "Given an app user or email, what should I put in a Slack message?"

It does four jobs:

1. `mentionForUser(User $user)` returns a real Slack mention like `<@U0B5A3AA5MG>` when the user has Slack enabled.
2. `mentionOrEmailForEmail($email)` finds the local user by email and returns a mention if possible, otherwise a safe email fallback.
3. `lookupUserIdByEmail($email)` can call Slack's `users.lookupByEmail` API when `SLACK_LOOKUP_USERS_BY_EMAIL=true`.
4. `escape($value)` makes text safe for Slack message formatting.

Why this matters: every future Slack feature should use this service instead of manually building `<@...>` strings in different places. That keeps mention behavior consistent.

### `app/Models/NewProductDraftAssignment.php`

This model represents the assignment record. The Slack additions make the assignment record carry both the old email/CSV fields and the new Slack reminder state.

The important Slack parts are:

- `assigned_user_ids` is cast to an array because it is stored as JSON.
- `completed_at` and `last_slack_notified_at` are cast to datetimes.
- The new fields are in `$fillable`, so the assignment service can set them safely.

Why this matters: the existing assignment system keeps working, while Slack gets the extra state it needs.

### Assignment Slack Notification And Job

When you add `NewProductDraftAssignmentSlackNotification` and `SendNewProductDraftAssignmentSlackJob` from this guide:

- The notification builds the Slack message: title, assignee mention, selected work columns, draft count, and link back to Filament.
- The job sends the notification on the queue and then records whether Slack sending succeeded or failed.

Why this matters: building the message and sending the message are separate jobs. The UI can queue the work quickly, and failures can be retried by Laravel's queue system.

### Reminder Notification And Scheduled Command

When you add `PendingWorkSlackReminderNotification` and the `slack:pending-work-reminder` command:

- The notification builds the pending-work report.
- The command sends it to the configured reminder channel.
- The schedule runs that command three times per day.

Why this matters: immediate assignment messages catch the first handoff; scheduled reminders catch the work that is still open later.

### Reusing This Pattern For Other Tasks

For any future workflow, reuse the same pattern:

1. Add database fields that identify the owner, status, and last notification time.
2. Store Slack IDs on `users`, not inside every task table.
3. Use `SlackUserResolver` to turn users into mentions.
4. Create a notification class that only builds the Slack message.
5. Create a queued job or scheduled command that decides when to send it.
6. Update the task record after sending, failing, or completing.

Example future uses:

- deletion approval reminders,
- collection approval reminders,
- product partial approval reminders,
- failed Shopify sync alerts,
- image backup failure alerts,
- inventory audit reminders.

## 1. Install The Laravel Slack Channel

Run this from the project root:

```bash
composer require laravel/slack-notification-channel
```

Laravel 12 uses the `laravel/slack-notification-channel` package for Slack notifications. The project already has the expected `services.php` Slack config stub, so the package can plug into the current app structure.

## 2. Create And Connect The Slack App

Create one Slack app for the workspace that should receive the notifications.

1. Go to <https://api.slack.com/apps>.
2. Create a new app from scratch.
3. Open **OAuth & Permissions**.
4. Add these Bot Token Scopes:
   - `chat:write`
   - `chat:write.public`
   - `chat:write.customize`
   - `users:read.email` only if you want the app to resolve Slack users from their email address automatically.
5. Install the app to the workspace.
6. Copy the **Bot User OAuth Token**. It starts with `xoxb-`.
7. Invite the bot to the destination channel:

```text
/invite @Your Slack App Name
```

Use channel IDs instead of names if possible. In Slack, open the channel, copy its link, and use the final ID-like part such as `C1234567890`.

## 3. Add Environment Variables

What these values do: they keep the Slack token, channel IDs, lookup behavior, timezone, and reminder times outside the code. The PHP files read these values through `config('services.slack...')`, so local, staging, and production can use different Slack channels without changing the implementation.

Add these to `.env`:

```dotenv
SLACK_BOT_USER_OAUTH_TOKEN=xoxb-your-token-here
SLACK_BOT_USER_DEFAULT_CHANNEL=C1234567890
SLACK_ASSIGNMENT_CHANNEL=C1234567890
SLACK_PARTIAL_APPROVAL_CHANNEL=C1234567890
SLACK_PARTIAL_APPROVAL_DELAY_MINUTES=30
SLACK_AUDIT_CHANNEL=C1234567890
SLACK_REMINDER_CHANNEL=C1234567890
SLACK_LOOKUP_USERS_BY_EMAIL=false
SLACK_REMINDER_TIMEZONE=Africa/Johannesburg
SLACK_REMINDER_TIMES=09:00,13:00,16:00
```

Add the same keys with blank values to `.env.example`:

```dotenv
SLACK_BOT_USER_OAUTH_TOKEN=
SLACK_BOT_USER_DEFAULT_CHANNEL=
SLACK_ASSIGNMENT_CHANNEL=
SLACK_PARTIAL_APPROVAL_CHANNEL=
SLACK_PARTIAL_APPROVAL_DELAY_MINUTES=30
SLACK_AUDIT_CHANNEL=
SLACK_REMINDER_CHANNEL=
SLACK_LOOKUP_USERS_BY_EMAIL=false
SLACK_REMINDER_TIMEZONE=Africa/Johannesburg
SLACK_REMINDER_TIMES=09:00,13:00,16:00
```

Set the assignment, partial approval, audit, and reminder channels to the same Slack channel if you want one shared operational feed. If `SLACK_LOOKUP_USERS_BY_EMAIL=false`, you will enter each user's Slack member ID manually in Filament. This is the safer first rollout because it does not require the `users:read.email` Slack scope.

## 4. Update `config/services.php`

What this code does: it converts the raw `.env` values into one Laravel config structure. The rest of the app can then ask for `services.slack.channels.assignments`, `services.slack.channels.partial_approvals`, `services.slack.channels.audits`, or `services.slack.channels.reminders` instead of reading environment variables directly.

Replace the existing `slack` block in `config/services.php` with this:

```php
'slack' => [
    'notifications' => [
        'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
        'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
    ],

    'channels' => [
        'assignments' => env('SLACK_ASSIGNMENT_CHANNEL', env('SLACK_BOT_USER_DEFAULT_CHANNEL')),
        'partial_approvals' => env('SLACK_PARTIAL_APPROVAL_CHANNEL') ?: env('SLACK_ASSIGNMENT_CHANNEL') ?: env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        'audits' => env('SLACK_AUDIT_CHANNEL', env('SLACK_BOT_USER_DEFAULT_CHANNEL')),
        'reminders' => env('SLACK_REMINDER_CHANNEL', env('SLACK_AUDIT_CHANNEL', env('SLACK_BOT_USER_DEFAULT_CHANNEL'))),
    ],

        'lookup_users_by_email' => env('SLACK_LOOKUP_USERS_BY_EMAIL', false),
        'partial_approval_delay_minutes' => (int) env('SLACK_PARTIAL_APPROVAL_DELAY_MINUTES', 30),
        'reminder_timezone' => env('SLACK_REMINDER_TIMEZONE', 'Africa/Johannesburg'),
    'reminder_times' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('SLACK_REMINDER_TIMES', '09:00,13:00,16:00'))
    ))),
],
```

Then clear cached config whenever you change `.env`:

```bash
php artisan config:clear
```

## 5. Add Slack Fields To Users And Assignments

This migration is additive. It keeps the existing assignment email flow, CSV files, assignment items, and assignment logs intact. The new fields only add Slack routing and reminder state.

The backfill at the end is important for this existing project: old assignments that already have `sent_at` are marked `completed`, so the new Slack reminder report does not treat historical email assignments as newly pending work.

Create the migration:

```bash
php artisan make:migration add_slack_fields_to_users_and_new_product_draft_assignments
```

Replace the generated file with this:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (!Schema::hasColumn('users', 'slack_user_id')) {
                $table->string('slack_user_id', 32)->nullable()->after('email');
            }

            if (!Schema::hasColumn('users', 'slack_notifications_enabled')) {
                $table->boolean('slack_notifications_enabled')->default(true)->after('slack_user_id');
            }
        });

        Schema::table('new_product_draft_assignments', function (Blueprint $table): void {
            if (!Schema::hasColumn('new_product_draft_assignments', 'assigned_user_ids')) {
                $table->json('assigned_user_ids')->nullable()->after('cc_emails');
            }

            if (!Schema::hasColumn('new_product_draft_assignments', 'notification_channel')) {
                $table->string('notification_channel', 128)->nullable()->after('assigned_user_ids');
            }

            if (!Schema::hasColumn('new_product_draft_assignments', 'work_status')) {
                $table->string('work_status', 20)->default('open')->after('status');
            }

            if (!Schema::hasColumn('new_product_draft_assignments', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('sent_at');
            }

            if (!Schema::hasColumn('new_product_draft_assignments', 'last_slack_notified_at')) {
                $table->timestamp('last_slack_notified_at')->nullable()->after('completed_at');
            }
        });

        DB::table('new_product_draft_assignments')
            ->whereNotNull('sent_at')
            ->where(function ($query): void {
                $query
                    ->whereNull('work_status')
                    ->orWhere('work_status', 'open');
            })
            ->update([
                'work_status' => 'completed',
                'completed_at' => DB::raw('sent_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('new_product_draft_assignments', function (Blueprint $table): void {
            foreach ([
                'assigned_user_ids',
                'notification_channel',
                'work_status',
                'completed_at',
                'last_slack_notified_at',
            ] as $column) {
                if (Schema::hasColumn('new_product_draft_assignments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            foreach (['slack_user_id', 'slack_notifications_enabled'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
```

Run it:

```bash
php artisan migrate
```

## 6. Update `app/Models/User.php`

What this code does: it makes each existing application user Slack-aware. The model can store a Slack member ID, turn Slack notifications on or off for that user, and tell Laravel which Slack channel should be used when the user receives a Slack notification.

Add this import:

```php
use Illuminate\Notifications\Notification as LaravelNotification;
```

Add these fields to `$fillable`:

```php
'slack_user_id',
'slack_notifications_enabled',
```

Add this cast inside `casts()`:

```php
'slack_notifications_enabled' => 'boolean',
```

Add this method to the class:

```php
public function routeNotificationForSlack(LaravelNotification $notification): mixed
{
    return config('services.slack.channels.assignments');
}
```

Slack mentions require a Slack user ID, not a display name. The message format is `<@U012AB3CD>`, and Slack turns that into a real mention.

## 7. Update `app/Models/NewProductDraftAssignment.php`

What this code does: it lets the existing assignment record carry Slack-specific state without removing the old email/CSV fields. This is how the app knows who the assignment is waiting on, where it was sent, whether it is still open, and when Slack was last notified.

Add these fields to `$fillable`:

```php
'assigned_user_ids',
'notification_channel',
'work_status',
'completed_at',
'last_slack_notified_at',
```

Add these casts:

```php
'assigned_user_ids' => 'array',
'completed_at' => 'datetime',
'last_slack_notified_at' => 'datetime',
```

## 8. Show Slack IDs In Filament Users

What this code does: it exposes the new Slack fields in the Filament user screen so you can attach Slack member IDs to existing live users manually. This is useful when the team is small and you do not want to rely on a seeder or Slack email lookup.

In `app/Filament/Resources/UserResource.php`, add these form fields after the existing `email` field:

```php
Forms\Components\TextInput::make('slack_user_id')
    ->label('Slack Member ID')
    ->helperText('Example: U012AB3CD. In Slack, open the user profile menu and choose Copy member ID.')
    ->maxLength(32),
Forms\Components\Toggle::make('slack_notifications_enabled')
    ->label('Slack Notifications')
    ->default(true),
```

Add this table column after the existing `email` column:

```php
Tables\Columns\TextColumn::make('slack_user_id')
    ->label('Slack ID')
    ->searchable()
    ->toggleable(),
```

Now you can edit each user in Filament and store their Slack member ID.

## 9. Add A Slack User Resolver

What this file does: `SlackUserResolver` is the reusable helper that turns an app user or email address into Slack-safe text. It is deliberately separate from assignment code so the same mention logic can be reused later for audits, approvals, sync errors, image backup failures, or any other task.

The important behavior in this file:

- `mentionForUser()` returns `<@SLACK_USER_ID>` when the user has Slack enabled.
- `mentionOrEmailForEmail()` tries to find a local user by email, then falls back to a plain escaped email if no mention is possible.
- `lookupUserIdByEmail()` optionally asks Slack for a member ID when `SLACK_LOOKUP_USERS_BY_EMAIL=true`.
- `escape()` prevents names, titles, and emails from breaking Slack message formatting.

Create `app/Services/SlackUserResolver.php`:

```php
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
```

## 10. Add The Immediate Assignment Slack Notification

What this file does: this notification builds the actual Slack message for one assignment. It does not create assignments and it does not decide when to send; it only loads the assignment, turns assignees into Slack mentions, summarizes the selected work columns and draft records, and returns a Slack Block Kit message.

Why it is separate: keeping the message-building code in a notification class means the same message can be sent from a job, a command, or another workflow later without duplicating the Slack formatting.

Create `app/Notifications/NewProductDraftAssignmentSlackNotification.php`:

```php
<?php

namespace App\Notifications;

use App\Filament\Resources\NewProductDraftResource;
use App\Models\NewProductDraftAssignment;
use App\Models\User;
use App\Services\NewProductDraftAssignmentService;
use App\Services\SlackUserResolver;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Str;

class NewProductDraftAssignmentSlackNotification extends Notification
{
    public function __construct(
        private readonly int $assignmentId,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $assignment = NewProductDraftAssignment::query()
            ->with(['items', 'sender'])
            ->find($this->assignmentId);

        if (!$assignment instanceof NewProductDraftAssignment) {
            return (new SlackMessage)->text("Assignment #{$this->assignmentId} could not be loaded.");
        }

        $resolver = app(SlackUserResolver::class);
        $columnService = app(NewProductDraftAssignmentService::class);
        $mentions = $this->assignmentMentions($assignment, $resolver);
        $subject = $resolver->escape($assignment->subject ?: 'New product draft assignment');
        $body = $resolver->escape($assignment->body ?? '');

        $selectedColumns = collect($assignment->selected_columns ?? [])
            ->map(fn (string $key): string => $columnService->labelForColumn($key))
            ->implode(', ');

        $draftLines = $assignment->items
            ->take(8)
            ->map(function ($item) use ($resolver): string {
                $title = $resolver->escape($item->title ?: 'Untitled draft');
                $handle = $resolver->escape($item->handle ?: 'no-handle');

                return "- {$title} ({$handle})";
            })
            ->implode("\n");

        if ($assignment->items->count() > 8) {
            $remaining = $assignment->items->count() - 8;
            $draftLines .= "\n- plus {$remaining} more";
        }

        $draftsUrl = NewProductDraftResource::getUrl('index');
        if (!str_starts_with($draftsUrl, 'http')) {
            $draftsUrl = url($draftsUrl);
        }

        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'New product draft assignment',
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => trim("{$mentions}\n*{$subject}*"),
                ],
            ],
            [
                'type' => 'section',
                'fields' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Assignment ID:*\n#{$assignment->id}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Drafts:*\n{$assignment->items->count()}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Columns:*\n" . Str::limit($resolver->escape($selectedColumns ?: 'Not specified'), 900),
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Sent by:*\n" . $resolver->escape($assignment->sender?->name ?: $assignment->from_name ?: 'Shopify Editor'),
                    ],
                ],
            ],
        ];

        if ($body !== '') {
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Message:*\n" . Str::limit($body, 1800),
                ],
            ];
        }

        if ($draftLines !== '') {
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Drafts to update:*\n" . Str::limit($draftLines, 2500),
                ],
            ];
        }

        $blocks[] = [
            'type' => 'actions',
            'elements' => [
                [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Open Drafts',
                    ],
                    'url' => $draftsUrl,
                ],
            ],
        ];

        return (new SlackMessage)
            ->text("New product draft assignment #{$assignment->id}")
            ->usingBlockKitTemplate(json_encode(['blocks' => $blocks], JSON_UNESCAPED_SLASHES) ?: '{"blocks":[]}');
    }

    private function assignmentMentions(NewProductDraftAssignment $assignment, SlackUserResolver $resolver): string
    {
        $assignedUserIds = collect($assignment->assigned_user_ids ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->values()
            ->all();

        if ($assignedUserIds !== []) {
            $mentions = User::query()
                ->whereIn('id', $assignedUserIds)
                ->get()
                ->map(fn (User $user): string => $resolver->mentionForUser($user) ?? $resolver->mentionOrEmailForEmail($user->email))
                ->filter()
                ->values();

            if ($mentions->isNotEmpty()) {
                return $mentions->implode(' ');
            }
        }

        return collect($assignment->to_emails ?? [])
            ->map(fn (string $email): string => $resolver->mentionOrEmailForEmail($email))
            ->filter()
            ->implode(' ');
    }
}
```

## 11. Add The Assignment Slack Job

What this file does: this queued job sends the assignment notification to Slack. It loads the assignment by ID, picks the correct Slack channel, sends `NewProductDraftAssignmentSlackNotification`, then records success or failure back on the assignment through `NewProductDraftAssignmentService`.

Why it is a job: Slack is an external API call, so it should not slow down the Filament bulk action. Queueing it also gives Laravel a chance to retry when Slack or the network has a temporary failure.

Create `app/Jobs/SendNewProductDraftAssignmentSlackJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\NewProductDraftAssignment;
use App\Notifications\NewProductDraftAssignmentSlackNotification;
use App\Services\NewProductDraftAssignmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

class SendNewProductDraftAssignmentSlackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 3;

    public function __construct(
        private readonly int $assignmentId,
    ) {
    }

    public function handle(NewProductDraftAssignmentService $service): void
    {
        $assignment = NewProductDraftAssignment::query()->find($this->assignmentId);

        if (!$assignment instanceof NewProductDraftAssignment) {
            return;
        }

        $channel = trim((string) ($assignment->notification_channel ?: config('services.slack.channels.assignments')));

        if ($channel === '') {
            throw new RuntimeException('Slack assignment channel is not configured.');
        }

        try {
            Notification::route('slack', $channel)
                ->notify(new NewProductDraftAssignmentSlackNotification($assignment->id));

            $service->markSlackSent($assignment->fresh() ?? $assignment, $channel);
        } catch (\Throwable $e) {
            $service->markSlackFailed($assignment->fresh() ?? $assignment, $e);

            throw $e;
        }
    }
}
```

## 12. Update `NewProductDraftAssignmentService`

What these changes do: they extend the existing assignment creation flow so it can accept assigned application users while still preserving the old email-based fields, CSV generation, assignment items, and logs. The service becomes the single place that records assignment lifecycle events like Slack sent, Slack failed, and completed.

In `app/Services/NewProductDraftAssignmentService.php`, update `createAssignment()` so it accepts users and still supports email fallback.

Find this section:

```php
$contextColumns = $this->normalizeContextColumns($data['context_columns'] ?? []);
$toEmails = $this->parseEmails($data['to_emails'] ?? null);
if (empty($toEmails)) {
    throw new \InvalidArgumentException('Provide at least one recipient email address.');
}

$ccEmails = $this->parseEmails($data['cc_emails'] ?? null);
```

Replace it with:

What this replacement does: it reads selected user IDs from the Filament action, confirms those users are active, uses their emails as fallback recipients, and stores the Slack channel that should receive the assignment notification.

```php
$contextColumns = $this->normalizeContextColumns($data['context_columns'] ?? []);
$assignedUserIds = $this->parseUserIds($data['assigned_user_ids'] ?? []);
$assignedUsers = $assignedUserIds === []
    ? collect()
    : User::query()
        ->whereIn('id', $assignedUserIds)
        ->where('is_active', true)
        ->get(['id', 'email']);

$assignedUserIds = $assignedUsers
    ->pluck('id')
    ->map(fn ($id): int => (int) $id)
    ->values()
    ->all();

$toEmails = $this->parseEmails($data['to_emails'] ?? null);
if ($toEmails === [] && $assignedUsers->isNotEmpty()) {
    $toEmails = $assignedUsers
        ->pluck('email')
        ->filter()
        ->map(fn (string $email): string => strtolower(trim($email)))
        ->unique()
        ->values()
        ->all();
}

if ($toEmails === []) {
    throw new \InvalidArgumentException('Select at least one assigned user or provide one fallback email address.');
}

$ccEmails = $this->parseEmails($data['cc_emails'] ?? null);
$notificationChannel = $this->nullIfEmpty($data['notification_channel'] ?? null)
    ?? config('services.slack.channels.assignments');
```

In the `DB::transaction(function () use (...)` list, add:

What this small change does: it makes the new variables available inside the existing database transaction that creates the assignment, its items, CSV, and logs.

```php
$assignedUserIds,
$notificationChannel,
```

In the `NewProductDraftAssignment::create([...])` array, add:

What this small change does: it stores the Slack owner list, target channel, and initial `open` work status on the assignment record at creation time.

```php
'assigned_user_ids' => $assignedUserIds,
'notification_channel' => $notificationChannel,
'work_status' => 'open',
```

In the first assignment log metadata array, add:

What this small change does: it records the Slack assignment context in the assignment log so someone can later see who was assigned and which channel was used.

```php
'assigned_user_ids' => $assignedUserIds,
'notification_channel' => $notificationChannel,
```

Add these methods anywhere inside the service class:

What these methods do: they centralize status updates after Slack sends, fails, or the work is completed. The rest of the app should call these methods instead of manually updating assignment columns in multiple places.

```php
public function markSlackSent(NewProductDraftAssignment $assignment, ?string $channel = null): void
{
    $assignment->update([
        'status' => 'sent',
        'sent_at' => now(),
        'last_slack_notified_at' => now(),
        'notification_channel' => $channel ?: $assignment->notification_channel,
        'error_message' => null,
    ]);

    $this->log(
        $assignment,
        'slack_sent',
        $assignment->sent_by,
        'Assignment Slack notification sent.',
        [
            'channel' => $channel ?: $assignment->notification_channel,
            'assigned_user_ids' => $assignment->assigned_user_ids,
        ]
    );
}

public function markSlackFailed(NewProductDraftAssignment $assignment, \Throwable $e): void
{
    $assignment->update([
        'status' => 'failed',
        'error_message' => $e->getMessage(),
    ]);

    $this->log(
        $assignment,
        'slack_failed',
        $assignment->sent_by,
        'Assignment Slack notification failed.',
        [
            'error' => $e->getMessage(),
            'assigned_user_ids' => $assignment->assigned_user_ids,
        ]
    );
}

public function markCompleted(NewProductDraftAssignment $assignment, ?User $user = null): void
{
    $assignment->update([
        'work_status' => 'completed',
        'completed_at' => now(),
    ]);

    $this->log(
        $assignment,
        'completed',
        $user?->id,
        'Assignment marked completed.'
    );
}
```

Add this private helper near `parseEmails()`:

What this helper does: it normalizes the multi-select user IDs from Filament into clean unique integers before they are used in database queries or stored in JSON.

```php
/**
 * @return array<int, int>
 */
private function parseUserIds(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    return array_values(array_unique(array_filter(array_map(
        fn ($id): int => (int) $id,
        $value
    ))));
}
```

## 13. Add The Assignment Slack Bulk Action

What this code does: it adds a Filament bulk action for "select application users and notify them in Slack" while leaving the existing email assignment action available during rollout. It still keeps fallback email fields so the existing assignment service and CSV/email assumptions do not break.

In `app/Filament/Resources/NewProductDraftResource.php`, add this import:

```php
use App\Jobs\SendNewProductDraftAssignmentSlackJob;
use App\Models\User;
```

The file already imports `Select`, `TextInput`, `Textarea`, `CheckboxList`, and `BulkAction`.

Find the existing bulk action:

```php
BulkAction::make('sendAssignmentEmail')
```

Add this Slack action directly after that email bulk action:

What this bulk action does when a user submits it: it creates the assignment using the existing service, stores the selected assignees, queues the Slack job, and shows a Filament success or failure notification.

```php
BulkAction::make('sendAssignmentSlack')
    ->label('Assign in Slack')
    ->icon('heroicon-o-chat-bubble-left-right')
    ->color('info')
    ->form([
        Select::make('assigned_user_ids')
            ->label('Assigned Users')
            ->multiple()
            ->searchable()
            ->preload()
            ->required()
            ->options(fn (): array => User::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->mapWithKeys(fn (User $user): array => [
                    $user->id => trim(($user->name ?: $user->email) . ' <' . $user->email . '>'),
                ])
                ->all())
            ->helperText('These users will be mentioned in the Slack channel. Add their Slack Member ID in User Management.'),
        Textarea::make('to_emails')
            ->label('Fallback Emails')
            ->rows(2)
            ->helperText('Optional. Used only when a selected user has no Slack ID or when email lookup is enabled.'),
        TextInput::make('from_name')
            ->label('From Name')
            ->default(fn (): ?string => Auth::user()?->name),
        TextInput::make('from_email')
            ->label('From Email')
            ->email()
            ->required()
            ->default(fn (): ?string => Auth::user()?->email),
        TextInput::make('subject')
            ->label('Subject')
            ->required()
            ->maxLength(255)
            ->default('New product draft assignment'),
        Textarea::make('body')
            ->label('Message')
            ->rows(4)
            ->helperText('Optional note shown in Slack.'),
        CheckboxList::make('context_columns')
            ->label('Reference Columns')
            ->options(fn (): array => app(NewProductDraftAssignmentService::class)->contextColumnOptions())
            ->columns(2)
            ->default(['title', 'sku', 'vendor', 'type'])
            ->helperText('Handle is always included as the identifier.'),
        CheckboxList::make('selected_columns')
            ->label('Work Columns')
            ->required()
            ->options(fn (): array => app(NewProductDraftAssignmentService::class)->workColumnOptions())
            ->columns(2)
            ->helperText('Choose the columns the assignee should work on.'),
    ])
    ->action(function ($records, array $data, NewProductDraftAssignmentService $service): void {
        try {
            $data['notification_channel'] = config('services.slack.channels.assignments');

            $assignment = $service->createAssignment($records, $data, Auth::user());
            SendNewProductDraftAssignmentSlackJob::dispatch($assignment->id);

            self::sendNotification(Notification::make()
                ->title('Slack assignment queued')
                ->body("Assignment #{$assignment->id} was recorded and the Slack notification has been queued.")
                ->success()
            );
        } catch (\Throwable $e) {
            self::sendNotification(Notification::make()
                ->title('Assignment failed')
                ->body($e->getMessage())
                ->danger()
            );
        }
    })
    ->deselectRecordsAfterCompletion(),
```

After Slack is proven in production, you can hide or remove the old email bulk action.

## 14. Add The Three-Times-Per-Day Reminder Notification

What this file does: this notification builds the scheduled Slack digest. It counts and lists open assignments, complementary-product Shopify gaps, product errors, image errors, and failed queue jobs. A complementary gap means Shopify has fewer than 3 active and sellable complementary products for an audited product. The message includes buttons back to the relevant Filament pages.

Why it exists separately from the immediate assignment notification: the immediate notification is about one handoff; this reminder is about everything still pending later in the day.

Create `app/Notifications/PendingWorkSlackReminderNotification.php`:

```php
<?php

namespace App\Notifications;

use App\Filament\Resources\NewProductDraftResource;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ShopifyAuditResource;
use App\Models\Image;
use App\Models\NewProductDraftAssignment;
use App\Models\Product;
use App\Models\ShopifyAudit;
use App\Models\User;
use App\Services\ComplementaryProductAuditService;
use App\Services\SlackUserResolver;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PendingWorkSlackReminderNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $resolver = app(SlackUserResolver::class);

        $openAssignmentCount = NewProductDraftAssignment::query()
            ->where('work_status', 'open')
            ->count();

        $assignments = NewProductDraftAssignment::query()
            ->where('work_status', 'open')
            ->latest()
            ->limit(10)
            ->get();

        $complementaryTarget = ComplementaryProductAuditService::SHOPIFY_TARGET_COUNT;

        $complementaryGapCount = ShopifyAudit::query()
            ->where('audit_type', ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS)
            ->where('needs_attention', true)
            ->where('shopify_valid_count', '<', $complementaryTarget)
            ->count();

        $complementaryGaps = ShopifyAudit::query()
            ->with('product')
            ->where('audit_type', ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS)
            ->where('needs_attention', true)
            ->where('shopify_valid_count', '<', $complementaryTarget)
            ->orderBy('shopify_valid_count')
            ->orderByDesc('last_checked_at')
            ->limit(10)
            ->get();

        $productErrorCount = Product::query()
            ->where('has_errors', true)
            ->count();

        $imageErrorCount = Image::query()
            ->where(function ($query): void {
                $query
                    ->whereNotNull('backup_error')
                    ->orWhereNotNull('shopify_image_sync_error');
            })
            ->count();

        $failedJobCount = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->count()
            : 0;

        $assignmentLines = $assignments
            ->map(fn (NewProductDraftAssignment $assignment): string => $this->assignmentLine($assignment, $resolver))
            ->filter()
            ->implode("\n");

        $complementaryGapLines = $complementaryGaps
            ->map(fn (ShopifyAudit $audit): string => $this->complementaryGapLine($audit, $resolver))
            ->filter()
            ->implode("\n");

        $draftsUrl = $this->absoluteUrl(NewProductDraftResource::getUrl('index'));
        $auditUrl = $this->absoluteUrl(ShopifyAuditResource::getUrl('index'));
        $productUrl = $this->absoluteUrl(ProductResource::getUrl('index'));

        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Shopify editor pending work',
                ],
            ],
            [
                'type' => 'section',
                'fields' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Open assignments:*\n{$openAssignmentCount}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Complementary gaps:*\n{$complementaryGapCount}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Product errors:*\n{$productErrorCount}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Image errors:*\n{$imageErrorCount}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Failed queue jobs:*\n{$failedJobCount}",
                    ],
                ],
            ],
        ];

        if ($assignmentLines !== '') {
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Assignments waiting on people:*\n" . Str::limit($assignmentLines, 2800),
                ],
            ];
        }

        if ($complementaryGapLines !== '') {
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Products below Shopify complementary target ({$complementaryTarget} active/sellable):*\n"
                        . Str::limit($complementaryGapLines, 2800),
                ],
            ];

            if ($complementaryGapCount > $complementaryGaps->count()) {
                $remaining = $complementaryGapCount - $complementaryGaps->count();

                $blocks[] = [
                    'type' => 'context',
                    'elements' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => "Showing the first {$complementaryGaps->count()} complementary gap(s); open audits for {$remaining} more.",
                        ],
                    ],
                ];
            }
        }

        $blocks[] = [
            'type' => 'actions',
            'elements' => [
                [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Open Drafts',
                    ],
                    'url' => $draftsUrl,
                ],
                [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Open Audits',
                    ],
                    'url' => $auditUrl,
                ],
                [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Open Products',
                    ],
                    'url' => $productUrl,
                ],
            ],
        ];

        return (new SlackMessage)
            ->text('Shopify editor pending work reminder')
            ->usingBlockKitTemplate(json_encode(['blocks' => $blocks], JSON_UNESCAPED_SLASHES) ?: '{"blocks":[]}');
    }

    private function assignmentLine(NewProductDraftAssignment $assignment, SlackUserResolver $resolver): string
    {
        $mentions = $this->assignmentMentions($assignment, $resolver);
        $subject = $resolver->escape($assignment->subject ?: 'New product draft assignment');
        $age = $assignment->created_at?->diffForHumans() ?? 'unknown age';

        return "- #{$assignment->id} {$mentions} {$subject} ({$age})";
    }

    private function complementaryGapLine(ShopifyAudit $audit, SlackUserResolver $resolver): string
    {
        $product = $audit->product;
        $title = $resolver->escape($product?->title ?: 'Product #' . $audit->product_id);
        $handle = $resolver->escape($product?->handle ?: 'no-handle');
        $productUrl = $product instanceof Product
            ? $this->absoluteUrl(ProductResource::getUrl('edit', ['record' => $product]))
            : null;
        $label = $productUrl ? "<{$productUrl}|{$title}>" : $title;

        $target = ComplementaryProductAuditService::SHOPIFY_TARGET_COUNT;
        $localTarget = ComplementaryProductAuditService::LOCAL_TARGET_COUNT;
        $shopifyValid = (int) ($audit->shopify_valid_count ?? 0);
        $shopifyCurrent = (int) ($audit->shopify_current_count ?? 0);
        $localValid = (int) ($audit->local_valid_count ?? 0);
        $missing = max(0, $target - $shopifyValid);

        $parts = [
            "Shopify {$shopifyValid}/{$target} active/sellable",
            "local {$localValid}/{$localTarget} eligible backups",
        ];

        if ($shopifyCurrent !== $shopifyValid) {
            $parts[] = "{$shopifyCurrent} Shopify ref(s) total";
        }

        if ($missing > 0) {
            $parts[] = 'needs ' . $missing . ' more active/sellable Shopify ' . Str::plural('ref', $missing);
        }

        return "- {$label} ({$handle}) - " . implode('; ', $parts);
    }

    private function assignmentMentions(NewProductDraftAssignment $assignment, SlackUserResolver $resolver): string
    {
        $assignedUserIds = collect($assignment->assigned_user_ids ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->values()
            ->all();

        if ($assignedUserIds !== []) {
            $mentions = User::query()
                ->whereIn('id', $assignedUserIds)
                ->get()
                ->map(fn (User $user): string => $resolver->mentionForUser($user) ?? $resolver->mentionOrEmailForEmail($user->email))
                ->filter()
                ->values();

            if ($mentions->isNotEmpty()) {
                return $mentions->implode(' ');
            }
        }

        return collect($assignment->to_emails ?? [])
            ->map(fn (string $email): string => $resolver->mentionOrEmailForEmail($email))
            ->filter()
            ->implode(' ');
    }

    private function absoluteUrl(string $url): string
    {
        return str_starts_with($url, 'http') ? $url : url($url);
    }
}
```

## 15. Add The Reminder Command And Schedule

What this code does: it creates an Artisan command that sends the pending-work digest to Slack, then schedules that command to run at the configured reminder times. This is the part that turns the reminder notification into the planned three-times-per-day Slack report.

In `routes/console.php`, add these imports at the top:

```php
use App\Models\NewProductDraftAssignment;
use App\Models\ShopifyAudit;
use App\Notifications\PendingWorkSlackReminderNotification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
```

Add this command and schedule near the top of the file, after the existing `inspire` command:

What the command does when it runs: it sends `PendingWorkSlackReminderNotification`, updates `last_slack_notified_at` for open assignments, updates `last_notified_at` for flagged audits, and prints the Slack channel it used.

```php
Artisan::command('slack:pending-work-reminder', function (): int {
    $channel = trim((string) config('services.slack.channels.reminders'));

    if ($channel === '') {
        $this->error('SLACK_REMINDER_CHANNEL or SLACK_BOT_USER_DEFAULT_CHANNEL is not configured.');

        return self::FAILURE;
    }

    NotificationFacade::route('slack', $channel)
        ->notify(new PendingWorkSlackReminderNotification());

    NewProductDraftAssignment::query()
        ->where('work_status', 'open')
        ->update(['last_slack_notified_at' => now()]);

    ShopifyAudit::query()
        ->where('audit_type', ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS)
        ->where('status', ShopifyAudit::STATUS_FLAGGED)
        ->update(['last_notified_at' => now()]);

    $this->info("Slack pending-work reminder sent to {$channel}.");

    return self::SUCCESS;
})->purpose('Send the Slack report for open assignments, audit issues, and errors.');

Artisan::command('slack:assignment-complete {assignment_id}', function (string $assignment_id): int {
    $assignment = NewProductDraftAssignment::query()->find((int) $assignment_id);

    if (!$assignment instanceof NewProductDraftAssignment) {
        $this->error("Assignment #{$assignment_id} was not found.");

        return self::FAILURE;
    }

    app(\App\Services\NewProductDraftAssignmentService::class)->markCompleted($assignment);

    $this->info("Assignment #{$assignment->id} marked completed.");

    return self::SUCCESS;
})->purpose('Mark a Slack assignment completed so reminders stop including it.');

foreach (config('services.slack.reminder_times', []) as $time) {
    Schedule::command('slack:pending-work-reminder')
        ->dailyAt($time)
        ->timezone(config('services.slack.reminder_timezone', 'Africa/Johannesburg'))
        ->withoutOverlapping()
        ->name('slack-pending-work-reminder-' . str_replace(':', '', (string) $time));
}
```

The default reminder schedule is 09:00, 13:00, and 16:00 in `Africa/Johannesburg`. Change `SLACK_REMINDER_TIMES` if the team wants different reminder times.

## 16. Optional: Replace The Current Audit Email With Slack

`app/Services/ComplementaryProductMaintenanceService.php` currently sends a hard-coded email alert when the daily complementary-products audit finds issues. You can keep that email during the first Slack test, then replace it once the channel reports are working.

What this optional change does: it keeps the existing audit calculation exactly where it is, but makes Slack the preferred alert delivery channel when `SLACK_AUDIT_CHANNEL` is configured. The hard-coded email remains a fallback if Slack is not configured.

Add these imports to `ComplementaryProductMaintenanceService.php`:

```php
use App\Notifications\PendingWorkSlackReminderNotification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
```

Keep the existing email imports if you want email fallback when Slack is not configured.

Find this block in `runDailyCheck()`:

```php
if ($alerts !== []) {
    $recipientEmails = $this->recipientEmails();
    if ($recipientEmails !== []) {
        Mail::to($recipientEmails)->send(new ComplementaryProductMaintenanceAlertMail($alerts));
        $notified = count($alerts);

        ShopifyAudit::query()
            ->where('audit_type', ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS)
            ->where('needs_attention', true)
            ->update([
                'last_notified_at' => now(),
            ]);
    }
}
```

Replace it with this:

What this replacement does: when the daily audit finds alerts, it posts the pending-work Slack reminder to the audit channel and marks the flagged audits as notified. If no Slack audit channel is configured, it keeps the existing hard-coded email fallback.

```php
if ($alerts !== []) {
    $channel = trim((string) config('services.slack.channels.audits'));

    if ($channel !== '') {
        NotificationFacade::route('slack', $channel)
            ->notify(new PendingWorkSlackReminderNotification());

        $notified = count($alerts);

        ShopifyAudit::query()
            ->where('audit_type', ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS)
            ->where('needs_attention', true)
            ->update([
                'last_notified_at' => now(),
            ]);
    } else {
        $recipientEmails = $this->recipientEmails();
        if ($recipientEmails === []) {
            return [
                'checked' => $checked,
                'recorded' => $recorded,
                'healthy' => $healthy,
                'flagged' => $flagged,
                'notified' => $notified,
            ];
        }

        Mail::to($recipientEmails)->send(new ComplementaryProductMaintenanceAlertMail($alerts));
        $notified = count($alerts);

        ShopifyAudit::query()
            ->where('audit_type', ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS)
            ->where('needs_attention', true)
            ->update([
                'last_notified_at' => now(),
            ]);
    }
}
```

## 17. Add Delayed Slack Reminders For Partial Approvals

What this feature does: when someone requests partial approval for products, the request records are still created by the existing partial approval flow. The only delivery change is that the old immediate email job is removed and replaced with one delayed Slack job. After 30 minutes, that job looks for all still-pending partial approval requests for the same target approver and sends one grouped Slack message that mentions the approver.

Why the 30-minute delay matters: if a requester creates several partial approval requests close together, Slack receives one useful reminder instead of many separate pings. If the approver handles the request before the 30 minutes pass, the job finds no pending work and sends nothing.

Why this uses the existing assignment Slack pieces: `SlackUserResolver` already knows how to turn application users into Slack mentions, so partial approvals reuse that same user-to-Slack mapping instead of inventing a second mapping system.

### Update `app/Services/ProductPartialApprovalService.php`

What this code does: it keeps the existing request creation logic and only changes what happens after requests are created. Instead of queueing the old email job immediately, it queues a Slack reminder job with a 30-minute delay.

Replace the old email job import:

```php
use App\Jobs\SendProductPartialApprovalRequestEmailJob;
```

with:

```php
use App\Jobs\SendProductPartialApprovalSlackReminderJob;
```

Then replace this old dispatch block:

```php
if ($summary['requested'] > 0 && $summary['request_batch_id']) {
    SendProductPartialApprovalRequestEmailJob::dispatch($summary['request_batch_id']);
}
```

with:

```php
if ($summary['requested'] > 0 && $summary['request_batch_id']) {
    $delayMinutes = max(1, (int) config('services.slack.partial_approval_delay_minutes', 30));

    SendProductPartialApprovalSlackReminderJob::dispatch($targetApproverId)
        ->delay(now()->addMinutes($delayMinutes));
}
```

### Create `app/Jobs/SendProductPartialApprovalSlackReminderJob.php`

What this file does: this queued job waits for Laravel's queue delay, then re-checks the database. It sends Slack only for requests that are still pending and still match the same target approver. It is unique per approver, so repeated requests for the same person within the delay window group into one reminder.

```php
<?php

namespace App\Jobs;

use App\Notifications\ProductPartialApprovalSlackReminderNotification;
use App\Services\ProductPartialApprovalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

class SendProductPartialApprovalSlackReminderJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 3;
    public int $uniqueFor = 2400;

    public function __construct(
        private readonly ?int $targetApproverId,
    ) {
    }

    public function uniqueId(): string
    {
        return 'partial-approval-slack-reminder:' . ($this->targetApproverId ?: 'any');
    }

    public function handle(ProductPartialApprovalService $service): void
    {
        $query = $service->visiblePendingRequestsQuery();

        if ($this->targetApproverId !== null && $this->targetApproverId > 0) {
            $query
                ->where('target_approver_id', $this->targetApproverId)
                ->where('requested_by', '!=', $this->targetApproverId);
        } else {
            $query->whereNull('target_approver_id');
        }

        $requestIds = $query
            ->orderBy('created_at')
            ->limit(25)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($requestIds === []) {
            return;
        }

        $channel = trim((string) config('services.slack.channels.partial_approvals'));

        if ($channel === '') {
            throw new RuntimeException('Slack partial approval channel is not configured.');
        }

        Notification::route('slack', $channel)
            ->notify(new ProductPartialApprovalSlackReminderNotification(
                $this->targetApproverId,
                $requestIds,
            ));
    }
}
```

### Create `app/Notifications/ProductPartialApprovalSlackReminderNotification.php`

What this file does: this notification builds the Slack message. It mentions the target approver when the request was assigned to a specific person, lists the pending products and requested fields, and includes a button back to the partial approval queue.

```php
<?php

namespace App\Notifications;

use App\Filament\Resources\ProductPartialApprovalRequestResource;
use App\Filament\Resources\ProductResource;
use App\Models\ProductPartialApprovalRequest;
use App\Models\User;
use App\Services\ProductPartialApprovalService;
use App\Services\SlackUserResolver;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Str;

class ProductPartialApprovalSlackReminderNotification extends Notification
{
    /**
     * @param array<int, int> $requestIds
     */
    public function __construct(
        private readonly ?int $targetApproverId,
        private readonly array $requestIds,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $resolver = app(SlackUserResolver::class);
        $service = app(ProductPartialApprovalService::class);

        $requests = ProductPartialApprovalRequest::query()
            ->with(['product', 'requester', 'targetApprover'])
            ->whereIn('id', $this->requestIds)
            ->where('status', ProductPartialApprovalRequest::STATUS_PENDING)
            ->orderBy('created_at')
            ->get();

        if ($requests->isEmpty()) {
            return (new SlackMessage)->text('There are no pending partial approval requests.');
        }

        $requestCount = $requests->count();
        $targetApprover = $this->targetApproverId
            ? User::query()->find($this->targetApproverId)
            : null;

        $mention = $targetApprover instanceof User
            ? ($resolver->mentionForUser($targetApprover) ?? $resolver->escape($targetApprover->name ?: $targetApprover->email))
            : 'Any eligible reviewer';

        $queueUrl = $this->absoluteUrl(ProductPartialApprovalRequestResource::getUrl('index'));

        $requestLines = $requests
            ->take(12)
            ->map(function (ProductPartialApprovalRequest $request) use ($resolver, $service): string {
                $product = $request->product;
                $title = $resolver->escape($product?->title ?: 'Product #' . $request->product_id);
                $handle = $resolver->escape($product?->handle ?: 'no-handle');
                $fields = $resolver->escape(implode(', ', $service->requestFieldLabels(
                    is_array($request->scopes) ? $request->scopes : [],
                    is_array($request->core_fields) ? $request->core_fields : [],
                )) ?: 'Selected fields');
                $requester = $resolver->escape($request->requester?->name ?: 'Unknown requester');
                $productUrl = $product ? $this->absoluteUrl(ProductResource::getUrl('edit', ['record' => $product])) : null;
                $label = $productUrl ? "<{$productUrl}|{$title}>" : $title;

                return "- {$label} ({$handle}) - {$fields} - requested by {$requester}";
            })
            ->implode("\n");

        if ($requests->count() > 12) {
            $remaining = $requestCount - 12;
            $requestLines .= "\n- plus {$remaining} more";
        }

        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Partial approvals waiting',
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "{$mention}, there " . ($requestCount === 1 ? 'is' : 'are') . " *{$requestCount}* partial approval " . Str::plural('request', $requestCount) . ' waiting for review.',
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => Str::limit($requestLines, 2800),
                ],
            ],
            [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => [
                            'type' => 'plain_text',
                            'text' => 'Open Partial Approval Queue',
                        ],
                        'url' => $queueUrl,
                    ],
                ],
            ],
        ];

        return (new SlackMessage)
            ->text('Partial approvals waiting for review')
            ->usingBlockKitTemplate(json_encode(['blocks' => $blocks], JSON_UNESCAPED_SLASHES) ?: '{"blocks":[]}');
    }

    private function absoluteUrl(string $url): string
    {
        return str_starts_with($url, 'http') ? $url : url($url);
    }
}
```

### Remove The Old Partial Approval Email Queue

What this cleanup does: it prevents future developers from accidentally reusing the old email-based partial approval notification. Assignment emails can still exist separately; this cleanup is only for partial approval requests.

Delete these files:

```text
app/Jobs/SendProductPartialApprovalRequestEmailJob.php
app/Mail/ProductPartialApprovalRequestMail.php
resources/views/emails/product-partial-approval-request.blade.php
```

Also remove the old `approvalRequestRecipientEmails()` and `userCanReceiveApprovalEmail()` methods from `ProductPartialApprovalService.php` because they only supported that deleted email job.

### Test The Partial Approval Slack Reminder

Make sure these values are set:

```dotenv
SLACK_BOT_USER_OAUTH_TOKEN=xoxb-your-token-here
SLACK_PARTIAL_APPROVAL_CHANNEL=C1234567890
SLACK_PARTIAL_APPROVAL_DELAY_MINUTES=3
```

Then clear config and run a queue worker:

```bash
php artisan config:clear
php artisan queue:work --tries=3 --timeout=120
```

In Filament, request partial approval and choose a specific target approver. That user must have `slack_user_id` filled in and `slack_notifications_enabled=true` on their user record. With `SLACK_PARTIAL_APPROVAL_DELAY_MINUTES=3`, the request will be queued for Slack after about 3 minutes.

After the smoke test, set `SLACK_PARTIAL_APPROVAL_DELAY_MINUTES=30`, clear config, and restart the queue worker.

## 18. Keep The Queue And Scheduler Running

This project already uses `QUEUE_CONNECTION=database`, and `composer dev` starts a queue listener. For local development, run:

```bash
composer dev
```

Or run the pieces separately:

```bash
php artisan serve
php artisan queue:work
php artisan schedule:work
npm run dev
```

For production, keep a queue worker alive with Supervisor, Laravel Forge, or the hosting platform's worker system:

```bash
php artisan queue:work --tries=3 --timeout=120
```

Also add the Laravel scheduler cron on the server:

```cron
* * * * * cd /path-to/shopify-editor && php artisan schedule:run >> /dev/null 2>&1
```

## 19. Test End To End

Clear config and make sure the new migration is applied:

```bash
php artisan config:clear
php artisan migrate
```

Confirm the schedule exists:

```bash
php artisan schedule:list
```

Send the reminder manually:

```bash
php artisan slack:pending-work-reminder
```

Create one assignment from Filament:

1. Go to **Catalog > New Products**.
2. Select one or more drafts.
3. Use the **Assign in Slack** bulk action.
4. Choose the assigned user.
5. Choose the work columns.
6. Submit.
7. Make sure the queue worker is running.
8. Confirm the Slack channel receives the message and the selected user is mentioned.

When the work is finished, mark the assignment complete:

```bash
php artisan slack:assignment-complete 123
```

Replace `123` with the real assignment ID from the Slack message.

Test the delayed partial approval reminder:

1. Make sure the target approver has a Slack member ID on their user record.
2. Request partial approval from Filament and assign it to that target approver.
3. Keep `php artisan queue:work` running.
4. Wait 30 minutes, or temporarily change the delay to one minute for a local smoke test.
5. Confirm Slack receives one grouped message that mentions the approver and links to the partial approval queue.

## 20. Optional: Send Critical Laravel Logs To Slack

This is separate from the bot-token notifications above. Laravel's log Slack channel uses an incoming webhook URL.

What this optional setup does: it sends critical application log entries to Slack using Laravel's logging system. This is for unexpected application failures, not normal assignment reminders.

Create a Slack incoming webhook and add this to `.env`:

```dotenv
LOG_STACK=single,slack
LOG_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/your/webhook/url
LOG_SLACK_USERNAME="Shopify Editor"
LOG_SLACK_EMOJI=":warning:"
LOG_SLACK_LEVEL=critical
```

Then change the `slack` channel in `config/logging.php` so it uses `LOG_SLACK_LEVEL` instead of the global `LOG_LEVEL`:

```php
'slack' => [
    'driver' => 'slack',
    'url' => env('LOG_SLACK_WEBHOOK_URL'),
    'username' => env('LOG_SLACK_USERNAME', 'Laravel Log'),
    'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
    'level' => env('LOG_SLACK_LEVEL', 'critical'),
    'replace_placeholders' => true,
],
```

Do not set `LOG_SLACK_LEVEL=debug` in production, or the channel can become noisy.

## 21. Rollout Checklist

- Install `laravel/slack-notification-channel`.
- Configure the Slack app, bot token, and channel IDs.
- Run the migration.
- Add Slack member IDs to users in Filament.
- Replace or add the Slack assignment bulk action.
- Run `php artisan slack:pending-work-reminder` manually.
- Create one test assignment and verify the person is pinged.
- Create one targeted partial approval request and verify Slack pings the approver after the delay.
- Keep `queue:work` and `schedule:run` running in production.
- After a few days, remove or hide the old email action if Slack fully replaces it.

## 22. References Checked

- Laravel 12 Slack notifications: <https://laravel.com/docs/12.x/notifications#slack-notifications>
- Laravel 12 notification routing: <https://laravel.com/docs/12.x/notifications#routing-slack-notifications>
- Laravel 12 scheduler: <https://laravel.com/docs/12.x/scheduling>
- Slack `chat.postMessage`: <https://docs.slack.dev/reference/methods/chat.postMessage/>
- Slack user mentions: <https://docs.slack.dev/messaging/formatting-message-text/#mentioning-users>
- Slack lookup user by email: <https://docs.slack.dev/reference/methods/users.lookupByEmail/>
