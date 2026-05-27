<?php

namespace App\Filament\Resources\ShopifyAuditResource\Pages;

use App\Filament\Resources\ShopifyAuditResource;
use App\Filament\Resources\ShopifyAuditResource\Widgets\ShopifyAuditRunBanner;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;

class ListShopifyAudits extends ListRecords
{
    protected static string $resource = ShopifyAuditResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ShopifyAuditRunBanner::class,
        ];
    }

    public function getHeading(): string|HtmlString
    {
        return new HtmlString(
            '<span style="display:inline-flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">'
            . '<span style="line-height:1;">Shopify Audit</span>'
            . '<span style="color:#d1d5db;">|</span>'
            . '<span style="max-width:80rem;font-size:18px;line-height:20px;color:#1d4ed8;font-weight:400;padding-top:7px;">'
            . '<span style="font-weight:600;">Audit view only:</span> '
            . 'Use this page to monitor live Shopify health, then jump into the existing draft workflow to fix issues.'
            . '</span>'
            . '</span>'
        );
    }
}
