<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table): void {
            if (! Schema::hasColumn('images', 'photograph_version')) {
                $table->unsignedInteger('photograph_version')->default(1);
            }
            if (! Schema::hasColumn('images', 'alt_text_version')) {
                $table->unsignedInteger('alt_text_version')->default(1);
            }
            if (! Schema::hasColumn('images', 'image_attention_since')) {
                $table->timestamp('image_attention_since')->nullable()->index();
            }
        });

        Schema::table('inherited_image_sources', function (Blueprint $table): void {
            if (! Schema::hasColumn('inherited_image_sources', 'source_sku')) {
                $table->string('source_sku')->nullable();
            }
            if (! Schema::hasColumn('inherited_image_sources', 'source_filename')) {
                $table->string('source_filename')->nullable();
            }
            if (! Schema::hasColumn('inherited_image_sources', 'source_position')) {
                $table->unsignedInteger('source_position')->nullable();
            }
            if (! Schema::hasColumn('inherited_image_sources', 'photograph_version')) {
                $table->unsignedInteger('photograph_version')->nullable();
            }
            if (! Schema::hasColumn('inherited_image_sources', 'alt_text_version')) {
                $table->unsignedInteger('alt_text_version')->nullable();
            }
            if (! Schema::hasColumn('inherited_image_sources', 'alt_fingerprint')) {
                $table->string('alt_fingerprint', 64)->nullable();
            }
            if (! Schema::hasColumn('inherited_image_sources', 'asset_sha256')) {
                $table->string('asset_sha256', 64)->nullable();
            }
        });

        if (! Schema::hasTable('component_image_authorizations')) {
            Schema::create('component_image_authorizations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('image_id')->index();
                $table->unsignedBigInteger('image_asset_id')->nullable();
                $table->string('asset_sha256', 64)->nullable();
                $table->unsignedInteger('photograph_version')->default(1);
                $table->unsignedInteger('alt_text_version')->default(1);
                $table->text('alt_text')->nullable();
                $table->string('scope', 32);
                $table->unsignedBigInteger('approval_request_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamps();
                $table->index(['image_id', 'scope', 'photograph_version'], 'image_auth_photo');
                $table->index(['image_id', 'scope', 'alt_text_version'], 'image_auth_alt');
            });
        }

        if (! Schema::hasTable('stack_image_sync_ledger')) {
            Schema::create('stack_image_sync_ledger', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('stack_product_id')->index();
                $table->unsignedBigInteger('image_id')->nullable()->index();
                $table->unsignedBigInteger('source_component_product_id')->nullable();
                $table->unsignedBigInteger('source_image_id')->nullable();
                $table->unsignedInteger('cms_photograph_version')->nullable();
                $table->unsignedInteger('cms_alt_version')->nullable();
                $table->unsignedInteger('authorized_photograph_version')->nullable();
                $table->unsignedInteger('authorized_alt_version')->nullable();
                $table->unsignedInteger('verified_photograph_version')->nullable();
                $table->unsignedInteger('verified_alt_version')->nullable();
                $table->string('shopify_media_id')->nullable();
                $table->unsignedInteger('membership_revision')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->unsignedBigInteger('approval_request_id')->nullable();
                $table->string('outcome', 64)->index();
                $table->text('detail')->nullable();
                $table->boolean('baseline_verified')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('stack_image_sync_cursors')) {
            Schema::create('stack_image_sync_cursors', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('stack_product_id')->unique();
                $table->unsignedInteger('desired_generation')->default(0);
                $table->unsignedInteger('working_generation')->default(0);
                $table->string('status', 32)->default('idle')->index();
                $table->unsignedBigInteger('approval_request_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('image_publication_reminders')) {
            Schema::create('image_publication_reminders', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('product_id')->index();
                $table->timestamp('attention_since');
                $table->string('kind', 32);
                $table->string('status', 32)->index();
                $table->text('detail')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
                $table->unique(['product_id', 'attention_since', 'kind'], 'image_reminder_once');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Retain image and delivery evidence during application rollback; destructive schema rollback requires separate review.');
    }
};
