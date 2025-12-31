<?php

namespace Database\Seeders;

use App\Models\Tag;
use App\Services\HeaderStore;
use App\Services\TagNormalizer;
use Illuminate\Database\Seeder;
use League\Csv\Reader;

class TagSeeder extends Seeder
{
    public function run(): void
    {
        $tags = $this->loadTagsFromTemplate();
        if (empty($tags)) {
            return;
        }

        foreach ($tags as $tag) {
            Tag::updateOrCreate(
                ['name' => $tag],
                ['active' => true]
            );
        }
    }

    private function loadTagsFromTemplate(): array
    {
        $templatePath = storage_path('app/public/template/products_export_1_30_December_2025.csv');
        if (!is_file($templatePath)) {
            return [];
        }

        $csv = Reader::createFromPath($templatePath);
        $csv->setHeaderOffset(0);

        $tags = [];
        foreach ($csv->getRecords() as $row) {
            $value = $row[HeaderStore::TAGS] ?? null;
            $tokens = TagNormalizer::parseTokens($value);
            foreach ($tokens as $token) {
                $tags[$token] = true;
            }
        }

        return array_keys($tags);
    }
}
