<?php

namespace Database\Seeders;

use App\Models\RequiredField;
use App\Services\HeaderStore;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

class RequiredFieldSeeder extends Seeder
{
    public function run(): void
    {
        $headers = $this->loadTemplateHeaders();
        if (empty($headers)) {
            $headers = HeaderStore::knownHeaders();
        }

        $productHeaderMap = [
            HeaderStore::HANDLE => 'handle',
            HeaderStore::TITLE => 'title',
            HeaderStore::BODY_HTML => 'body_html',
            HeaderStore::VENDOR => 'vendor',
            HeaderStore::TAGS => 'tags',
            HeaderStore::TYPE => 'type',
            HeaderStore::PUBLISHED => 'published',
            HeaderStore::PRODUCT_CATEGORY => 'product_category',
            HeaderStore::GOOGLE_PRODUCT_CATEGORY => 'google_product_category',
            HeaderStore::STATUS => 'status',
            HeaderStore::SEO_TITLE => 'seo_title',
            HeaderStore::SEO_DESCRIPTION => 'seo_description',
            HeaderStore::COLOR_METAFIELD => 'color_string',
        ];

        $variantHeaderMap = [
            HeaderStore::VARIANT_SKU => 'sku',
            HeaderStore::VARIANT_PRICE => 'price',
            HeaderStore::VARIANT_COMPARE_AT => 'compare_at_price',
            HeaderStore::VARIANT_BARCODE => 'barcode',
            HeaderStore::OPTION1_NAME => 'option1_name',
            HeaderStore::OPTION1_VALUE => 'option1_value',
            HeaderStore::OPTION2_NAME => 'option2_name',
            HeaderStore::OPTION2_VALUE => 'option2_value',
            HeaderStore::OPTION3_NAME => 'option3_name',
            HeaderStore::OPTION3_VALUE => 'option3_value',
        ];

        $imageHeaderMap = [
            HeaderStore::IMAGE_SRC => 'src',
            HeaderStore::IMAGE_POSITION => 'position',
            HeaderStore::IMAGE_ALT_TEXT => 'alt_text',
        ];

        $requiredDefaults = [
            ['source' => 'product', 'attribute' => 'handle'],
            ['source' => 'product', 'attribute' => 'product_category'],
            ['source' => 'product', 'attribute' => 'type'],
            ['source' => 'product', 'attribute' => 'google_product_category'],
            ['source' => 'product', 'attribute' => 'seo_title'],
            ['source' => 'product', 'attribute' => 'seo_description'],
            ['source' => 'product', 'attribute' => 'title'],
            ['source' => 'product', 'attribute' => 'body_html'],
            ['source' => 'product', 'attribute' => 'vendor'],
            ['source' => 'product', 'attribute' => 'tags'],
            ['source' => 'product', 'attribute' => 'color_string'],
            ['source' => 'variant', 'attribute' => 'sku'],
            ['source' => 'row', 'attribute' => HeaderStore::JEWELRY_MATERIAL],
            ['source' => 'row', 'attribute' => HeaderStore::COST_PER_ITEM],
        ];

        $requiredLookup = [];
        foreach ($requiredDefaults as $item) {
            $requiredLookup[$item['source'] . '|' . $item['attribute']] = true;
        }

        $bulkEditableDefaults = [
            'row|' . HeaderStore::JEWELRY_MATERIAL,
            'product|published',
            'product|status',
            'row|Bracelet design (product.metafields.shopify.bracelet-design)',
        ];

        $bulkEditableLookup = array_fill_keys($bulkEditableDefaults, true);
        $quickEditDefaults = [];
        $quickEditLookup = array_fill_keys($quickEditDefaults, true);

        $rows = [];
        foreach ($headers as $header) {
            if (isset($productHeaderMap[$header])) {
                $attribute = $productHeaderMap[$header];
                $rows[] = $this->makeRow(
                    'product',
                    'product',
                    $attribute,
                    $header,
                    $requiredLookup,
                    $bulkEditableLookup,
                    $quickEditLookup
                );
                continue;
            }
            if (isset($variantHeaderMap[$header])) {
                $attribute = $variantHeaderMap[$header];
                $rows[] = $this->makeRow(
                    'variant',
                    'variant',
                    $attribute,
                    $header,
                    $requiredLookup,
                    $bulkEditableLookup,
                    $quickEditLookup
                );
                continue;
            }
            if (isset($imageHeaderMap[$header])) {
                $attribute = $imageHeaderMap[$header];
                $rows[] = $this->makeRow(
                    'image',
                    'image',
                    $attribute,
                    $header,
                    $requiredLookup,
                    $bulkEditableLookup,
                    $quickEditLookup
                );
                continue;
            }

            $rows[] = $this->makeRow(
                'extra',
                'row',
                $header,
                $header,
                $requiredLookup,
                $bulkEditableLookup,
                $quickEditLookup
            );
        }

        DB::table('required_fields')->truncate();
        if (!empty($rows)) {
            DB::table('required_fields')->insert($rows);
        }
    }

    private function makeRow(
        string $scope,
        string $source,
        string $attribute,
        string $label,
        array $requiredLookup,
        array $bulkEditableLookup,
        array $quickEditLookup
    ): array {
        $isRequired = $requiredLookup[$source . '|' . $attribute] ?? false;
        $isBulkEditable = $bulkEditableLookup[$source . '|' . $attribute] ?? false;
        $isQuickEdit = $quickEditLookup[$source . '|' . $attribute] ?? false;

        return [
            'scope' => $scope,
            'source' => $source,
            'attribute' => $attribute,
            'label' => $label,
            'required' => $isRequired,
            'bulk_editable' => $isBulkEditable,
            'quick_edit' => $isQuickEdit,
        ];
    }

    private function loadTemplateHeaders(): array
    {
        $templatePath = storage_path('app/private/imports/products.csv');
        if (!is_file($templatePath)) {
            return [];
        }

        $csv = Reader::createFromPath($templatePath);
        $csv->setHeaderOffset(0);
        return $csv->getHeader();
    }
}
