<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $colors = DB::table('colors')->select('id', 'name')->get();
        $seen = [];

        foreach ($colors as $color) {
            $normalized = $this->normalizeColorToken($color->name);
            if ($normalized === null) {
                DB::table('colors')->where('id', $color->id)->delete();
                continue;
            }

            if ($normalized === $color->name) {
                $seen[$normalized] = $color->id;
                continue;
            }

            if (isset($seen[$normalized]) || DB::table('colors')->where('name', $normalized)->exists()) {
                DB::table('colors')->where('id', $color->id)->delete();
                continue;
            }

            DB::table('colors')->where('id', $color->id)->update(['name' => $normalized]);
            $seen[$normalized] = $color->id;
        }

        $products = DB::table('products')->select('id', 'color_string')->get();
        foreach ($products as $product) {
            $normalized = $this->normalizeColorString($product->color_string);
            DB::table('products')
                ->where('id', $product->id)
                ->update(['color_string' => $normalized]);
        }
    }

    public function down(): void
    {
        // irreversible normalization
    }

    private function normalizeColorString(?string $value): ?string
    {
        $parts = $this->parseColorTokens($value);
        return empty($parts) ? null : implode('; ', $parts);
    }

    private function parseColorTokens(?string $value): array
    {
        $value = $this->normalizeValue($value);
        if ($value === null) {
            return [];
        }

        $normalized = str_replace(',', ';', $value);
        $rawParts = array_filter(array_map('trim', explode(';', $normalized)));

        $tokens = [];
        $seen = [];
        foreach ($rawParts as $part) {
            $token = $this->normalizeColorToken($part);
            if ($token === null) {
                continue;
            }

            $key = strtolower($token);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $tokens[] = $token;
        }

        return $tokens;
    }

    private function normalizeColorToken(?string $value): ?string
    {
        $value = $this->normalizeValue($value);
        if ($value === null) {
            return null;
        }

        $normalized = strtolower($value);
        $normalized = str_replace('&', 'and', $normalized);
        $normalized = preg_replace('/\s+/', '-', $normalized);
        $normalized = preg_replace('/-+/', '-', $normalized);
        $normalized = trim($normalized, '-');

        if ($normalized === 'multi') {
            $normalized = 'multicolour';
        }

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
};
