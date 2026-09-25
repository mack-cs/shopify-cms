<?php

namespace App\Services;

use App\Models\DropdownOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

final class DropdownReviewWorkbookImporter
{
    /**
     * @param array<string, string> $headers
     * @param array<int, string> $collections
     * @return array{created:int, reactivated:int, updated:int, deactivated:int, skipped:int}
     */
    public function import(string $path, array $headers, array $collections, bool $deactivateMissing = false): array
    {
        if (! is_file($path)) {
            throw ValidationException::withMessages(['file' => 'The review workbook could not be found.']);
        }

        $workbook = $this->readWorkbook($path);
        $headersByLabel = array_flip($headers);
        $allowedCollections = array_fill_keys($collections, true);
        $seen = [];
        $summary = [
            'created' => 0,
            'reactivated' => 0,
            'updated' => 0,
            'deactivated' => 0,
            'skipped' => 0,
        ];

        DropdownOption::withoutEvents(function () use ($workbook, $headersByLabel, $allowedCollections, $deactivateMissing, &$seen, &$summary): void {
            DB::transaction(function () use ($workbook, $headersByLabel, $allowedCollections, $deactivateMissing, &$seen, &$summary): void {
            foreach ($workbook as $sheet) {
                $collection = trim((string) $sheet['name']);
                if (! isset($allowedCollections[$collection])) {
                    continue;
                }

                $rows = $sheet['rows'];
                $headingRow = array_shift($rows) ?? [];
                $columns = [];
                foreach ($headingRow as $index => $label) {
                    $normalized = $this->normalizeHeading($label);
                    foreach ($headersByLabel as $friendlyLabel => $header) {
                        if ($normalized === $this->normalizeHeading($friendlyLabel)) {
                            $columns[$index] = $header;
                            break;
                        }
                    }
                }

                $context = app(DropdownCollectionCatalog::class)->contextForCollection($collection);

                foreach ($rows as $rowIndex => $row) {
                    foreach ($columns as $columnIndex => $header) {
                        $value = trim((string) ($row[$columnIndex] ?? ''));
                        if ($value === '') {
                            continue;
                        }

                        $canonical = DropdownOption::canonicalValue($header, $value);
                        $seen[$collection][$header][$canonical] = true;

                        $matches = DropdownOption::query()
                            ->where('header', $header)
                            ->where('collection_style', $collection)
                            ->get()
                            ->filter(fn (DropdownOption $option): bool => DropdownOption::canonicalValue($header, $option->value) === $canonical);

                        $option = $matches->first();
                        if ($option instanceof DropdownOption) {
                            $changes = [
                                'value' => $value,
                                'active' => true,
                                'sort_order' => $rowIndex,
                                'collection_tag_primary' => $context['tag_primary'] ?? $option->collection_tag_primary,
                                'collection_tag_secondary' => $context['tag_secondary'] ?? $option->collection_tag_secondary,
                            ];
                            $wasActive = (bool) $option->active;
                            $option->fill($changes);
                            if ($option->isDirty()) {
                                $option->save();
                                $summary[$wasActive ? 'updated' : 'reactivated']++;
                            }

                            continue;
                        }

                        DropdownOption::query()->create([
                            'header' => $header,
                            'value' => $value,
                            'collection_style' => $collection,
                            'collection_tag_primary' => $context['tag_primary'] ?? null,
                            'collection_tag_secondary' => $context['tag_secondary'] ?? null,
                            'active' => true,
                            'sort_order' => $rowIndex,
                        ]);
                        $summary['created']++;
                    }
                }
            }

            if (! $deactivateMissing) {
                return;
            }

            DropdownOption::query()
                ->where('active', true)
                ->whereIn('collection_style', array_keys($allowedCollections))
                ->whereIn('header', array_values($headersByLabel))
                ->get()
                ->each(function (DropdownOption $option) use ($seen, &$summary): void {
                    $canonical = DropdownOption::canonicalValue($option->header, $option->value);
                    if (isset($seen[$option->collection_style][$option->header][$canonical])) {
                        return;
                    }

                    $option->active = false;
                    $option->save();
                    $summary['deactivated']++;
                });
            });
        });

        return $summary;
    }

    /**
     * @return array<int, array{name:string, rows:array<int, array<int, string>>}>
     */
    private function readWorkbook(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'The review workbook could not be opened.']);
        }

        $sharedStrings = $this->sharedStrings($zip);
        $workbookXml = $this->xml($zip->getFromName('xl/workbook.xml') ?: '');
        $rels = $this->relationships($zip);
        $sheets = [];

        foreach ($workbookXml->sheets->sheet ?? [] as $sheet) {
            $attributes = $sheet->attributes();
            $relationshipAttributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $relationshipId = (string) ($relationshipAttributes['id'] ?? '');
            $target = $rels[$relationshipId] ?? null;
            if ($target === null) {
                continue;
            }

            $sheetXml = $this->xml($zip->getFromName('xl/'.$target) ?: '');
            $sheets[] = [
                'name' => (string) ($attributes['name'] ?? ''),
                'rows' => $this->rows($sheetXml, $sharedStrings),
            ];
        }

        $zip->close();

        return $sheets;
    }

    /** @return array<int, string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $content = $zip->getFromName('xl/sharedStrings.xml');
        if (! is_string($content) || $content === '') {
            return [];
        }

        $xml = $this->xml($content);
        $strings = [];
        foreach ($xml->si ?? [] as $si) {
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
                continue;
            }

            $text = '';
            foreach ($si->r ?? [] as $run) {
                $text .= (string) ($run->t ?? '');
            }
            $strings[] = $text;
        }

        return $strings;
    }

    /** @return array<string, string> */
    private function relationships(ZipArchive $zip): array
    {
        $xml = $this->xml($zip->getFromName('xl/_rels/workbook.xml.rels') ?: '');
        $relationships = [];

        foreach ($xml->Relationship ?? [] as $relationship) {
            $attributes = $relationship->attributes();
            $id = (string) ($attributes['Id'] ?? '');
            $target = (string) ($attributes['Target'] ?? '');
            if ($id !== '' && $target !== '') {
                $relationships[$id] = $target;
            }
        }

        return $relationships;
    }

    /** @return array<int, array<int, string>> */
    private function rows(SimpleXMLElement $sheetXml, array $sharedStrings): array
    {
        $rows = [];

        foreach ($sheetXml->sheetData->row ?? [] as $row) {
            $values = [];
            foreach ($row->c ?? [] as $cell) {
                $attributes = $cell->attributes();
                $column = $this->columnIndex((string) ($attributes['r'] ?? ''));
                $type = (string) ($attributes['t'] ?? '');
                $value = '';

                if ($type === 'inlineStr') {
                    $value = (string) ($cell->is->t ?? '');
                } elseif ($type === 's') {
                    $value = $sharedStrings[(int) ($cell->v ?? 0)] ?? '';
                } else {
                    $value = (string) ($cell->v ?? '');
                }

                $values[$column] = $value;
            }

            if ($values !== []) {
                ksort($values);
                $rows[] = $values;
            }
        }

        return $rows;
    }

    private function columnIndex(string $cellReference): int
    {
        preg_match('/^[A-Z]+/i', $cellReference, $match);
        $letters = strtoupper($match[0] ?? 'A');
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    private function normalizeHeading(string $heading): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $heading) ?? $heading));
    }

    private function xml(string $content): SimpleXMLElement
    {
        $xml = simplexml_load_string($content);
        if (! $xml instanceof SimpleXMLElement) {
            throw ValidationException::withMessages(['file' => 'The review workbook has invalid XML.']);
        }

        return $xml;
    }
}
