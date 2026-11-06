<?php

namespace App\Services\Procurement;

use App\Models\ProcurementSupplierOrderLine;
use Illuminate\Validation\ValidationException;

/** One parser and validator for both spreadsheet paste and CSV uploads. */
final class SupplierImportPreview
{
    public function parse(string $contents, string $type, bool $paste, callable $header): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);
        $delimiter = $paste ? "\t" : ',';
        $number = 0;
        do {
            $first = fgetcsv($stream, 0, $delimiter);
            $number++;
        } while ($first !== false && $this->blank($first));
        if ($first === false) {
            throw ValidationException::withMessages(['file' => 'There are no data rows to review.']);
        }
        $normalized = array_map($header, $first);
        $required = $type === 'order' ? ['sku', 'order_id', 'quantity_ordered', 'eta'] : ['order_id', 'sku', 'quantity_received'];
        $hasHeader = ! $paste || array_diff($required, $normalized) === [];
        if ($hasHeader && ($missing = array_diff($required, $normalized)) !== []) {
            fclose($stream);
            throw ValidationException::withMessages(['file' => 'Missing column(s): '.implode(', ', $missing).'.']);
        }
        $labels = $hasHeader ? $first : ($type === 'receipt' ? ['Order ID', 'SKU', 'Quantity Received'] : match (count($first)) {
            4 => ['SKU', 'Quantity Ordered', 'Order ID', 'ETA Date'],
            5 => ['Item', 'SKU', 'Quantity Ordered', 'Order ID', 'ETA Date'],
            default => ['Item', 'SKU', 'Product', 'Vendor', 'Quantity Ordered', 'Order ID', 'ETA Date'],
        });
        $headers = array_map($header, $labels);
        $rows = [];
        $append = function (array $values, int $rowNumber) use (&$rows, $headers, $labels): void {
            if ($this->blank($values)) {
                return;
            }
            $row = [];
            foreach ($headers as $index => $key) {
                $row[$key] = trim((string) ($values[$index] ?? ''));
            }
            $row['_row'] = $rowNumber;
            $row['_source_row'] = $row['original_row'] ?? $rowNumber;
            $row['_headers'] = $labels;
            $row['_values'] = $values;
            $row['_saved'] = false;
            $row['_shape_error'] = count($values) !== count($headers) ? 'The number of values does not match the column headings. Check separators and quoted cells.' : null;
            $rows[] = $row;
        };
        if (! $hasHeader) {
            $append($first, $number);
        }
        while (($values = fgetcsv($stream, 0, $delimiter)) !== false) {
            $append($values, ++$number);
        }
        fclose($stream);
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'There are no data rows to review.']);
        }

        return $rows;
    }

    public function validate(array $rows, string $type, callable $validate): array
    {
        $seen = [];
        $groups = [];
        foreach ($rows as $index => &$row) {
            $errors = $validate($row, $type);
            if (! empty($row['_shape_error'])) {
                $errors[] = $row['_shape_error'];
            }
            $key = mb_strtoupper(trim((string) ($row['order_id'] ?? ''))).'|'.mb_strtoupper(trim((string) ($row['sku'] ?? '')));
            if ($type === 'order' && isset($seen[$key])) {
                $errors[] = 'Order ID and SKU are duplicated within this batch; the earlier entry is row '.$seen[$key].'. Only one line can be saved.';
            }
            if ($errors === [] && $type === 'order') {
                $seen[$key] = $row['_row'];
            }
            $row['_errors'] = array_values(array_unique($errors));
            $row['_valid'] = $errors === [];
            if ($row['_valid'] && $type === 'receipt') {
                $groups[$key][] = $index;
            }
            if ($type === 'receipt') {
                $line = ProcurementSupplierOrderLine::where('status', 'open')->whereRaw('UPPER(TRIM(sku)) = ?', [strtoupper((string) ($row['sku'] ?? ''))])
                    ->whereHas('order', fn ($q) => $q->where('order_number', $row['order_id'] ?? ''))->first();
                $row['_confirmed_outstanding'] = $line?->quantity_outstanding;
                $row['_staged_quantity'] = $line?->quantity_staged;
                $row['_uncertain_quantity'] = $line?->quantity_uncertain;
                $row['_available_to_receive'] = $line?->quantity_available_to_receive;
            }
        }
        unset($row);
        foreach ($groups as $indices) {
            $first = $rows[$indices[0]];
            $line = ProcurementSupplierOrderLine::where('status', 'open')->whereRaw('UPPER(TRIM(sku)) = ?', [strtoupper($first['sku'])])
                ->whereHas('order', fn ($q) => $q->where('order_number', $first['order_id']))->first();
            if (! $line) {
                continue;
            }
            $total = array_sum(array_map(fn ($i) => (int) $rows[$i]['quantity_received'], $indices));
            if ($total > $line->quantity_available_to_receive) {
                foreach ($indices as $i) {
                    $rows[$i]['_errors'][] = "These rows together receive {$total}. Only {$line->quantity_available_to_receive} units remain available to receive; {$line->quantity_staged} are already staged.";
                    $rows[$i]['_valid'] = false;
                }
            }
        }

        return $rows;
    }

    private function blank(array $values): bool
    {
        return count(array_filter($values,fn ($value) => trim((string) $value) !== '')) === 0;
    }
}
