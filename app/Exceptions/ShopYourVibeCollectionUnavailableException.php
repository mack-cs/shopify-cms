<?php

namespace App\Exceptions;

use RuntimeException;

class ShopYourVibeCollectionUnavailableException extends RuntimeException
{
    public function __construct(public readonly string $collectionGid)
    {
        parent::__construct('Shopify returned no collection for '.$collectionGid.'.');
    }
}
