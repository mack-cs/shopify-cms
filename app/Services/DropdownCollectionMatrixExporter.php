<?php

namespace App\Services;

use League\Csv\Reader;
use League\Csv\Writer;
use SplTempFileObject;

final class DropdownCollectionMatrixExporter
{
    public function __construct(private readonly DropdownCollectionCatalog $catalog) {}

    public function exportToString(?string $sourcePath = null): string
    {
        $sourcePath ??= storage_path('app/public/template/dropdown-seed.csv');
        if (! is_file($sourcePath)) {
            return '';
        }

        $reader = Reader::createFromPath($sourcePath);
        $reader->setHeaderOffset(0);

        $rawHeaders = $reader->getHeader();
        $headers = array_map(fn (string $header): string => $this->cleanHeader($header), $rawHeaders);
        $collectionIndex = array_search('Collection', $headers, true);
        $tagsIndex = array_search('Tags', $headers, true);

        $exportHeaders = ['Collection', 'Vendor'];
        foreach ($headers as $index => $header) {
            if ($index === $collectionIndex || $index === $tagsIndex || $header === '') {
                continue;
            }

            $exportHeaders[] = $header;
        }

        $writer = Writer::createFromFileObject(new SplTempFileObject());
        $writer->insertOne($exportHeaders);

        foreach ($reader->getRecords() as $record) {
            $collection = $collectionIndex === false ? '' : trim((string) ($record[$rawHeaders[$collectionIndex]] ?? ''));
            if ($collection === '') {
                continue;
            }

            $row = [
                $collection,
                $this->catalog->vendorForCollection($collection) ?? '',
            ];

            foreach ($headers as $index => $header) {
                if ($index === $collectionIndex || $index === $tagsIndex || $header === '') {
                    continue;
                }

                $row[] = $this->cleanCell($record[$rawHeaders[$index]] ?? '');
            }

            $writer->insertOne($row);
        }

        return $writer->toString();
    }

    private function cleanHeader(string $header): string
    {
        $header = str_replace("\r", '', $header);
        $lines = preg_split('/\n/', $header) ?: [$header];

        return trim($lines[0] ?? $header);
    }

    private function cleanCell(mixed $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? (string) $value);
    }
}
