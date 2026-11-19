<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('direct_inventory_correction_reports')) {
            Schema::create('direct_inventory_correction_reports', function (Blueprint $table): void {
                $table->id();
                $table->date('week_start')->unique();
                $table->string('status', 32);
                $table->text('error')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('direct_inventory_correction_report_items')) {
            Schema::create('direct_inventory_correction_report_items', function (Blueprint $table): void {
                $table->id();
                $table->string('adjustment_id', 64)->unique();
                $table->unsignedBigInteger('report_id')->nullable()->index();
                $table->string('status', 32);
                $table->timestamps();
            });
        }

        // Previous daily deliveries have no receipt ledger. Record uncertainty,
        // never assume an existing adjustment was actually emailed.
        $cutoverId = DB::table('shopify_inventory_adjustments')->max('id');
        DB::table('shopify_inventory_adjustments')
            ->where('id', '<=', $cutoverId ?? 0)
            ->whereRaw('lower(reason) = ?', ['correction'])
            ->orderBy('id')->chunkById(500, function ($rows): void {
                $stamp = now();
                DB::table('direct_inventory_correction_report_items')->insertOrIgnore(
                    $rows->map(fn ($row): array => [
                        'adjustment_id' => $row->adjustment_id,
                        'report_id' => null,
                        'status' => 'cutover_review',
                        'created_at' => $stamp,
                        'updated_at' => $stamp,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain image and delivery evidence during application rollback; destructive schema rollback requires separate review.');
    }
};
