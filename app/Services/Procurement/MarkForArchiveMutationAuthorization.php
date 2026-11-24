<?php

namespace App\Services\Procurement;

/**
 * Scoped capability that allows only custom.mark_for_archive metafieldsSet writes.
 * Engraved-bead products otherwise block all Shopify mutations.
 */
final class MarkForArchiveMutationAuthorization
{
    private ?string $ownerId = null;

    public function run(string $ownerId, callable $send): mixed
    {
        $previous = $this->ownerId;
        $this->ownerId = $ownerId;
        try {
            return $send();
        } finally {
            $this->ownerId = $previous;
        }
    }

    public function permits(string $query, array $variables): bool
    {
        if ($this->ownerId === null || ! preg_match('/\bmetafieldsSet\b/', $query)) {
            return false;
        }

        $metafields = $variables['metafields'] ?? null;
        if (! is_array($metafields) || count($metafields) !== 1) {
            return false;
        }

        $metafield = $metafields[0];
        if (! is_array($metafield)) {
            return false;
        }

        return ($metafield['ownerId'] ?? null) === $this->ownerId
            && ($metafield['namespace'] ?? null) === 'custom'
            && ($metafield['key'] ?? null) === 'mark_for_archive'
            && ($metafield['type'] ?? null) === 'boolean'
            && in_array($metafield['value'] ?? null, ['true', 'false'], true);
    }
}
