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
