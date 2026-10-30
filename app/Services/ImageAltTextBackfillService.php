<?php

namespace App\Services;

use App\Models\Image;
use App\Models\Product;
use App\Models\Variant;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ImageAltTextBackfillService
{
    public function downloadActive(): StreamedResponse
    {
        $filename = 'image-alt-text-template-'.now()->format('Y-m-d').'.xlsx';

        return response()->streamDownload(function (): void {
            $path = tempnam(sys_get_temp_dir(), 'alt-text-');
            if ($path === false) {
                return;
            }

            try {
                $this->writeTemplate($path);
                $bytes = file_get_contents($path);
                if (is_string($bytes)) {
                    echo $bytes;
                }
            } finally {
                @unlink($path);
            }
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * @return array{updated:int, unchanged:int, skipped_blank:int, unmatched:array<int, array{row:int, sku:string, position:int, reason:string}>}
     */
    public function importFromPath(string $path): array
    {
        $handle = fopen($path, 'rb');
        $magic = $handle === false ? '' : (string) fread($handle, 2);
        if (is_resource($handle)) {
            fclose($handle);
        }

        if ($magic === 'PK') {
            return $this->import($this->csvFromXlsx($path));
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('The alt-text file could not be read.');
        }

        return $this->import($contents);
    }

    /**
     * @return array{updated:int, unchanged:int, skipped_blank:int, unmatched:array<int, array{row:int, sku:string, position:int, reason:string}>}
     */
    public function import(string $contents): array
    {
        $contents = trim(str_replace("\r\n", "\n", $contents));
        if ($contents === '') {
            throw new \InvalidArgumentException('The alt-text file is empty.');
        }

        $lines = array_values(array_filter(explode("\n", $contents), fn (string $line): bool => trim($line) !== ''));
        $delimiter = substr_count($lines[0], "\t") > substr_count($lines[0], ',') ? "\t" : ',';
        $first = array_map([$this, 'header'], str_getcsv($lines[0], $delimiter));
        $hasHeader = in_array('sku', $first, true) && (in_array('alt_text', $first, true) || in_array('position', $first, true));
        $headers = $hasHeader
            ? $first
            : (count(str_getcsv($lines[0], $delimiter)) >= 3
                ? ['sku', 'position', 'alt_text']
                : ['sku', 'alt_text']);
        $dataLines = $hasHeader ? array_slice($lines, 1) : $lines;

        $updated = 0;
        $unchanged = 0;
        $skippedBlank = 0;
        $unmatched = [];

        foreach ($dataLines as $offset => $line) {
            $rowNumber = $offset + ($hasHeader ? 2 : 1);
            $values = str_getcsv($line, $delimiter);
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = trim((string) ($values[$index] ?? ''));
            }

            $altText = trim((string) ($row['alt_text'] ?? ''));
            if ($altText === '') {
                $skippedBlank++;
                continue;
            }

            $identity = $this->identity((string) ($row['sku'] ?? ''), (string) ($row['position'] ?? ''));
            if ($identity['sku'] === '' || $identity['position'] < 1) {
                $unmatched[] = [
                    'row' => $rowNumber,
                    'sku' => (string) ($row['sku'] ?? ''),
                    'position' => max(0, $identity['position']),
                    'reason' => 'SKU and a positive image position are required.',
                ];
                continue;
            }

            $product = $this->productForSku($identity['sku']);
            if (!$product instanceof Product) {
                $unmatched[] = [
                    'row' => $rowNumber,
                    'sku' => $identity['sku'],
                    'position' => $identity['position'],
                    'reason' => $product === null
                        ? "No product variant matched SKU {$identity['sku']}."
                        : "SKU {$identity['sku']} matches more than one product.",
                ];
                continue;
            }

            $images = $this->imagesAtPosition($product, $identity['position']);
            if ($images === []) {
                $unmatched[] = [
                    'row' => $rowNumber,
                    'sku' => $identity['sku'],
                    'position' => $identity['position'],
                    'reason' => "SKU {$identity['sku']} has no image at position {$identity['position']}.",
                ];
                continue;
            }

            foreach ($images as $image) {
                if (trim((string) $image->alt_text) === $altText) {
                    $unchanged++;
                    continue;
                }

                Image::withoutEvents(function () use ($image, $altText): void {
                    $image->forceFill([
                        'alt_text' => $altText,
                    ])->save();
                });
                $updated++;
            }
        }

        return [
            'updated' => $updated,
            'unchanged' => $unchanged,
            'skipped_blank' => $skippedBlank,
            'unmatched' => $unmatched,
        ];
    }

    /**
     * @return array{sku:string, position:int}
     */
    private function identity(string $sku, string $position): array
    {
        $sku = trim($sku);
        if ($position !== '' && ctype_digit($position) && (int) $position >= 1) {
            $explicit = (int) $position;
            if ($this->productForSku($sku) instanceof Product) {
                return ['sku' => $sku, 'position' => $explicit];
            }

            if (preg_match('/^(.+)-(\d+)$/', $sku, $matches) === 1 && (int) $matches[2] === $explicit) {
                $base = trim($matches[1]);
                if ($base !== '' && $this->productForSku($base) instanceof Product) {
                    return ['sku' => $base, 'position' => $explicit];
                }
            }

            return ['sku' => $sku, 'position' => $explicit];
        }

        if ($sku !== '' && $this->productForSku($sku) instanceof Product) {
            return ['sku' => $sku, 'position' => 1];
        }

        if (preg_match('/^(.+)-(\d+)$/', $sku, $matches) === 1) {
            $base = trim($matches[1]);
            $suffix = (int) $matches[2];
            if ($suffix >= 1 && $base !== '' && $this->productForSku($base) instanceof Product) {
                return ['sku' => $base, 'position' => $suffix];
            }
        }

        return ['sku' => $sku, 'position' => $position === '' ? 1 : 0];
    }

    private function productForSku(string $sku): Product|false|null
    {
        $normalized = mb_strtolower(trim($sku));
        if ($normalized === '') {
            return null;
        }

        $productIds = Variant::query()
            ->active()
            ->whereRaw('LOWER(TRIM(sku)) = ?', [$normalized])
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($productIds->count() > 1) {
            return false;
        }

        $productId = $productIds->first();
        if ($productId === null) {
            return null;
        }

        return Product::query()->find($productId);
    }

    /**
     * @return array<int, Image>
     */
    private function imagesAtPosition(Product $product, int $position): array
    {
        $images = Image::query()
            ->active()
            ->where('product_id', $product->id)
            ->where('position', $position)
            ->orderBy('id')
            ->get();

        if ($images->isEmpty() && $position === 1) {
            $images = Image::query()
                ->active()
                ->where('product_id', $product->id)
                ->whereNull('position')
                ->orderBy('id')
                ->get();
        }

        return $images->all();
    }

    private function writeTemplate(string $path): void
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Excel export requires the PHP zip extension.');
        }

        $sheetPath = tempnam(sys_get_temp_dir(), 'alt-sheet-');
        if ($sheetPath === false) {
            throw new \RuntimeException('The alt-text template could not be created.');
        }

        $sheet = fopen($sheetPath, 'wb');
        if ($sheet === false) {
            @unlink($sheetPath);
            throw new \RuntimeException('The alt-text template could not be created.');
        }

        fwrite($sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
        fwrite($sheet, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">');
        fwrite($sheet, '<cols><col min="1" max="1" width="16" customWidth="1"/><col min="2" max="2" width="12" customWidth="1"/><col min="3" max="3" width="44" customWidth="1"/><col min="4" max="4" width="36" customWidth="1"/><col min="5" max="5" width="54" customWidth="1"/><col min="6" max="6" width="24" customWidth="1"/></cols>');
        fwrite($sheet, '<sheetData>');
        $this->writeSheetRow($sheet, 1, ['SKU', 'Position', 'Alt Text', 'Product', 'Shopify Image', 'Preview'], false);

        $rowNumber = 1;
        Image::query()
            ->active()
            ->whereHas('product', fn ($query) => $query->whereRaw('LOWER(status) = ?', ['active']))
            ->with([
                'imageAsset',
                'product.variants' => fn ($query) => $query->orderBy('id'),
            ])
            ->orderBy('product_id')
            ->orderByRaw('CASE WHEN position IS NULL THEN 1 ELSE 0 END')
            ->orderBy('position')
            ->orderBy('id')
            ->lazy(200)
            ->each(function ($image) use ($sheet, &$rowNumber): void {
                if (! $image instanceof Image) {
                    return;
                }

                $sku = trim((string) ($image->product?->variants->first()?->sku ?? ''));
                $rowNumber++;
                $this->writeSheetRow($sheet, $rowNumber, [
                    $sku,
                    (string) (int) ($image->position ?? 1),
                    (string) ($image->alt_text ?? ''),
                    (string) ($image->product?->title ?? ''),
                    $this->shopifyImageUrl($image),
                ], true);
            });

        fwrite($sheet, '</sheetData></worksheet>');
        fclose($sheet);

        @unlink($path);
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE) !== true) {
            @unlink($sheetPath);
            throw new \RuntimeException('The alt-text template could not be created.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Alt text" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($sheetPath);
    }

    /**
     * @param resource $sheet
     * @param array<int, string> $values
     */
    private function writeSheetRow($sheet, int $rowNumber, array $values, bool $preview): void
    {
        $height = $preview ? ' ht="78" customHeight="1"' : '';
        fwrite($sheet, '<row r="'.$rowNumber.'"'.$height.'>');
        foreach ($values as $index => $value) {
            $cell = $this->columnLetter($index).$rowNumber;
            if ($preview && $index === 1 && ctype_digit($value)) {
                fwrite($sheet, '<c r="'.$cell.'"><v>'.$value.'</v></c>');
                continue;
            }

            fwrite($sheet, '<c r="'.$cell.'" t="inlineStr"><is><t xml:space="preserve">'.$this->xml($value).'</t></is></c>');
        }

        if ($preview) {
            fwrite($sheet, '<c r="F'.$rowNumber.'"><f>IF(E'.$rowNumber.'=&quot;&quot;,&quot;&quot;,IMAGE(E'.$rowNumber.'))</f></c>');
        }

        fwrite($sheet, '</row>');
    }

    private function shopifyImageUrl(Image $image): string
    {
        $src = trim((string) $image->src);
        if (preg_match('#^https?://#i', $src) === 1) {
            return $src;
        }

        return (string) ($image->backupPublicUrl() ?? '');
    }

    private function csvFromXlsx(string $path): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('The Excel file could not be read.');
        }

        $shared = $this->sharedStrings($zip->getFromName('xl/sharedStrings.xml'));
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if (! is_string($sheetXml) || $sheetXml === '') {
            throw new \RuntimeException('The Excel file has no worksheet.');
        }

        $xml = simplexml_load_string($sheetXml);
        if ($xml === false) {
            throw new \RuntimeException('The Excel worksheet could not be read.');
        }

        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $csv = fopen('php://temp', 'r+');
        if ($csv === false) {
            throw new \RuntimeException('The Excel file could not be read.');
        }

        foreach ($xml->children($ns)->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $attributes = $cell->attributes();
                $column = $this->columnIndex((string) $attributes['r']);
                $cells[$column] = $this->cellValue($cell, $shared);
            }

            if ($cells === []) {
                continue;
            }

            $width = max(array_keys($cells));
            $values = [];
            for ($index = 0; $index <= $width; $index++) {
                $values[] = $cells[$index] ?? '';
            }

            fputcsv($csv, $values);
        }

        rewind($csv);
        $contents = stream_get_contents($csv);
        fclose($csv);

        return is_string($contents) ? $contents : '';
    }

    /**
     * @return array<int, string>
     */
    private function sharedStrings(string|false $xml): array
    {
        if (! is_string($xml) || $xml === '') {
            return [];
        }

        $parsed = simplexml_load_string($xml);
        if ($parsed === false) {
            return [];
        }

        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $strings = [];
        foreach ($parsed->children($ns)->si as $item) {
            $text = (string) ($item->t ?? '');
            foreach ($item->r as $run) {
                $text .= (string) ($run->t ?? '');
            }
            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * @param array<int, string> $shared
     */
    private function cellValue(\SimpleXMLElement $cell, array $shared): string
    {
        $type = (string) $cell->attributes()['t'];
        if ($type === 'inlineStr') {
            return trim((string) ($cell->is->t ?? ''));
        }

        if (isset($cell->f) && ! isset($cell->v)) {
            return '';
        }

        $value = (string) ($cell->v ?? '');
        if ($type === 's') {
            return $shared[(int) $value] ?? '';
        }

        return $value;
    }

    private function columnLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)).$letter;
            $index = intdiv($index, 26);
        }

        return $letter;
    }

    private function columnIndex(string $reference): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($reference)) ?? '';
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function header(string $header): string
    {
        $header = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header));

        return match (str_replace([' ', '-'], '_', $header)) {
            'item_sku', 'variant_sku' => 'sku',
            'image_position', 'pos' => 'position',
            'alt', 'image_alt', 'image_alt_text' => 'alt_text',
            default => str_replace([' ', '-'], '_', $header),
        };
    }
}
