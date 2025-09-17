<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Illuminate\Support\Collection;

class ProductsImport implements ToCollection, WithHeadingRow, SkipsOnFailure
{
    use SkipsFailures;
    public function __construct(public int $branchId) {}


    public function collection(Collection $rows) {
        foreach ($rows as $row) {
            $sku = trim((string)$row['sku']); if (!$sku) continue;
            $product = Product::updateOrCreate(
                ['sku'=>$sku],
                [
                    'title' => $row['title'] ?? $sku,
                    'price' => (float)($row['price'] ?? 0),
                    'sale_price' => filled($row['sale_price']) ? (float)$row['sale_price'] : null,
                    'weight' => filled($row['weight']) ? (float)$row['weight'] : null,
                    ]
                );

                $stock = (int)($row['stock'] ?? 0);
                $alert = (int)($row['stock_alert'] ?? 0);
                $product->branches()->syncWithoutDetaching([
                    $this->branchId => ['stock'=>$stock, 'stock_alert'=>$alert],
                ]);
            }
        }
}
