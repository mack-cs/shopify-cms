<?php

namespace App\Services\Shopify;

final class ShopifyInventoryQueryBuilder
{
    public function build(): string
    {
        $quantityNames = array_values(array_unique(array_filter(array_map(
            fn (mixed $name): string => trim((string) $name),
            config('shopify_sync.inventory.quantity_names', [])
        ))));

        if ($quantityNames === []) {
            $quantityNames = ['available', 'on_hand', 'committed', 'incoming'];
        }

        $names = implode(', ', array_map(fn (string $name): string => '"' . addcslashes($name, '"\\') . '"', $quantityNames));

        return <<<GQL
{
  inventoryItems {
    edges {
      node {
        id
        sku
        tracked
        requiresShipping
        variant {
          id
          title
          displayName
          sku
          barcode
          price
          inventoryQuantity
          product {
            id
            title
            handle
            productType
            vendor
            status
          }
        }
        inventoryLevels {
          edges {
            node {
              id
              location {
                id
                name
                isActive
              }
              quantities(names: [{$names}]) {
                name
                quantity
              }
            }
          }
        }
      }
    }
  }
}
GQL;
    }
}
