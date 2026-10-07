<?php

namespace App\Services;

use App\Models\DropdownOption;
use ZipArchive;

final class DropdownReviewWorkbookExporter
{
    /**
     * @param array<string, string> $headers
     * @param array<int, string> $collections
     */
    public function export(array $headers, array $collections): string
    {
        $sheets = $this->sheets($headers, $collections);
        $path = tempnam(sys_get_temp_dir(), 'dropdown-review-');
        if ($path === false) {
            throw new \RuntimeException('Could not create dropdown review export.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not open dropdown review workbook.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes(count($sheets)));
        $zip->addFromString('_rels/.rels', $this->rootRelationships());
        $zip->addFromString('xl/workbook.xml', $this->workbook($sheets));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships(count($sheets)));
        $zip->addFromString('xl/styles.xml', $this->styles());

        foreach (array_values($sheets) as $index => $sheet) {
            $zip->addFromString('xl/worksheets/sheet'.($index + 1).'.xml', $this->worksheet($sheet['rows']));
        }

        $zip->close();

        $contents = file_get_contents($path);
        @unlink($path);

        if ($contents === false) {
            throw new \RuntimeException('Could not read dropdown review workbook.');
        }

        return $contents;
    }

    /**
     * @param array<string, string> $headers
     * @param array<int, string> $collections
     * @return array<int, array{name:string, rows:array<int, array<int, string>>}>
     */
    private function sheets(array $headers, array $collections): array
    {
        $records = DropdownOption::query()
            ->where('active', true)
            ->whereIn('collection_style', $collections)
            ->whereIn('header', array_keys($headers))
            ->orderBy('collection_style')
            ->orderBy('header')
            ->orderBy('sort_order')
            ->orderBy('value')
            ->get(['collection_style', 'header', 'value']);

        $sheets = [];
        foreach ($collections as $collection) {
            $columns = [];
            $maxRows = 0;
            foreach ($headers as $header => $label) {
                $values = $records
                    ->where('collection_style', $collection)
                    ->where('header', $header)
                    ->pluck('value')
                    ->map(fn ($value): string => app(ShopifyTaxonomyValueNormalizer::class)->normalize($header, (string) $value))
                    ->filter(fn (string $value): bool => $value !== '')
                    ->unique(fn (string $value): string => mb_strtolower($value))
                    ->values()
                    ->all();

                $columns[] = ['label' => $label, 'values' => $values];
                $maxRows = max($maxRows, count($values));
            }

            $rows = [array_column($columns, 'label')];
            for ($rowIndex = 0; $rowIndex < $maxRows; $rowIndex++) {
                $row = [];
                foreach ($columns as $column) {
                    $row[] = $column['values'][$rowIndex] ?? '';
                }
                $rows[] = $row;
            }

            $sheets[] = [
                'name' => $this->sheetName($collection),
                'rows' => $rows,
            ];
        }

        return $sheets;
    }

    /** @param array<int, array{name:string, rows:array<int, array<int, string>>}> $sheets */
    private function workbook(array $sheets): string
    {
        $sheetXml = '';
        foreach ($sheets as $index => $sheet) {
            $id = $index + 1;
            $sheetXml .= '<sheet name="'.$this->xml($sheet['name']).'" sheetId="'.$id.'" r:id="rId'.$id.'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheetXml.'</sheets></workbook>';
    }

    private function worksheet(array $rows): string
    {
        $rowXml = '';
        foreach ($rows as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            $cellXml = '';
            foreach ($row as $columnIndex => $value) {
                $cell = $this->cellName($columnIndex, $rowNumber);
                $style = $rowNumber === 1 ? ' s="1"' : '';
                $cellXml .= '<c r="'.$cell.'" t="inlineStr"'.$style.'><is><t>'.$this->xml($value).'</t></is></c>';
            }
            $rowXml .= '<row r="'.$rowNumber.'">'.$cellXml.'</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<cols><col min="1" max="3" width="32" customWidth="1"/></cols>'
            .'<sheetData>'.$rowXml.'</sheetData>'
            .'<autoFilter ref="A1:C1"/>'
            .'</worksheet>';
    }

    private function contentTypes(int $sheetCount): string
    {
        $sheets = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$sheets
            .'</Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbookRelationships(int $sheetCount): string
    {
        $relationships = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $relationships .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }
        $relationships .= '<Relationship Id="rId'.($sheetCount + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relationships
            .'</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" applyFont="1"/></cellXfs>'
            .'</styleSheet>';
    }

    private function sheetName(string $collection): string
    {
        $name = preg_replace('/[\[\]\*\/\\\\\?:]/', ' ', $collection) ?? $collection;
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

        return mb_substr($name !== '' ? $name : 'Dropdowns', 0, 31);
    }

    private function cellName(int $columnIndex, int $rowNumber): string
    {
        $column = '';
        $index = $columnIndex + 1;
        while ($index > 0) {
            $index--;
            $column = chr(65 + ($index % 26)).$column;
            $index = intdiv($index, 26);
        }

        return $column.$rowNumber;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
