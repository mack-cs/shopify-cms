<?php

use App\Models\NewProductDraft;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table) {
            $table->string('origin', 32)->nullable()->index()->after('payload');
        });

        DB::table('new_product_drafts')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('products')
                    ->where(function ($matchQuery): void {
                        $matchQuery
                            ->where(function ($shopifyMatch): void {
                                $shopifyMatch
                                    ->whereColumn('products.shopify_id', 'new_product_drafts.shopify_id')
                                    ->whereNotNull('new_product_drafts.shopify_id')
                                    ->where('new_product_drafts.shopify_id', '!=', '');
                            })
                            ->orWhere(function ($handleMatch): void {
                                $handleMatch
                                    ->whereColumn('products.handle', 'new_product_drafts.handle')
                                    ->whereNotNull('new_product_drafts.handle')
                                    ->where('new_product_drafts.handle', '!=', '');
                            });
                    });
            })
            ->update(['origin' => NewProductDraft::ORIGIN_PRODUCT_MIRROR]);

        DB::table('new_product_drafts')
            ->whereNull('origin')
            ->update(['origin' => NewProductDraft::ORIGIN_DRAFT_TOOL]);
    }

    public function down(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table) {
            $table->dropIndex(['origin']);
            $table->dropColumn('origin');
        });
    }
};
