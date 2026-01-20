<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductBranch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncProductsFromApi implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 minutes

    public function __construct(
        private int $branchId,
        private string $apiUrl
    ) {}

    public function handle()
    {
        // DEBUG: If you don't see this in logs, the queue didn't restart!
        Log::info("Product Sync: VERSION CHECK v2.0 - Starting...");

        $page = 1;
        $headers = array_merge(
            config('tlkeys.crm.headers', []), 
            ['X-Currency' => config('app.currency', 'USD')]
        );

        do {
            // 1. Fetch Data
            $response = Http::timeout(60)
                ->withHeaders($headers)
                ->get($this->apiUrl, ['page' => $page]);

            if ($response->failed()) {
                Log::error("Sync Error Page $page: " . $response->body());
                return;
            }

            $json = $response->json();

            // 2. Normalize Data
            if (isset($json['data']) && is_array($json['data'])) {
                $rows = $json['data'];
            } elseif (is_array($json)) {
                $rows = $json;
            } else {
                $rows = [];
            }

            if (empty($rows)) {
                Log::info("Sync: Page $page is empty. Finishing.");
                break;
            }

            // 3. Process Rows (With Strict Type Check)
            DB::transaction(function () use ($rows) {
                foreach ($rows as $row) {
                    
                    // --- THE FIX IS HERE ---
                    // This prevents 'true' (from has_more) crashing the app
                    if (!is_array($row)) {
                        continue; 
                    }
                    
                    $this->processRow($row);
                }
            });

            Log::info("Sync: Finished Page $page");

            // 4. Pagination
            $page++;
            if (isset($json['has_more'])) {
                $hasMore = (bool) $json['has_more'];
            } else {
                $hasMore = count($rows) >= 500;
            }

        } while ($hasMore);

        Log::info("Product Sync: Completed successfully.");
    }

    private function processRow(array $row)
    {
        $sku = trim((string) ($row['sku'] ?? ''));
        if ($sku === '') return;

        // Ensure array
        $titleData = is_array($row['title']) ? $row['title'] : ['en' => $row['title'] ?? 'Unknown'];

        // 1. Create or Get
        $product = Product::firstOrCreate(
            ['sku' => $sku],
            [
                'title'       => $titleData,
                'price'       => $this->toFloat($row['price']),
                'sale_price'  => $this->toFloat($row['sale_price']),
                'weight'      => $this->toFloat($row['weight']),
                'image'       => $row['image'] ?? null,
            ]
        );

        // 2. Force Update Title
        $product->replaceTranslations('title', $titleData);

        // 3. Update Fields
        $updates = [];
        $price = $this->toFloat($row['price']);
        if ($price !== null && (float)$product->price !== $price) $updates['price'] = $price;
        
        $salePrice = $this->toFloat($row['sale_price']);
        if ($salePrice !== null && (float)$product->sale_price !== $salePrice) $updates['sale_price'] = $salePrice;

        $weight = $this->toFloat($row['weight']);
        if ($weight !== null && (float)$product->weight !== $weight) $updates['weight'] = $weight;

        $image = $row['image'] ?? null;
        if ($image && $product->image !== $image) $updates['image'] = $image;

        if (!empty($updates) || $product->isDirty('title')) {
            $product->fill($updates);
            $product->save();
        }

        // 4. Update Stock
        ProductBranch::updateOrCreate(
            ['product_id' => $product->id, 'branch_id' => $this->branchId],
            [
                'stock' => (int) ($row['stock'] ?? 0),
            ]
        );
    }

    private function toFloat($val): ?float
    {
        if ($val === null || $val === '') return null;
        return (float) $val;
    }
}