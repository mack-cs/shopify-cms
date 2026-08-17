<?php

namespace App\Contracts;

interface ShopifyGraphqlGateway
{
    public function graphql(string $query, array $variables = []): array;
}
