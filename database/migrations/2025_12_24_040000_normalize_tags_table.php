<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tags = DB::table('tags')->select('id', 'name')->get();
        $seen = [];

        foreach ($tags as $tag) {
            $normalized = $this->normalizeTagToken($tag->name);
            if ($normalized === null) {
                DB::table('tags')->where('id', $tag->id)->delete();
                continue;
            }

            if ($normalized === $tag->name) {
                $seen[$normalized] = $tag->id;
                continue;
            }

            if (isset($seen[$normalized]) || DB::table('tags')->where('name', $normalized)->exists()) {
                DB::table('tags')->where('id', $tag->id)->delete();
                continue;
            }

            DB::table('tags')->where('id', $tag->id)->update(['name' => $normalized]);
            $seen[$normalized] = $tag->id;
        }

        $products = DB::table('products')->select('id', 'tags')->get();
        foreach ($products as $product) {
            $normalized = $this->normalizeTagString($product->tags);
            DB::table('products')
                ->where('id', $product->id)
                ->update(['tags' => $normalized]);
        }
    }

    public function down(): void
    {
        // irreversible normalization
    }

    private function normalizeTagString(?string $value): ?string
    {
        $tokens = $this->parseTags($value);
        return empty($tokens) ? null : implode(', ', $tokens);
    }

    private function parseTags(?string $value): array
    {
        $value = $this->normalizeValue($value);
        if ($value === null) {
            return [];
        }

        $parts = preg_split('/[,;]/', $value);
        if (!$parts) {
            return [];
        }

        $tokens = [];
        $seen = [];
        foreach ($parts as $part) {
            $token = $this->normalizeTagToken($part);
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

    private function normalizeTagToken(?string $value): ?string
    {
        $value = $this->normalizeValue($value);
        if ($value === null) {
            return null;
        }

        $normalized = strtolower($value);
        $normalized = str_replace('&', 'and', $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized);
        $normalized = preg_replace('/-+/', '-', $normalized);
        $normalized = trim($normalized, '-');

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
