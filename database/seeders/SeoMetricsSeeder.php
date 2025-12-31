<?php

namespace Database\Seeders;

use App\Models\SeoMetric;
use App\Models\SeoPeriod;
use Illuminate\Database\Seeder;

class SeoMetricsSeeder extends Seeder
{
    public function run(): void
    {
        $periods = [
            'March - May 2025' => ['sort_order' => 1],
            'June to Aug 2025' => ['sort_order' => 2],
        ];

        $periodMap = [];
        foreach ($periods as $label => $meta) {
            $periodMap[$label] = SeoPeriod::updateOrCreate(
                ['label' => $label],
                ['sort_order' => $meta['sort_order']]
            )->id;
        }

        $rows = [
            // March - May 2025 (queries)
            ['period_label' => 'March - May 2025', 'entity_type' => 'query', 'entity_value' => 'leigh avenue', 'clicks' => 157, 'impressions' => 881, 'ctr' => 17.82, 'position' => 1.66],
            ['period_label' => 'March - May 2025', 'entity_type' => 'query', 'entity_value' => 'leigh avenue jewellery', 'clicks' => 21, 'impressions' => 95, 'ctr' => 22.11, 'position' => 1.01],
            ['period_label' => 'March - May 2025', 'entity_type' => 'query', 'entity_value' => 'luna linx', 'clicks' => 2, 'impressions' => 381, 'ctr' => 0.52, 'position' => 9.54],
            ['period_label' => 'March - May 2025', 'entity_type' => 'query', 'entity_value' => 'be the change', 'clicks' => 2, 'impressions' => 118, 'ctr' => 1.69, 'position' => 8.24],
            ['period_label' => 'March - May 2025', 'entity_type' => 'query', 'entity_value' => 'leigh jewelers', 'clicks' => 2, 'impressions' => 33, 'ctr' => 6.06, 'position' => 35.12],
            ['period_label' => 'March - May 2025', 'entity_type' => 'query', 'entity_value' => 'lee avenue', 'clicks' => 2, 'impressions' => 10, 'ctr' => 20.00, 'position' => 2.90],
            ['period_label' => 'March - May 2025', 'entity_type' => 'query', 'entity_value' => 'livi', 'clicks' => 1, 'impressions' => 469, 'ctr' => 0.21, 'position' => 8.14],
            ['period_label' => 'March - May 2025', 'entity_type' => 'query', 'entity_value' => 'chameleon wizard', 'clicks' => 1, 'impressions' => 167, 'ctr' => 0.60, 'position' => 20.02],
            ['period_label' => 'March - May 2025', 'entity_type' => 'query', 'entity_value' => 'kofifi style', 'clicks' => 1, 'impressions' => 85, 'ctr' => 1.18, 'position' => 9.33],
            ['period_label' => 'March - May 2025', 'entity_type' => 'query', 'entity_value' => 'penny whistle', 'clicks' => 1, 'impressions' => 67, 'ctr' => 1.49, 'position' => 12.85],
            ['period_label' => 'March - May 2025', 'entity_type' => 'query', 'entity_value' => 'luna lover', 'clicks' => 1, 'impressions' => 46, 'ctr' => 2.17, 'position' => 10.15],
            ['period_label' => 'March - May 2025', 'entity_type' => 'query', 'entity_value' => 'elements of desire', 'clicks' => 1, 'impressions' => 44, 'ctr' => 2.27, 'position' => 22.05],
            ['period_label' => 'March - May 2025', 'entity_type' => 'query', 'entity_value' => 'dj on the decks', 'clicks' => 1, 'impressions' => 29, 'ctr' => 3.45, 'position' => 2.52],
            ['period_label' => 'March - May 2025', 'entity_type' => 'query', 'entity_value' => 'shop avenue', 'clicks' => 1, 'impressions' => 16, 'ctr' => 6.25, 'position' => 8.44],
            ['period_label' => 'March - May 2025', 'entity_type' => 'query', 'entity_value' => 'mamariti', 'clicks' => 1, 'impressions' => 10, 'ctr' => 10.00, 'position' => 5.80],
            ['period_label' => 'March - May 2025', 'entity_type' => 'query', 'entity_value' => 'tortoise shells for sale', 'clicks' => 1, 'impressions' => 6, 'ctr' => 16.67, 'position' => 2.00],
            ['period_label' => 'March - May 2025', 'entity_type' => 'query', 'entity_value' => 'tortoise shell', 'clicks' => 0, 'impressions' => 248, 'ctr' => 0.00, 'position' => 10.92],
            ['period_label' => 'March - May 2025', 'entity_type' => 'query', 'entity_value' => 'leigh', 'clicks' => 0, 'impressions' => 228, 'ctr' => 0.00, 'position' => 7.89],
            ['period_label' => 'March - May 2025', 'entity_type' => 'query', 'entity_value' => 'grandiose', 'clicks' => 0, 'impressions' => 202, 'ctr' => 0.00, 'position' => 11.65],
            ['period_label' => 'March - May 2025', 'entity_type' => 'query', 'entity_value' => 'bracelets cape town', 'clicks' => 0, 'impressions' => 183, 'ctr' => 0.00, 'position' => 37.61],

            // June to Aug 2025 (queries)
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'query', 'entity_value' => 'leigh avenue', 'clicks' => 439, 'impressions' => 1254, 'ctr' => 35.01, 'position' => 1.45],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'query', 'entity_value' => 'leigh avenue jewellery', 'clicks' => 129, 'impressions' => 291, 'ctr' => 44.33, 'position' => 1.01],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'query', 'entity_value' => 'lee avenue', 'clicks' => 11, 'impressions' => 57, 'ctr' => 19.30, 'position' => 37.58],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'query', 'entity_value' => 'leighavenue', 'clicks' => 10, 'impressions' => 17, 'ctr' => 58.82, 'position' => 1.00],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'query', 'entity_value' => 'leigh ave', 'clicks' => 2, 'impressions' => 58, 'ctr' => 3.45, 'position' => 5.95],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'query', 'entity_value' => 'pleasure treasure', 'clicks' => 2, 'impressions' => 39, 'ctr' => 5.13, 'position' => 25.33],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'query', 'entity_value' => 'handmade jewellery', 'clicks' => 1, 'impressions' => 580, 'ctr' => 0.17, 'position' => 75.09],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'query', 'entity_value' => 'handcrafted jewellery', 'clicks' => 1, 'impressions' => 186, 'ctr' => 0.54, 'position' => 75.95],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'query', 'entity_value' => 'handmade jewellery south africa', 'clicks' => 1, 'impressions' => 169, 'ctr' => 0.59, 'position' => 32.21],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'query', 'entity_value' => 'dj on the decks', 'clicks' => 1, 'impressions' => 88, 'ctr' => 1.14, 'position' => 3.64],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'query', 'entity_value' => 'elements of desire', 'clicks' => 1, 'impressions' => 36, 'ctr' => 2.78, 'position' => 18.86],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'query', 'entity_value' => 'handmade jewellery online south africa', 'clicks' => 1, 'impressions' => 25, 'ctr' => 4.00, 'position' => 14.00],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'query', 'entity_value' => 'handcrafted bracelets', 'clicks' => 1, 'impressions' => 23, 'ctr' => 4.35, 'position' => 91.09],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'query', 'entity_value' => 'mobicred', 'clicks' => 1, 'impressions' => 21, 'ctr' => 4.76, 'position' => 4.95],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'query', 'entity_value' => 'avenue sa', 'clicks' => 1, 'impressions' => 8, 'ctr' => 12.50, 'position' => 30.12],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'query', 'entity_value' => 'elevated basics', 'clicks' => 1, 'impressions' => 7, 'ctr' => 14.29, 'position' => 7.86],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'query', 'entity_value' => 'leopard bracelet', 'clicks' => 1, 'impressions' => 6, 'ctr' => 16.67, 'position' => 6.67],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'query', 'entity_value' => 'wave necklace', 'clicks' => 1, 'impressions' => 5, 'ctr' => 20.00, 'position' => 9.40],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'query', 'entity_value' => 'leah avenue', 'clicks' => 1, 'impressions' => 2, 'ctr' => 50.00, 'position' => 41.00],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'query', 'entity_value' => 'leigh beads', 'clicks' => 1, 'impressions' => 1, 'ctr' => 100.00, 'position' => 5.00],

            // March - May 2025 (pages)
            ['period_label' => 'March - May 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/', 'clicks' => 182, 'impressions' => 2488, 'ctr' => 7.32, 'position' => 9.85],
            ['period_label' => 'March - May 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/collections/bracelets', 'clicks' => 31, 'impressions' => 1939, 'ctr' => 1.60, 'position' => 9.29],
            ['period_label' => 'March - May 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/collections/necklaces', 'clicks' => 12, 'impressions' => 1529, 'ctr' => 0.78, 'position' => 3.34],
            ['period_label' => 'March - May 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/pages/stockists', 'clicks' => 8, 'impressions' => 780, 'ctr' => 1.03, 'position' => 15.54],
            ['period_label' => 'March - May 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/collections/earrings', 'clicks' => 7, 'impressions' => 1461, 'ctr' => 0.48, 'position' => 3.19],
            ['period_label' => 'March - May 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/collections/livi-road', 'clicks' => 7, 'impressions' => 1193, 'ctr' => 0.59, 'position' => 6.90],
            ['period_label' => 'March - May 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/pages/about', 'clicks' => 6, 'impressions' => 406, 'ctr' => 1.48, 'position' => 4.41],
            ['period_label' => 'March - May 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/pages/contact', 'clicks' => 5, 'impressions' => 865, 'ctr' => 0.58, 'position' => 7.14],
            ['period_label' => 'March - May 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/products/kofifi-culture', 'clicks' => 5, 'impressions' => 396, 'ctr' => 1.26, 'position' => 13.12],
            ['period_label' => 'March - May 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/products/tortoise-shell-2', 'clicks' => 3, 'impressions' => 434, 'ctr' => 0.69, 'position' => 11.17],
            ['period_label' => 'March - May 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/shop/', 'clicks' => 3, 'impressions' => 118, 'ctr' => 2.54, 'position' => 5.01],
            ['period_label' => 'March - May 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/products/luna-linx', 'clicks' => 2, 'impressions' => 439, 'ctr' => 0.46, 'position' => 9.67],
            ['period_label' => 'March - May 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/collections/elevated-basics', 'clicks' => 2, 'impressions' => 150, 'ctr' => 1.33, 'position' => 5.47],
            ['period_label' => 'March - May 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/products/be-the-change', 'clicks' => 2, 'impressions' => 132, 'ctr' => 1.52, 'position' => 8.43],
            ['period_label' => 'March - May 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/products/mamaritis-shebeen', 'clicks' => 2, 'impressions' => 85, 'ctr' => 2.35, 'position' => 7.73],
            ['period_label' => 'March - May 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/products/times-square-traipsing', 'clicks' => 2, 'impressions' => 5, 'ctr' => 40.00, 'position' => 16.60],
            ['period_label' => 'March - May 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/collections', 'clicks' => 1, 'impressions' => 760, 'ctr' => 0.13, 'position' => 2.16],
            ['period_label' => 'March - May 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/pages/collections', 'clicks' => 1, 'impressions' => 641, 'ctr' => 0.16, 'position' => 4.49],
            ['period_label' => 'March - May 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/products/dj-on-the-decks', 'clicks' => 1, 'impressions' => 244, 'ctr' => 0.41, 'position' => 15.32],
            ['period_label' => 'March - May 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/products/chameleon-wizard', 'clicks' => 1, 'impressions' => 189, 'ctr' => 0.53, 'position' => 19.16],

            // June to Aug 2025 (pages)
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/', 'clicks' => 566, 'impressions' => 14690, 'ctr' => 3.85, 'position' => 56.92],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/collections/bracelets', 'clicks' => 121, 'impressions' => 2640, 'ctr' => 4.58, 'position' => 4.94],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/collections/necklaces', 'clicks' => 26, 'impressions' => 2498, 'ctr' => 1.04, 'position' => 4.03],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/pages/stockists', 'clicks' => 25, 'impressions' => 1674, 'ctr' => 1.49, 'position' => 25.26],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/pages/about', 'clicks' => 20, 'impressions' => 1829, 'ctr' => 1.09, 'position' => 2.98],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/collections/earrings', 'clicks' => 13, 'impressions' => 1666, 'ctr' => 0.78, 'position' => 2.80],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/pages/contact', 'clicks' => 11, 'impressions' => 1412, 'ctr' => 0.78, 'position' => 4.31],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/collections', 'clicks' => 7, 'impressions' => 616, 'ctr' => 1.14, 'position' => 2.30],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/collections/livi-road', 'clicks' => 4, 'impressions' => 1010, 'ctr' => 0.40, 'position' => 6.89],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/products/ganesha', 'clicks' => 4, 'impressions' => 60, 'ctr' => 6.67, 'position' => 8.30],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/pages/reviews', 'clicks' => 4, 'impressions' => 47, 'ctr' => 8.51, 'position' => 5.36],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/products/worth-dying-for', 'clicks' => 4, 'impressions' => 33, 'ctr' => 12.12, 'position' => 10.88],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/products/jolly-jammer-1', 'clicks' => 3, 'impressions' => 443, 'ctr' => 0.68, 'position' => 6.36],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/pages/mobicred', 'clicks' => 2, 'impressions' => 509, 'ctr' => 0.39, 'position' => 33.87],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/products/kofifi-culture', 'clicks' => 2, 'impressions' => 352, 'ctr' => 0.57, 'position' => 13.22],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/products/adjustable-bracelet-with-bead-insert-and-rosegold-chain', 'clicks' => 2, 'impressions' => 240, 'ctr' => 0.83, 'position' => 5.78],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/collections/elevated-basics', 'clicks' => 2, 'impressions' => 236, 'ctr' => 0.85, 'position' => 26.31],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/collections/elevated-basics/products/pleasure-treasure', 'clicks' => 2, 'impressions' => 61, 'ctr' => 3.28, 'position' => 18.77],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/products/thula-thula', 'clicks' => 2, 'impressions' => 43, 'ctr' => 4.65, 'position' => 14.72],
            ['period_label' => 'June to Aug 2025', 'entity_type' => 'page', 'entity_value' => 'https://leighavenue.co.za/products/desert-daisy', 'clicks' => 2, 'impressions' => 15, 'ctr' => 13.33, 'position' => 15.60],
        ];

        foreach ($rows as $row) {
            SeoMetric::updateOrCreate(
                [
                    'period_id' => $periodMap[$row['period_label']],
                    'entity_type' => $row['entity_type'],
                    'entity_value' => $row['entity_value'],
                ],
                [
                    'clicks' => $row['clicks'],
                    'impressions' => $row['impressions'],
                    'ctr' => $row['ctr'],
                    'position' => $row['position'],
                ]
            );
        }
    }
}
