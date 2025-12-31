<?php

namespace Database\Seeders;

use App\Models\SeoPage;
use Illuminate\Database\Seeder;

class SeoPageSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'name' => 'Homepage',
                'keywords' => 'Leigh Avenue, handcrafted jewellery, bracelets, necklaces, earrings, charms',
                'url' => 'https://leighavenue.co.za/',
                'seo_title' => 'Leigh Avenue handcrafted jewellery, bracelets, necklaces & earrings',
                'meta_title' => 'Leigh Avenue | Design house of handcrafted jewellery',
                'meta_description' => 'Leigh Avenue handcrafted jewellery includes bracelets, necklaces, charms, earrings in eclectic and classical contemporary styles.',
                'notes' => 'Waiting for new images.',
            ],
            [
                'name' => 'Stockist',
                'keywords' => 'Leigh Avenue Jewellery',
                'url' => 'https://leighavenue.co.za/pages/stockists',
                'seo_title' => 'Leigh Avenue Jewellery supply to these jewellery shops',
                'meta_title' => 'Leigh Avenue | Jewellery | Stockists and jewellery shops',
                'meta_description' => 'Leigh Avenue Jewellery is available at top stockists across South Africa including Mungo & Jemima, The Space, Odizee, The Linden Co-Op, Colour Box, Kubili.',
                'notes' => 'Waiting for new images.',
            ],
            [
                'name' => 'About Us',
                'keywords' => 'Leigh Avenue Jewellery',
                'url' => 'https://leighavenue.co.za/pages/about',
                'seo_title' => 'About Leigh Avenue Jewellery',
                'meta_title' => 'Leigh Avenue | Jewellery | About Us',
                'meta_description' => "Discover what makes Leigh Avenue Jewellery so unique and why it's jewellery pieces are so sought after by decerning sophisticated customers.",
                'notes' => 'Waiting for new images.',
            ],
            [
                'name' => 'Contact us',
                'keywords' => 'Leigh Avenue Jewellery',
                'url' => 'https://leighavenue.co.za/pages/contact',
                'seo_title' => 'Contact us Leigh Avenue Jewellery',
                'meta_title' => 'Leigh Avenue | Jewellery | Contact us',
                'meta_description' => 'Get in touch with Leigh Avenue Jewellery and enjoy exceptional online service. Our customers delight in our quality jewellery products and online service.',
                'notes' => 'Waiting for new images.',
            ],
            [
                'name' => 'Bracelets',
                'keywords' => 'Leigh Avenue, bracelets',
                'url' => 'https://leighavenue.co.za/bracelets/',
                'seo_title' => 'Leigh Avenue Bracelets',
                'meta_title' => 'Leigh Avenue bracelets | Mix, match & stack your way',
                'meta_description' => 'Handmade bracelets from Leigh Avenue that will add a touch of bohemian charm. Discover handcrafted designs in vibrant colours and unique patterns.',
                'notes' => 'Waiting for new images.',
            ],
            [
                'name' => 'Necklaces',
                'keywords' => 'Leigh Avenue, necklaces',
                'url' => 'https://leighavenue.co.za/necklaces',
                'seo_title' => 'Leigh Avenue Necklaces',
                'meta_title' => 'Leigh Avenue necklaces | Handcrafted designed necklaces',
                'meta_description' => "Discover Leigh Avenue's unique handcrafted necklaces with vibrant colours, unique patterns, variety of themes in eclectic contemporary styles.",
                'notes' => 'Waiting for new images.',
            ],
            [
                'name' => 'Charms',
                'keywords' => 'Leigh Avenue, charms, jewellery',
                'url' => 'https://leighavenue.co.za/charms',
                'seo_title' => 'Leigh Avenue Charms',
                'meta_title' => 'Leigh Avenue charms | Explore exquisite handmade charms',
                'meta_description' => "Explore Leigh Avenue's wide range of handmade charms, designed for those discerning customer who appreciate quality, style and workmanship.",
                'notes' => 'Waiting for new images.',
            ],
            [
                'name' => 'Earrings',
                'keywords' => 'Leigh Avenue, earrings',
                'url' => 'https://leighavenue.co.za/earrings',
                'seo_title' => 'Leigh Avenue Earrings',
                'meta_title' => 'Leigh Avenue earrings | Exclusively designed and hand made',
                'meta_description' => 'Discover the absolute finest hand made earrings for women in South Africa exclusively designed by Leigh Avenue, the design house of handcrafted jewellery',
                'notes' => 'Waiting for new images.',
            ],
            [
                'name' => 'Shop by Collections',
                'keywords' => 'Leigh Avenue, handcrafted jewellery',
                'url' => 'https://leighavenue.co.za/pages/collections',
                'seo_title' => 'Leigh Avenue Collections | Handcrafted Jewellery',
                'meta_title' => 'Leigh Avenue | High quality handcrafted jewellery',
                'meta_description' => 'Leigh Avenue, the design house of handcrafted jewellery, offers bracelets, necklaces, charms and earrings in a wide variety of styles, textures and colours.',
                'notes' => 'Waiting for new images.',
            ],
            [
                'name' => 'Shop by Style',
                'keywords' => 'Leigh Avenue, jewellery',
                'url' => 'https://leighavenue.co.za/pages/shop-by-style',
                'seo_title' => 'Leigh Avenue Jewellery',
                'meta_title' => 'Leigh Avenue | Ultimate in everyday wear jewellery',
                'meta_description' => 'Why look for the nearest jewellery shops when you can buy online from Leigh Avenue for bracelets, necklaces, charms and earrings in vibrant colours and styles.',
                'notes' => 'Waiting for new images.',
            ],
        ];

        foreach ($rows as $row) {
            SeoPage::updateOrCreate(
                ['url' => $row['url']],
                $row
            );
        }
    }
}
