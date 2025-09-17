<?php

namespace App\Jobs;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ImportCustomersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $progressKey;
    public int $timeout = 600;           // 10 min job timeout (queue-level)
    public int $tries   = 3;             // retry if it fails

    public function __construct(string $progressKey = 'import_customers_progress')
    {
        $this->progressKey = $progressKey;
    }

    public function handle(): void
    {
        $cfg   = config('tlkeys.crm');
        $base  = rtrim($cfg['base_url'] ?? '', '/');
        $path  = $cfg['customers_endpoint'] ?? '/customers';
        $hdrs  = (array) ($cfg['headers'] ?? []);
        $maxPages = (int) ($cfg['pagination']['max_pages'] ?? 1000);
        $per  = (int) ($cfg['pagination']['per_page'] ?? 100);
        $usePagination = (bool) ($cfg['pagination']['enabled'] ?? false);

        if ($base === '') {
            Cache::put($this->progressKey, ['status' => 'error', 'message' => 'Missing CRM base URL'], 3600);
            return;
        }

        $created = 0;
        $updated = 0;
        $page    = 1;

        Cache::put($this->progressKey, ['status' => 'running', 'created' => 0, 'updated' => 0, 'page' => 0], 3600);

        if ($usePagination) {
            while (true) {
                if ($page > $maxPages) {
                    break;
                }

                $response = Http::withHeaders($hdrs)
                    ->baseUrl($base)
                    ->acceptJson()
                    ->connectTimeout(5)     // fail fast on connection issues
                    ->timeout(15)           // each page has a hard 15s cap
                    ->retry(3, 250)         // transient errors
                    ->get($path, [
                        $cfg['pagination']['page_param']     ?? 'page' => $page,
                        $cfg['pagination']['per_page_param'] ?? 'per_page' => $per,
                    ]);

                if (! $response->ok()) {
                    Cache::put($this->progressKey, [
                        'status'  => 'error',
                        'message' => "API {$response->status()} on page {$page}",
                        'created' => $created,
                        'updated' => $updated,
                        'page'    => $page,
                    ], 3600);
                    return;
                }

                $rows = $response->json();
                if (! is_array($rows) || count($rows) === 0) {
                    break; // finished
                }

                [$c, $u] = $this->upsertCustomers($rows);
                $created += $c;
                $updated += $u;

                Cache::put($this->progressKey, [
                    'status'  => 'running',
                    'created' => $created,
                    'updated' => $updated,
                    'page'    => $page,
                    'last'    => now()->toDateTimeString(),
                ], 3600);

                $page++;
            }
        } else {
            $response = Http::withHeaders($hdrs)
                ->baseUrl($base)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->retry(3, 250)
                ->get($path);

            if (! $response->ok()) {
                Cache::put($this->progressKey, [
                    'status'  => 'error',
                    'message' => 'API failed: ' . $response->status(),
                ], 3600);
                return;
            }

            $rows = $response->json();
            if (! is_array($rows)) {
                Cache::put($this->progressKey, [
                    'status'  => 'error',
                    'message' => 'Unexpected API response.',
                ], 3600);
                return;
            }

            [$created, $updated] = $this->upsertCustomers($rows);
            Cache::put($this->progressKey, [
                'status'  => 'running',
                'created' => $created,
                'updated' => $updated,
                'page'    => 1,
            ], 3600);
        }

        Cache::put($this->progressKey, [
            'status'  => 'done',
            'created' => $created,
            'updated' => $updated,
            'finished'=> now()->toDateTimeString(),
        ], 3600);
    }

    /**
     * Upsert customers from API rows.
     * Ensures address (array|string) is saved as a string to match DB schema.
     */
    private function upsertCustomers(array $rows): array
    {
        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $name   = Arr::get($row, 'name');
            $email  = Arr::get($row, 'email');
            $phone  = Arr::get($row, 'phone');

            // Address might come as array or string
            $addressVal = Arr::get($row, 'address');
            $addressStr = is_array($addressVal)
                ? $this->addressArrayToString($addressVal)
                : (is_scalar($addressVal) ? (string) $addressVal : null);

            $wallet = Arr::has($row, 'wallet_balance') ? (float) $row['wallet_balance'] : null;

            if (!$name || (!$email && !$phone)) {
                continue;
            }

            $query = Customer::query();
            if ($email) {
                $query->where('email', $email);
            } else {
                $query->where('phone', $phone);
            }

            $customer = $query->first();

            if (! $customer) {
                $customer = new Customer();
                $created++;
            } else {
                $updated++;
            }

            $customer->name    = $name;
            $customer->email   = $email;
            $customer->phone   = $phone;
            $customer->address = $addressStr ?? $customer->address;

            if ($wallet !== null && (! $customer->exists || (bool) (config('tlkeys.crm.reset_wallet_on_import') ?? false))) {
                $customer->wallet_balance = $wallet;
            }

            $customer->save();
        }

        return [$created, $updated];
    }

    private function addressArrayToString(array $addr): ?string
    {
        $parts = array_filter([
            $addr['address']      ?? null,
            $addr['street']       ?? null,
            $addr['city']         ?? null,
            $addr['state']        ?? null,
            $addr['postal_code']  ?? null,
            $addr['country_name'] ?? ($addr['country'] ?? null),
        ], fn ($v) => filled(is_scalar($v) ? (string) $v : null));

        return $parts ? implode(', ', $parts) : null;
    }
}
