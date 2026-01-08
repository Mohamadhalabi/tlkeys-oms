<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\ProductBranch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithBatchInserts;

/**
 * Supports:
 * - title as JSON in one cell: {"en":"...","ar":"..."}
 * - OR two columns: title_en, title_ar
 * - Optional cost price in column "cost_price" (or "cost")
 * Also updates stock for the selected branch.
 * NOW ALSO UPDATES: price and sale_price if changed.
 */
class ProductsImport implements ToCollection, WithHeadingRow, WithChunkReading, WithBatchInserts, ShouldQueue
{
    public function __construct(private int $branchId) {}

    public function collection(Collection $rows)
    {
        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                $sku = trim((string) ($row['sku'] ?? ''));
                if ($sku === '') continue;

                $cost = $this->getCostPrice($row); // nullable
                $price = $this->toFloat($row['price'] ?? null);
                $salePrice = $this->toFloat($row['sale_price'] ?? null);
                $weight = $this->toFloat($row['weight'] ?? null);
                $image = (string) ($row['image'] ?? null);

                // 1. Create if missing (If SKU exists, this just fetches the model)
                $product = Product::firstOrCreate(
                    ['sku' => $sku],
                    [
                        // These are only used if the product is NEW
                        'title'       => $this->parseTitle($row),
                        'price'       => $price ?? 0,
                        'sale_price'  => $salePrice,
                        'cost_price'  => $cost,
                        'weight'      => $weight,
                        'image'       => $image,
                    ]
                );

                // 2. Determine if we need to update existing fields (Price, Sale Price, Cost)
                $updates = [];

                // Update Price if provided and different
                if ($price !== null && (float) $product->price !== $price) {
                    $updates['price'] = $price;
                }

                // Update Sale Price if provided and different
                if ($salePrice !== null && (float) $product->sale_price !== $salePrice) {
                    $updates['sale_price'] = $salePrice;
                }

                // Update Cost Price if provided and different
                if ($cost !== null && (float) $product->cost_price !== (float) $cost) {
                    $updates['cost_price'] = $cost;
                }

                // If you also want to update weight/image for existing products, add them here:
                /*
                if ($weight !== null && (float) $product->weight !== $weight) {
                    $updates['weight'] = $weight;
                }
                if ($image !== '' && $product->image !== $image) {
                    $updates['image'] = $image;
                }
                */

                // Perform the update if there are changes
                if (!empty($updates)) {
                    $product->update($updates);
                }

                // 3. Update Branch Stock
                ProductBranch::updateOrCreate(
                    ['product_id' => $product->id, 'branch_id' => $this->branchId],
                    [
                        'stock'       => (int) ($row['stock'] ?? 0),
                        'stock_alert' => (int) ($row['stock_alert'] ?? 0),
                    ]
                );
            }
        });
    }

    /** Build translations:
     * 1) Try title_en/title_ar
     * 2) Else decode JSON from "title" (handles double-encoded / curly quotes)
     * 3) Else use the raw string for both
     */
    private function parseTitle(Collection|array $row): array
    {
        $row = $row instanceof Collection ? $row->toArray() : $row;

        $en  = (string) ($row['title_en'] ?? '');
        $ar  = (string) ($row['title_ar'] ?? '');
        $raw = isset($row['title']) ? (string) $row['title'] : '';

        if ($en === '' && $ar === '' && $raw !== '') {
            foreach ($this->titleCandidates($raw) as $cand) {
                $decoded = $this->deepJsonDecode($cand);
                if (is_array($decoded)) {
                    $en = (string) ($decoded['en'] ?? $decoded['EN'] ?? $en);
                    $ar = (string) ($decoded['ar'] ?? $decoded['AR'] ?? $ar);
                    if ($en !== '' || $ar !== '') break;
                }
            }
        }

        if ($en === '' && $ar === '') {
            $en = $raw;
            $ar = $raw;
        }

        return ['en' => $en, 'ar' => $ar !== '' ? $ar : $en];
    }

    /** Optional cost price helper. Accepts headers:
     * - cost_price (recommended)
     * - cost
     * Values like "12,34" are normalized.
     */
    private function getCostPrice(Collection|array $row): ?float
    {
        $row = $row instanceof Collection ? $row->toArray() : $row;

        $candidates = [
            $row['cost_price'] ?? null,
            $row['cost'] ?? null,
        ];

        foreach ($candidates as $v) {
            $f = $this->toFloat($v);
            if ($f !== null) return $f;
        }
        return null;
    }

    private function titleCandidates(string $s): array
    {
        $curlyOpen  = "\xE2\x80\x9C"; // “
        $curlyClose = "\xE2\x80\x9D"; // ”
        $s1 = str_replace([$curlyOpen, $curlyClose], '"', $s);

        return array_unique(array_filter([
            trim($s1),
            trim($s1, "\"'"),
            stripslashes($s1),
            stripslashes(trim($s1, "\"'")),
            html_entity_decode($s1, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ]));
    }

    // Decode up to 3 times in case the cell contains a JSON-encoded JSON string
    private function deepJsonDecode(string $value, int $maxDepth = 3)
    {
        $current = $value;
        for ($i = 0; $i < $maxDepth; $i++) {
            $decoded = json_decode($current, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $current = stripslashes($current);
                $decoded = json_decode($current, true);
            }
            if (json_last_error() === JSON_ERROR_NONE) {
                if (is_array($decoded)) return $decoded;
                if (is_string($decoded)) { $current = $decoded; continue; }
            }
            break;
        }
        return null;
    }

    private function toFloat($v): ?float
    {
        if ($v === null) return null;
        $v = trim((string) $v);
        if ($v === '') return null;
        return (float) str_replace(',', '.', $v);
    }

    public function chunkSize(): int { return 1000; }
    public function batchSize(): int { return 1000; }
}