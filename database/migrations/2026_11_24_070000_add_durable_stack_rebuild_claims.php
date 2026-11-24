<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MARKERS = 'stack_rebuild_schema_markers';

    public function up(): void
    {
        $this->markers();
        $this->ensureBatchColumn();
        $this->ensureOwnerColumn();
        $this->ensureClaimsTable();
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::MARKERS)) {
            return;
        }

        $added = DB::table(self::MARKERS)->pluck('name')->all();

        if (in_array('table:stack_rebuild_claims', $added, true)) {
            Schema::dropIfExists('stack_rebuild_claims');
        } else {
            foreach ([
                'column:stack_rebuild_claims.batch_id',
                'column:stack_rebuild_claims.owner',
                'column:stack_rebuild_claims.started_at',
                'column:stack_rebuild_claims.heartbeat_at',
            ] as $marker) {
                if (! in_array($marker, $added, true)) {
                    continue;
                }
                $column = substr($marker, strlen('column:stack_rebuild_claims.'));
                if (Schema::hasTable('stack_rebuild_claims') && Schema::hasColumn('stack_rebuild_claims', $column)) {
                    Schema::table('stack_rebuild_claims', function (Blueprint $table) use ($column): void {
                        $table->dropColumn($column);
                    });
                }
            }
        }

        if (in_array('index:products.image_rebuild_batch_id', $added, true)
            && ! in_array('column:products.image_rebuild_batch_id', $added, true)
            && Schema::hasColumn('products', 'image_rebuild_batch_id')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropIndex(['image_rebuild_batch_id']);
            });
        }

        foreach ([
            'column:products.image_rebuild_owner',
            'column:products.image_rebuild_batch_id',
        ] as $marker) {
            if (! in_array($marker, $added, true)) {
                continue;
            }
            $this->dropProductColumn(substr($marker, strlen('column:products.')));
        }

        Schema::dropIfExists(self::MARKERS);
    }

    private function dropProductColumn(string $column): void
    {
        if (! Schema::hasColumn('products', $column)) {
            return;
        }
        if ($this->hasIndex('products', $column)) {
            Schema::table('products', function (Blueprint $table) use ($column): void {
                $table->dropIndex([$column]);
            });
        }
        Schema::table('products', function (Blueprint $table) use ($column): void {
            $table->dropColumn($column);
        });
    }

    private function markers(): void
    {
        if (Schema::hasTable(self::MARKERS)) {
            return;
        }

        Schema::create(self::MARKERS, function (Blueprint $table): void {
            $table->string('name')->primary();
        });
    }

    private function ensureBatchColumn(): void
    {
        if (Schema::hasColumn('products', 'image_rebuild_batch_id')) {
            $this->assertIntegerColumn('products', 'image_rebuild_batch_id');
            if (! $this->hasIndex('products', 'image_rebuild_batch_id')) {
                Schema::table('products', function (Blueprint $table): void {
                    $table->index('image_rebuild_batch_id');
                });
                $this->mark('index:products.image_rebuild_batch_id');
            }

            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $column = $table->unsignedBigInteger('image_rebuild_batch_id')->nullable()->index();
            if (Schema::hasColumn('products', 'image_rebuild_message')) {
                $column->after('image_rebuild_message');
            }
        });
        $this->mark('column:products.image_rebuild_batch_id');
    }

    private function ensureOwnerColumn(): void
    {
        if (Schema::hasColumn('products', 'image_rebuild_owner')) {
            $this->assertStringColumn('products', 'image_rebuild_owner');

            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $column = $table->string('image_rebuild_owner', 64)->nullable();
            if (Schema::hasColumn('products', 'image_rebuild_batch_id')) {
                $column->after('image_rebuild_batch_id');
            }
        });
        $this->mark('column:products.image_rebuild_owner');
    }

    private function ensureClaimsTable(): void
    {
        if (! Schema::hasTable('stack_rebuild_claims')) {
            Schema::create('stack_rebuild_claims', function (Blueprint $table): void {
                $table->id();
                $table->string('claim_key', 255)->unique();
                $table->unsignedBigInteger('batch_id')->nullable()->index();
                $table->string('owner', 64);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('heartbeat_at')->nullable();
                $table->timestamps();
            });
            $this->mark('table:stack_rebuild_claims');

            return;
        }

        $this->assertStringColumn('stack_rebuild_claims', 'claim_key');
        $this->assertUnique('stack_rebuild_claims', 'claim_key');
        $this->ensureClaimColumn('batch_id', function (Blueprint $table): void {
            $table->unsignedBigInteger('batch_id')->nullable()->index();
        });
        $this->ensureClaimColumn('owner', function (Blueprint $table): void {
            $table->string('owner', 64)->nullable();
        });
        $this->ensureClaimColumn('started_at', function (Blueprint $table): void {
            $table->timestamp('started_at')->nullable();
        });
        $this->ensureClaimColumn('heartbeat_at', function (Blueprint $table): void {
            $table->timestamp('heartbeat_at')->nullable();
        });
        if (Schema::hasColumn('stack_rebuild_claims', 'owner')) {
            $this->assertStringColumn('stack_rebuild_claims', 'owner');
        }
    }

    private function ensureClaimColumn(string $column, Closure $definition): void
    {
        if (Schema::hasColumn('stack_rebuild_claims', $column)) {
            return;
        }

        Schema::table('stack_rebuild_claims', function (Blueprint $table) use ($definition): void {
            $definition($table);
        });
        $this->mark('column:stack_rebuild_claims.'.$column);
    }

    private function assertIntegerColumn(string $table, string $column): void
    {
        $definition = $this->column($table, $column);
        $type = strtolower((string) ($definition['type_name'] ?? $definition['type'] ?? ''));
        if (! str_contains($type, 'int')) {
            throw new RuntimeException($table.'.'.$column.' has an incompatible type.');
        }
        if (($definition['nullable'] ?? true) !== true) {
            throw new RuntimeException($table.'.'.$column.' must remain nullable.');
        }
    }

    private function assertStringColumn(string $table, string $column): void
    {
        $definition = $this->column($table, $column);
        $type = strtolower((string) ($definition['type_name'] ?? $definition['type'] ?? ''));
        if (! str_contains($type, 'char') && ! str_contains($type, 'text') && ! str_contains($type, 'string') && ! str_contains($type, 'clob')) {
            throw new RuntimeException($table.'.'.$column.' has an incompatible type.');
        }
    }

    private function assertUnique(string $table, string $column): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            $columns = $index['columns'] ?? [];
            if (($index['unique'] ?? false) === true && in_array($column, $columns, true)) {
                return;
            }
        }

        throw new RuntimeException($table.'.'.$column.' must be unique.');
    }

    /**
     * @return array<string, mixed>
     */
    private function column(string $table, string $name): array
    {
        foreach (Schema::getColumns($table) as $column) {
            if (($column['name'] ?? null) === $name) {
                return $column;
            }
        }

        throw new RuntimeException($table.'.'.$name.' is missing.');
    }

    private function hasIndex(string $table, string $column): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (in_array($column, $index['columns'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    private function mark(string $name): void
    {
        DB::table(self::MARKERS)->insertOrIgnore([
            'name' => $name,
        ]);
    }
};
