<?php

namespace App\Services\Shopify;

use Carbon\CarbonInterface;

final class ShopifyOrderQueryBuilder
{
    public function full(): string
    {
        return $this->build(null, null);
    }

    public function updatedBetween(CarbonInterface $windowStart, CarbonInterface $windowEnd): string
    {
        return $this->build($windowStart, $windowEnd);
    }

    private function build(?CarbonInterface $windowStart, ?CarbonInterface $windowEnd): string
    {
        $connection = 'orders';

        if ($windowStart instanceof CarbonInterface && $windowEnd instanceof CarbonInterface) {
            $filter = sprintf(
                'updated_at:>=%s updated_at:<%s',
                $windowStart->toIso8601String(),
                $windowEnd->toIso8601String(),
            );
            $connection = 'orders(query: "'.addcslashes($filter, '"\\').'")';
        }

        return <<<GQL
{
  {$connection} {
    edges {
      node {
        id
        name
        createdAt
        updatedAt
        processedAt
        cancelledAt
        cancelReason
        displayFinancialStatus
        displayFulfillmentStatus
        currencyCode
        subtotalPriceSet { shopMoney { amount currencyCode } }
        totalPriceSet { shopMoney { amount currencyCode } }
        totalDiscountsSet { shopMoney { amount currencyCode } }
        totalShippingPriceSet { shopMoney { amount currencyCode } }
        totalTaxSet { shopMoney { amount currencyCode } }
        totalRefundedSet { shopMoney { amount currencyCode } }
        tags
        sourceName
        paymentGatewayNames
        test
        customerAcceptsMarketing
        billingAddress { country province city }
        shippingAddress { country province city }
        discountApplications(first: 50) {
          edges {
            node {
              allocationMethod
              targetSelection
              targetType
              value {
                ... on MoneyV2 { amount currencyCode }
                ... on PricingPercentageValue { percentage }
              }
            }
          }
        }
        lineItems(first: 250) {
          edges {
            node {
              id
              title
              quantity
              sku
              vendor
              taxable
              requiresShipping
              originalUnitPriceSet { shopMoney { amount currencyCode } }
              discountedTotalSet { shopMoney { amount currencyCode } }
              totalDiscountSet { shopMoney { amount currencyCode } }
              product {
                id
                title
                handle
                productType
                vendor
                status
              }
              variant {
                id
                title
                sku
                barcode
                price
                inventoryQuantity
              }
            }
          }
        }
        refunds {
          id
          createdAt
          note
          totalRefundedSet { shopMoney { amount currencyCode } }
          refundLineItems(first: 250) {
            edges {
              node {
                id
                quantity
                restocked
                restockType
                subtotalSet { shopMoney { amount currencyCode } }
                totalTaxSet { shopMoney { amount currencyCode } }
                lineItem { id }
                location { id name }
              }
            }
          }
        }
        transactions(first: 100) {
          id
          kind
          status
          gateway
          formattedGateway
          amountSet { shopMoney { amount currencyCode } }
          createdAt
          processedAt
          errorCode
          manualPaymentGateway
          test
          parentTransaction { id }
        }
      }
    }
  }
}
GQL;
    }
}
