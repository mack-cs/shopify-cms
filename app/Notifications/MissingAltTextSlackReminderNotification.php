<?php

namespace App\Notifications;

use App\Filament\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Str;

final class MissingAltTextSlackReminderNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $products = Product::query()
            ->activeMissingImageAltText()
            ->withCount([
                'images as missing_image_alt_text_count' => fn (Builder $query): Builder => Product::applyMissingImageAltTextImageFilter($query),
            ])
            ->orderByDesc('missing_image_alt_text_count')
            ->orderBy('title')
            ->limit(20)
            ->get();

        $count = Product::query()->activeMissingImageAltText()->count();
        $lines = $products->map(function (Product $product): string {
            $url = $this->absoluteUrl(ProductResource::getUrl('edit', ['record' => $product]));
            $title = trim((string) $product->title) ?: "Product #{$product->id}";
            $images = (int) ($product->missing_image_alt_text_count ?? 0);

            return "- <{$url}|{$title}> -- {$images} " . Str::plural('image', $images);
        })->implode("\n");

        return (new SlackMessage)
            ->text('Missing image alt text needs attention')
            ->usingBlockKitTemplate(json_encode(['blocks' => [
                [
                    'type' => 'header',
                    'text' => ['type' => 'plain_text', 'text' => 'Missing image alt text'],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "Your daily task reminder: *{$count} active products* need image alt text.\n" . Str::limit($lines, 2800),
                    ],
                ],
            ]], JSON_UNESCAPED_SLASHES) ?: '{"blocks":[]}');
    }

    private function absoluteUrl(string $url): string
    {
        return Str::startsWith($url, ['http://', 'https://'])
            ? $url
            : rtrim((string) config('app.url'), '/') . '/' . ltrim($url, '/');
    }
}
