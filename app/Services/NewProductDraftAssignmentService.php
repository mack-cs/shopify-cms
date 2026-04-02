<?php

namespace App\Services;

use App\Models\NewProductDraft;
use App\Models\NewProductDraftAssignment;
use App\Models\StyleProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Csv\Writer;
use SplTempFileObject;

final class NewProductDraftAssignmentService
{
    /**
     * @return array<string, string>
     */
    public function contextColumnOptions(): array
    {
        $options = [];

        foreach ($this->contextColumns() as $key => $definition) {
            $options[$key] = $definition['label'];
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function workColumnOptions(): array
    {
        $options = [];

        foreach ($this->workColumns() as $key => $definition) {
            $options[$key] = $definition['label'];
        }

        return $options;
    }

    /**
     * @param iterable<int, NewProductDraft> $records
     */
    public function createAssignment(iterable $records, array $data, ?User $sender = null): NewProductDraftAssignment
    {
        $drafts = collect($records)
            ->filter(fn ($record) => $record instanceof NewProductDraft)
            ->values();

        if ($drafts->isEmpty()) {
            throw new \InvalidArgumentException('Select at least one draft.');
        }

        $selectedColumns = $this->normalizeSelectedColumns($data['selected_columns'] ?? []);
        if (empty($selectedColumns)) {
            throw new \InvalidArgumentException('Choose at least one work column.');
        }

        $contextColumns = $this->normalizeContextColumns($data['context_columns'] ?? []);
        $toEmails = $this->parseEmails($data['to_emails'] ?? null);
        if (empty($toEmails)) {
            throw new \InvalidArgumentException('Provide at least one recipient email address.');
        }

        $ccEmails = $this->parseEmails($data['cc_emails'] ?? null);
        $fromName = $this->nullIfEmpty($data['from_name'] ?? null) ?? $sender?->name;
        $fromEmail = $this->nullIfEmpty($data['from_email'] ?? null) ?? $sender?->email;
        if ($fromEmail === null) {
            throw new \InvalidArgumentException('Provide a From email address.');
        }

        $subject = $this->nullIfEmpty($data['subject'] ?? null)
            ?? 'New product draft assignment';
        $body = $this->nullIfEmpty($data['body'] ?? null);

        return DB::transaction(function () use (
            $drafts,
            $sender,
            $fromName,
            $fromEmail,
            $toEmails,
            $ccEmails,
            $subject,
            $body,
            $contextColumns,
            $selectedColumns
        ): NewProductDraftAssignment {
            $assignment = NewProductDraftAssignment::create([
                'sent_by' => $sender?->id,
                'status' => 'queued',
                'from_name' => $fromName,
                'from_email' => $fromEmail,
                'to_emails' => $toEmails,
                'cc_emails' => $ccEmails ?: null,
                'subject' => $subject,
                'body' => $body,
                'context_columns' => $contextColumns,
                'selected_columns' => $selectedColumns,
            ]);

            $assignment->items()->createMany(
                $drafts->map(fn (NewProductDraft $draft): array => [
                    'new_product_draft_id' => $draft->id,
                    'handle' => $draft->handle,
                    'title' => $draft->title,
                ])->all()
            );

            $csvPath = $this->storeCsv($assignment, $drafts, $contextColumns, $selectedColumns);

            $assignment->update([
                'csv_disk' => 'local',
                'csv_path' => $csvPath,
            ]);

            $this->log(
                $assignment,
                'created',
                $sender?->id,
                'Assignment queued for email delivery.',
                [
                    'draft_count' => $drafts->count(),
                    'to' => $toEmails,
                    'cc' => $ccEmails,
                    'context_columns' => $contextColumns,
                    'selected_columns' => $selectedColumns,
                ]
            );

            return $assignment->fresh(['items', 'logs', 'sender']);
        });
    }

    public function markSent(NewProductDraftAssignment $assignment): void
    {
        $assignment->update([
            'status' => 'sent',
            'sent_at' => now(),
            'error_message' => null,
        ]);

        $this->log(
            $assignment,
            'sent',
            $assignment->sent_by,
            'Assignment email sent.',
            [
                'to' => $assignment->to_emails,
                'cc' => $assignment->cc_emails,
            ]
        );
    }

    public function markFailed(NewProductDraftAssignment $assignment, \Throwable $e): void
    {
        $assignment->update([
            'status' => 'failed',
            'error_message' => $e->getMessage(),
        ]);

        $this->log(
            $assignment,
            'failed',
            $assignment->sent_by,
            'Assignment email failed.',
            [
                'error' => $e->getMessage(),
            ]
        );
    }

    public function labelForColumn(string $key): string
    {
        $columns = $this->allColumns();
        return $columns[$key]['label'] ?? $key;
    }

    /**
     * @param array<int, string> $contextColumns
     * @param array<int, string> $selectedColumns
     */
    private function storeCsv(
        NewProductDraftAssignment $assignment,
        Collection $drafts,
        array $contextColumns,
        array $selectedColumns
    ): string {
        $headers = ['Handle'];

        foreach ($contextColumns as $key) {
            $definition = $this->allColumns()[$key] ?? null;
            if ($definition === null || in_array($definition['label'], $headers, true)) {
                continue;
            }
            $headers[] = $definition['label'];
        }

        foreach ($selectedColumns as $key) {
            $definition = $this->allColumns()[$key] ?? null;
            if ($definition === null || in_array($definition['label'], $headers, true)) {
                continue;
            }
            $headers[] = $definition['label'];
        }

        $styleProfiles = StyleProfile::query()
            ->whereIn('handle', $drafts->pluck('handle')->filter()->all())
            ->get()
            ->keyBy('handle');

        $writer = Writer::createFromFileObject(new SplTempFileObject());
        $writer->insertOne($headers);

        foreach ($drafts as $draft) {
            $styleProfile = $draft->handle ? $styleProfiles->get($draft->handle) : null;
            $row = ['Handle' => $draft->handle ?? ''];

            foreach ($contextColumns as $key) {
                $definition = $this->allColumns()[$key] ?? null;
                if ($definition === null) {
                    continue;
                }

                $row[$definition['label']] = $this->valueForColumn($key, $draft, $styleProfile);
            }

            foreach ($selectedColumns as $key) {
                $definition = $this->allColumns()[$key] ?? null;
                if ($definition === null) {
                    continue;
                }

                $row[$definition['label']] = $this->valueForColumn($key, $draft, $styleProfile);
            }

            $writer->insertOne(array_map(
                fn (string $header): string => (string) ($row[$header] ?? ''),
                $headers
            ));
        }

        $timestamp = now()->format('Ymd_His');
        $path = "assignments/new_product_drafts_assignment_{$assignment->id}_{$timestamp}.csv";
        Storage::disk('local')->put($path, $writer->toString());

        $this->log(
            $assignment,
            'csv_generated',
            $assignment->sent_by,
            'Assignment CSV generated.',
            ['csv_path' => $path]
        );

        return $path;
    }

    private function valueForColumn(string $key, NewProductDraft $draft, ?StyleProfile $styleProfile): string
    {
        return match ($key) {
            'sku' => trim((string) ($draft->sku ?? '')),
            'title' => trim((string) ($draft->title ?? '')),
            'vendor' => trim((string) ($draft->vendor ?? '')),
            'type' => trim((string) ($draft->type ?? '')),
            'status' => trim((string) ($draft->status ?? '')),
            'batch' => trim((string) ($draft->batch ?? '')),
            'published' => trim((string) ($draft->published ?? '')),
            'product_category' => trim((string) ($draft->product_category ?? '')),
            'google_product_category' => trim((string) ($draft->google_product_category ?? '')),
            'color_string' => trim((string) ($draft->color_string ?? '')),
            'tags' => trim((string) ($draft->tags ?? '')),
            'body_html' => trim((string) ($draft->body_html ?? '')),
            'variant_price' => trim((string) ($draft->variant_price ?? '')),
            'variant_compare_at_price' => trim((string) ($draft->variant_compare_at_price ?? '')),
            'variant_inventory_qty' => trim((string) ($draft->variant_inventory_qty ?? '')),
            'material_cost' => trim((string) ($draft->material_cost ?? '')),
            'jewelry_material' => trim((string) ($draft->jewelry_material ?? '')),
            'product_materials' => trim((string) ($draft->product_materials ?? '')),
            'materials_and_dimensions' => trim((string) ($draft->materials_and_dimensions ?? '')),
            'product_design' => trim((string) ($draft->product_design ?? '')),
            'metal' => trim((string) ($draft->metal ?? '')),
            'colour_style' => trim((string) ($draft->colour_style ?? '')),
            'size' => trim((string) ($draft->size ?? '')),
            'siblings' => trim((string) ($draft->siblings ?? '')),
            'siblings_collection_name' => trim((string) ($draft->siblings_collection_name ?? '')),
            'sibling_collection' => trim((string) ($draft->sibling_collection ?? '')),
            'uvp_short_paragraph' => trim((string) ($draft->uvp_short_paragraph ?? '')),
            'complementary_products' => trim((string) ($draft->complementary_products ?? '')),
            'draft_seo_title' => trim((string) ($styleProfile?->draft_seo_title ?? '')),
            'draft_seo_description' => trim((string) ($styleProfile?->draft_seo_description ?? '')),
            'style_materials' => trim((string) ($styleProfile?->materials ?? '')),
            'style_components' => trim((string) ($styleProfile?->components ?? '')),
            'style_colour_prompt' => trim((string) ($styleProfile?->colour_prompt ?? '')),
            'draft_title' => trim((string) ($styleProfile?->draft_title ?? '')),
            'draft_description' => trim((string) ($styleProfile?->draft_description ?? '')),
            'draft_image_alt_text' => trim((string) ($styleProfile?->draft_image_alt_text ?? '')),
            default => '',
        };
    }

    /**
     * @param array<int, mixed> $value
     * @return array<int, string>
     */
    private function normalizeContextColumns(array $value): array
    {
        $allowed = array_keys($this->contextColumns());
        $selected = array_values(array_filter(array_map(
            fn ($item): string => is_string($item) ? trim($item) : '',
            $value
        )));

        $selected = array_values(array_intersect($selected, $allowed));

        if (!in_array('title', $selected, true)) {
            array_unshift($selected, 'title');
        }
        if (!in_array('sku', $selected, true)) {
            $selected[] = 'sku';
        }

        return array_values(array_unique($selected));
    }

    /**
     * @param array<int, mixed> $value
     * @return array<int, string>
     */
    private function normalizeSelectedColumns(array $value): array
    {
        $allowed = array_keys($this->workColumns());
        $selected = array_values(array_filter(array_map(
            fn ($item): string => is_string($item) ? trim($item) : '',
            $value
        )));

        return array_values(array_unique(array_intersect($selected, $allowed)));
    }

    /**
     * @return array<int, string>
     */
    private function parseEmails(mixed $value): array
    {
        if (!is_string($value)) {
            return [];
        }

        $parts = preg_split('/[\s,;]+/', $value) ?: [];
        $emails = [];

        foreach ($parts as $part) {
            $email = strtolower(trim($part));
            if ($email === '') {
                continue;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException("Invalid email address: {$email}");
            }

            $emails[] = $email;
        }

        return array_values(array_unique($emails));
    }

    private function nullIfEmpty(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function log(
        NewProductDraftAssignment $assignment,
        string $action,
        ?int $userId,
        ?string $message = null,
        array $meta = []
    ): void {
        $assignment->logs()->create([
            'user_id' => $userId,
            'action' => $action,
            'message' => $message,
            'meta' => empty($meta) ? null : $meta,
        ]);
    }

    /**
     * @return array<string, array{label:string}>
     */
    private function allColumns(): array
    {
        return $this->contextColumns() + $this->workColumns();
    }

    /**
     * @return array<string, array{label:string}>
     */
    private function contextColumns(): array
    {
        return [
            'title' => ['label' => 'Title'],
            'sku' => ['label' => 'SKU'],
            'vendor' => ['label' => 'Vendor'],
            'type' => ['label' => 'Type'],
            'status' => ['label' => 'Status'],
            'batch' => ['label' => 'Batch'],
            'product_category' => ['label' => 'Product Category'],
            'google_product_category' => ['label' => 'Google Product Category'],
            'color_string' => ['label' => 'Colors'],
            'tags' => ['label' => 'Tags'],
            'published' => ['label' => 'Published'],
        ];
    }

    /**
     * @return array<string, array{label:string}>
     */
    private function workColumns(): array
    {
        return [
            'body_html' => ['label' => 'Description'],
            'variant_price' => ['label' => 'Price'],
            'variant_compare_at_price' => ['label' => 'Compare-at Price'],
            'variant_inventory_qty' => ['label' => 'Inventory'],
            'material_cost' => ['label' => 'Material Cost'],
            'jewelry_material' => ['label' => 'Jewelry Material'],
            'product_materials' => ['label' => 'Product Materials'],
            'materials_and_dimensions' => ['label' => 'Materials and Dimensions'],
            'product_design' => ['label' => 'Product Design'],
            'metal' => ['label' => 'Metal'],
            'colour_style' => ['label' => 'Pattern Category'],
            'size' => ['label' => 'Size'],
            'siblings' => ['label' => 'Siblings'],
            'siblings_collection_name' => ['label' => 'Siblings Collection Name'],
            'sibling_collection' => ['label' => 'Sibling Collection'],
            'uvp_short_paragraph' => ['label' => 'UVP Short Paragraph'],
            'complementary_products' => ['label' => 'Complementary Products'],
            'draft_seo_title' => ['label' => 'SEO Title'],
            'draft_seo_description' => ['label' => 'SEO Description'],
            'style_materials' => ['label' => 'Style Materials'],
            'style_components' => ['label' => 'Style Components'],
            'style_colour_prompt' => ['label' => 'Colour Prompt'],
            'draft_title' => ['label' => 'Draft Title'],
            'draft_description' => ['label' => 'Draft Description'],
            'draft_image_alt_text' => ['label' => 'Draft Image Alt Text'],
        ];
    }
}
