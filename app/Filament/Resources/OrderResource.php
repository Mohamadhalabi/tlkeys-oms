<?php
// app/Filament/Resources/OrderResource.php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Resources\OrderResource\RelationManagers\PaymentsRelationManager;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Filament\Notifications\Notification;
use Illuminate\Validation\Rule;

class OrderResource extends \Filament\Resources\Resource
{
    protected static ?string $model = Order::class;
    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Sales';
    public static function getNavigationGroup(): ?string { return __('Sales'); }
    public static function getNavigationLabel(): string { return __('Orders'); }
    public static function getPluralModelLabel(): string { return __('Orders'); }
    public static function getModelLabel(): string { return __('Order'); }

    public static function form(Form $form): Form
    {
        $user   = Auth::user();
        $locale = app()->getLocale();

        $canSeeCost       = (bool) (($user?->can_see_cost ?? false) || $user?->hasRole('admin'));
        $canSellBelowCost = (bool) (($user?->can_sell_below_cost ?? false) || $user?->hasRole('admin'));

        // ---- Precise helpers (BCMath) ----
        $m2   = fn ($v) => bcadd((string)$v, '0', 2);               // 2 d.p. string
        $m4   = fn ($v) => bcadd((string)$v, '0', 4);               // 4 d.p. string
        $mul2 = fn ($a, $b) => bcadd(bcmul((string)$a, (string)$b, 8), '0', 2);
        $mul4 = fn ($a, $b) => bcadd(bcmul((string)$a, (string)$b, 12), '0', 4);
        $div4 = fn ($a, $b) => ($b == 0 || $b === '0') ? '0.0000' : bcadd(bcdiv((string)$a, (string)$b, 12), '0', 4);

        // (kept for lightweight derived displays that aren’t edited by user)
        $r2 = fn ($v) => round((float) $v, 2);
        $r4 = fn ($v) => round((float) $v, 4);

        $applyCurrencyOnCreate = function (string $cur, Forms\Get $get, Forms\Set $set, $record) use ($r2, $m2, $mul2) {
            if ($record) return;
            $set('currency', $cur);
            $rate = (string) CurrencyRate::getRate($cur ?: 'USD');
            if (((float)$rate) <= 0) { $rate = '1'; }

            $set('exchange_rate', (float)$rate);
            $set('discount_local', $m2($mul2(($get('discount') ?? 0), $rate)));
            $set('shipping_local', $m2($mul2(($get('shipping') ?? 0), $rate)));

            foreach (array_keys((array)($get('items') ?? [])) as $i) {
                $usdUnit = (string) ($get("items.$i.unit_price") ?? '0');
                $qty     = (string) ($get("items.$i.qty") ?? '0');
                $usdLine = (string) ($get("items.$i.line_total") ?? $mul4($qty, $usdUnit));

                $set("items.$i.unit_price_local", $m2($mul2($usdUnit, $rate)));
                $set("items.$i.line_total_local", $m2($mul2($usdLine, $rate)));
                $set("items.$i::__currency_mirror", microtime(true));
            }
        };

        $titleFor = function (Product $p, string $locale): string {
            static $tCache = [];
            if (isset($tCache[$p->id][$locale])) return $tCache[$p->id][$locale];

            $title = '';
            if (method_exists($p, 'getTranslation')) {
                try { $t = $p->getTranslation('title', $locale, false); if (!empty($t)) $title = $t; } catch (\Throwable $e) {}
            }
            if ($title === '') {
                if (is_array($p->title ?? null)) {
                    $title = $p->title[$locale] ?? ($p->title['en'] ?? reset($p->title) ?? '');
                } else {
                    $title = (string) ($p->title ?? '');
                }
            }
            return $tCache[$p->id][$locale] = $title;
        };

        $compute = function (array $items, float $discountUsd, float $shippingUsd, float $extraFeesUsd, float $rate) use ($r2): array {
            $subtotalUsd = $r2(collect($items)->sum(function ($row) {
                $qty  = (float)($row['qty'] ?? 0);
                $unit = (float)($row['unit_price'] ?? 0);
                return $qty * $unit;
            }));
            $totalUsd = $r2(max(0, $subtotalUsd - $r2($discountUsd) + $r2($shippingUsd) + $r2($extraFeesUsd)));
            return [
                'subtotal_usd' => $subtotalUsd,
                'total_usd'    => $totalUsd,
                'subtotal_fx'  => $r2($subtotalUsd * $rate),
                'total_fx'     => $r2($totalUsd * $rate),
            ];
        };

        $setNestedTotals = function (Forms\Set $set, array $t): void {
            $set('../../subtotal', $t['subtotal_usd']);
            $set('../../total', $t['total_usd']);
        };

        $extraFromPercent = function (Forms\Get $get) use ($r2) {
            $items = (array) ($get('items') ?? []);
            $subtotalUsd = $r2(collect($items)->sum(fn ($r) => (float)($r['qty'] ?? 0) * (float)($r['unit_price'] ?? 0)));
            $discountUsd = $r2((float) ($get('discount') ?? 0));
            $baseUsd = max(0, $subtotalUsd - $discountUsd);
            $percent = (float) ($get('extra_fees_percent') ?? 0);
            return $r2($baseUsd * max(0, $percent) / 100.0);
        };

        $fallbackImg = 'data:image/svg+xml;utf8,' . rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="60" height="60">
               <rect width="100%" height="100%" fill="#f3f4f6"/>
               <g fill="#9ca3af"><rect x="12" y="18" width="36" height="24" rx="3"/>
               <circle cx="30" cy="30" r="6"/></g></svg>'
        );

        $getProduct = function (int $id): ?Product {
            static $cache = [];
            if (isset($cache[$id])) return $cache[$id];
            return $cache[$id] = Product::query()
                ->select('id','sku','title','price','sale_price','cost_price','image')
                ->find($id);
        };

        $getProductStockForBranch = function (int $productId, int $branchId): ?int {
            static $cache = [];
            $key = $productId . ':' . $branchId;
            if (array_key_exists($key, $cache)) return $cache[$key];

            $prod = Product::query()
                ->select('id')
                ->with(['stocks' => fn ($q) => $q->where('branch_id', $branchId)->select('product_id','branch_id','stock')])
                ->find($productId);

            $stock = optional($prod?->stocks?->first())->stock;
            return $cache[$key] = is_null($stock) ? null : (int) $stock;
        };

        $imageUrlFor = function (?Product $p) {
            static $imgCache = [];
            if (!$p) return null;
            if (isset($imgCache[$p->id])) return $imgCache[$p->id];
            if (!$p->image) return $imgCache[$p->id] = null;

            $image = $p->image;
            $url   = filter_var($image, FILTER_VALIDATE_URL) ? $image : Storage::disk('public')->url($image);
            return $imgCache[$p->id] = $url;
        };

        $normalizeItems = function (array $items): array {
            if (empty($items)) return $items;
            $i = 1;
            foreach (array_keys($items) as $k) {
                $items[$k]['__index'] = $i;
                $items[$k]['sort']    = $i;
                $i++;
            }
            return $items;
        };

        return $form->schema([
            Forms\Components\Section::make(__('Order Info'))
                ->schema([
                    Forms\Components\Select::make('currency')
                        ->label(__('Currency'))
                        ->options(function () {
                            return Cache::remember('order_form_currency_opts', 300, function () {
                                $opts = CurrencyRate::options();
                                return ['USD' => __('US Dollar')] + $opts;
                            });
                        })
                        ->default('USD')
                        ->reactive()
                        // hydrate with precise local values from USD once
                        ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set, $record) use ($r2, $m2, $mul2) {
                            $rate = (string) (($record && $record->exchange_rate > 0) ? $record->exchange_rate : CurrencyRate::getRate($state ?: 'USD'));
                            if (((float)$rate) <= 0) $rate = '1';

                            $set('exchange_rate', (float)$rate);
                            $set('discount_local', $m2($mul2(($get('discount') ?? 0), $rate)));
                            $set('shipping_local', $m2($mul2(($get('shipping') ?? 0), $rate)));

                            foreach (array_keys((array)($get('items') ?? [])) as $i) {
                                $usdUnit = (string) ($get("items.$i.unit_price") ?? '0');
                                $qty     = (string) ($get("items.$i.qty") ?? '0');
                                $usdLine = (string) ($get("items.$i.line_total") ?? (string)$r2(((float)$qty) * ((float)$usdUnit)));

                                $set("items.$i.unit_price_local", $m2($mul2($usdUnit, $rate)));
                                $set("items.$i.line_total_local", $m2($mul2($usdLine, $rate)));
                                $set("items.$i.__currency_mirror", microtime(true));
                            }
                        })
                        // when currency really changes, recompute *local* from USD (one-way)
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set, $record) use ($m2, $mul2) {
                            if ($record) return; // don't change existing orders' money

                            $rate = (string) CurrencyRate::getRate($state ?: 'USD');
                            if (((float)$rate) <= 0) $rate = '1';

                            $set('exchange_rate', (float)$rate);
                            $set('discount_local', $m2($mul2(($get('discount') ?? 0), $rate)));
                            $set('shipping_local', $m2($mul2(($get('shipping') ?? 0), $rate)));

                            foreach (array_keys((array)($get('items') ?? [])) as $i) {
                                $usdUnit = (string) ($get("items.$i.unit_price") ?? '0');
                                $qty     = (string) ($get("items.$i.qty") ?? '0');
                                $usdLine = (string) ($get("items.$i.line_total") ?? '0');

                                $set("items.$i.unit_price_local", $m2($mul2($usdUnit, $rate)));
                                $set("items.$i.line_total_local", $m2($mul2($usdLine !== '0' ? $usdLine : $mul2($usdUnit, $qty), $rate)));
                                $set("items.$i.__currency_mirror", microtime(true));
                            }
                        })
                        ->disabled(fn ($record) => (bool) $record)
                        ->columnSpan(['sm' => 2, 'md' => 2, 'lg' => 1]),

                    Forms\Components\Hidden::make('exchange_rate')
                        ->default(fn (Forms\Get $get) => CurrencyRate::getRate($get('currency') ?: 'USD'))
                        ->dehydrated(true),

                    Forms\Components\Select::make('branch_id')
                        ->label(__('Branch'))
                        ->options(fn () =>
                            Cache::remember('order_form_branch_opts', 300, fn () =>
                                Branch::query()->orderBy('name')->pluck('name', 'id')
                            )
                        )
                        ->default(fn () => $user->hasRole('seller') ? $user->branch_id : null)
                        ->disabled(fn () => $user->hasRole('seller'))
                        ->required()
                        ->reactive()
                        ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set, $record) use ($applyCurrencyOnCreate, $getProductStockForBranch) {
                            foreach (array_keys((array)($get('items') ?? [])) as $i) {
                                $set("items.$i.__branch_mirror", (int) $state);
                                $pid = (int) ($get("items.$i.product_id") ?? 0);
                                if ($pid) $set("items.$i.stock_in_branch", $getProductStockForBranch($pid, (int)$state));
                            }
                            if (!$record && $state) {
                                $code = optional(Branch::select('id','code')->find((int)$state))->code;
                                if ($code === 'SA') $applyCurrencyOnCreate('SAR', $get, $set, $record);
                            }
                        })
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set, $record) use ($applyCurrencyOnCreate, $getProductStockForBranch) {
                            if (!$record && $state) {
                                $code = optional(Branch::select('id','code')->find((int)$state))->code;
                                if ($code === 'SA') $applyCurrencyOnCreate('SAR', $get, $set, $record);
                            }
                            $items = (array) ($get('items') ?? []);
                            foreach (array_keys($items) as $i) {
                                $set("items.$i.__branch_mirror", (int) $state);
                                $pid = (int) ($get("items.$i.product_id") ?? 0);
                                $set("items.$i.stock_in_branch", $pid ? $getProductStockForBranch($pid, (int)$state) : null);
                            }
                        })
                        ->columnSpan(['sm' => 2, 'md' => 2, 'lg' => 1]),

                    Forms\Components\Select::make('type')
                        ->label(__('Type'))
                        ->options(['proforma'=>__('Proforma'), 'order'=>__('Order')])
                        ->default('proforma')
                        ->required()
                        ->reactive()
                        ->disabled(fn (Forms\Get $get, $record) => $record && $record->type === 'order')
                        ->columnSpan(['sm' => 2, 'md' => 1, 'lg' => 1]),

                    /* ------------------ FIXED CUSTOMER SEARCH ------------------ */
                    Forms\Components\Select::make('customer_id')
                        ->label(__('Customer'))
                        ->searchable()
                        ->preload(false)
                        ->getSearchResultsUsing(function (string $search) {
                            $user = auth()->user();
                            $isSeller = $user?->hasRole('seller');

                            $q = Customer::query();

                            if ($isSeller) {
                                $q->where(function ($w) use ($user) {
                                    $w->where('seller_id', $user->id)
                                      ->orWhereNull('seller_id');
                                });
                            }

                            $search = trim($search);
                            if ($search !== '') {
                                $s = mb_strtolower($search);
                                $q->where(function ($qq) use ($s) {
                                    $qq->whereRaw('LOWER(name)  LIKE ?', ["%{$s}%"])
                                       ->orWhereRaw('LOWER(code)  LIKE ?', ["%{$s}%"])
                                       ->orWhereRaw('LOWER(email) LIKE ?', ["%{$s}%"])
                                       ->orWhereRaw('LOWER(phone) LIKE ?', ["%{$s}%"]);
                                });
                            }

                            return $q->orderBy('name')
                                ->limit(50)
                                ->pluck('name', 'id')
                                ->toArray();
                        })
                        ->getOptionLabelUsing(function ($value) {
                            if (!$value) return null;
                            $user = auth()->user();
                            $isSeller = $user?->hasRole('seller');

                            $q = Customer::query()->whereKey($value);
                            if ($isSeller) {
                                $q->where(function ($w) use ($user) {
                                    $w->where('seller_id', $user->id)
                                      ->orWhereNull('seller_id');
                                });
                            }
                            return $q->value('name');
                        })
                        ->required(fn (Forms\Get $get) => $get('type') === 'order')
                        ->rule(function (Forms\Get $get) {
                            if ($get('type') !== 'order') return ['nullable'];

                            $user = auth()->user();
                            $isSeller = $user?->hasRole('seller');

                            if ($isSeller) {
                                return [
                                    'required',
                                    Rule::exists('customers', 'id')->where(
                                        fn ($q) => $q->where('seller_id', $user->id)->orWhereNull('seller_id')
                                    ),
                                ];
                            }

                            return ['required', Rule::exists('customers', 'id')];
                        })
                        ->createOptionForm([
                            Forms\Components\TextInput::make('name')->label(__('Name'))->required(),
                            Forms\Components\TextInput::make('email')->label(__('Email'))->email()->nullable(),
                            Forms\Components\TextInput::make('phone')->label(__('Phone'))->nullable(),
                            Forms\Components\Textarea::make('address')->label(__('Address'))->rows(3)->nullable(),
                        ])
                        ->createOptionUsing(function (array $data) {
                            $user = auth()->user();
                            if ($user?->hasRole('seller')) {
                                $data['seller_id'] = $user->id;
                            }
                            return \App\Models\Customer::create($data)->id;
                        })
                        ->columnSpan(['sm' => 2, 'md' => 2, 'lg' => 1]),
                    /* ---------------- END FIXED CUSTOMER SEARCH ---------------- */

                    Forms\Components\Select::make('payment_status')
                        ->label(__('Payment status'))
                        ->options([
                            'unpaid'         => __('Unpaid'),
                            'partially_paid' => __('Partially paid'),
                            'paid'           => __('Paid'),
                            'debt'           => __('Debt'),
                        ])
                        ->default('unpaid')
                        ->visible(fn (Forms\Get $get) => $get('type') === 'order')
                        ->dehydrated(fn (Forms\Get $get) => $get('type') === 'order')
                        ->required(fn (Forms\Get $get) => $get('type') === 'order')
                        ->reactive()
                        ->columnSpan(['sm' => 2, 'md' => 2, 'lg' => 1]),

                    Forms\Components\TextInput::make('paid_amount')
                        ->label(__('Paid amount (USD)'))
                        ->numeric()->step('0.01')->rule('decimal:0,2')->minValue(0)
                        ->visible(fn (Forms\Get $get) =>
                            $get('type') === 'order' && $get('payment_status') === 'partially_paid'
                        )
                        ->required(fn (Forms\Get $get) =>
                            $get('type') === 'order' && $get('payment_status') === 'partially_paid'
                        )
                        ->live(onBlur: true)
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($r2) {
                            $set('paid_amount', $r2($state));
                        })
                        ->rule(fn (Forms\Get $get) => function (string $attribute, $value, \Closure $fail) use ($get, $r2) {
                            if ($get('payment_status') !== 'partially_paid') return;
                            $items = (array) ($get('items') ?? []);
                            $subtotal = $r2(collect($items)->sum(fn ($row) => (float)($row['qty'] ?? 0) * (float)($row['unit_price'] ?? 0)));
                            $discount = $r2((float) ($get('discount') ?? 0));
                            $shipping = $r2((float) ($get('shipping') ?? 0));
                            $extra    = $r2((float) ($get('extra_fees') ?? 0));
                            $total = $r2(max(0.0, $subtotal - $discount + $shipping + $extra));
                            if ((float)$value > $total + 0.00001) {
                                $fail(__('The paid amount must be less than or equal to the order total (:total USD).', [
                                    'total' => number_format($total, 2),
                                ]));
                            }
                        })
                        ->dehydrated(fn (Forms\Get $get) => $get('payment_status') === 'partially_paid')
                        ->columnSpan(['sm' => 2, 'md' => 2, 'lg' => 1]),

                    Forms\Components\Select::make('status')
                        ->label(__('Order status'))
                        ->options([
                            'on_hold'    => __('On hold'),
                            'draft'      => __('Draft'),
                            'pending'    => __('Pending'),
                            'processing' => __('Processing'),
                            'completed'  => __('Completed'),
                            'cancelled'  => __('Cancelled'),
                            'refunded'   => __('Refunded'),
                            'failed'     => __('Failed'),
                        ])
                        ->default('pending')
                        ->visible(fn (Forms\Get $get) => $get('type') === 'order')
                        ->dehydrated(fn (Forms\Get $get) => $get('type') === 'order')
                        ->columnSpan(['sm' => 2, 'md' => 2, 'lg' => 1]),

                    Forms\Components\TextInput::make('margin_percent')
                        ->label(__('Margin % over cost (optional)'))
                        ->numeric()->minValue(0)->step('0.01')->rule('decimal:0,2')
                        ->live(onBlur: true)
                        ->visible(fn () => (auth()->user()?->hasRole('admin') ?? false) || (auth()->user()?->can_see_cost ?? false))
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($canSellBelowCost, $canSeeCost, $r2, $r4, $extraFromPercent) {
                            $items = (array) ($get('items') ?? []);
                            if (empty($items)) return;

                            $rate = (float) ($get('exchange_rate') ?? 1);
                            if ($rate <= 0) $rate = 1;

                            $margin = $r2($state ?? 0);

                            foreach (array_keys($items) as $i) {
                                $pid = (int) ($get("items.$i.product_id") ?? 0);
                                if (!$pid) continue;

                                $product = \App\Models\Product::query()->select('id','price','sale_price','cost_price')->find($pid);
                                $cost    = (float) ($get("items.$i.cost_price") ?? ($product?->cost_price ?? 0));
                                $qty     = (float) ($get("items.$i.qty") ?? 1);

                                $usd = (float) ($product?->sale_price ?? $product?->price ?? 0);
                                if ($margin > 0 && $cost > 0) $usd = $r2($cost * (1 + $margin / 100)); else $usd = $r2($usd);

                                if (!$canSellBelowCost && $cost > 0 && $usd < $cost) {
                                    $usd = $r2($cost);
                                    $msg = $canSeeCost
                                        ? __('Unit price raised to cost (:cost USD).', ['cost' => number_format($cost, 2)])
                                        : __('Unit price raised to the minimum allowed.');
                                    Notification::make()->title($msg)->warning()->send();
                                }

                                $set("items.$i.unit_price", $r4($usd));
                                $set("items.$i.unit_price_local", $r2($usd * $rate));
                                $set("items.$i.line_total", $r4($qty * $usd));
                                $set("items.$i.line_total_local", $r2($qty * $usd * $rate));
                                $set("items.$i.__currency_mirror", microtime(true));
                            }

                            $itemsNow    = (array) ($get('items') ?? []);
                            $subtotalUsd = $r2(collect($itemsNow)->sum(fn ($r) => (float)($r['qty'] ?? 0) * (float)($r['unit_price'] ?? 0)));
                            $discountUsd = $r2((float) ($get('discount') ?? 0));
                            $shippingUsd = $r2((float) ($get('shipping') ?? 0));
                            $extraUsd    = $extraFromPercent($get);
                            $set('extra_fees', $extraUsd);
                            $totalUsd    = $r2(max(0, $subtotalUsd - $discountUsd + $shippingUsd + $extraUsd));
                            $set('subtotal', $subtotalUsd);
                            $set('total', $totalUsd);
                        })
                        ->columnSpan(['sm' => 2, 'md' => 2, 'lg' => 1]),
                ])
                ->columns([
                    'default' => 1,
                    'sm' => 2,
                    'md' => 3,
                    'lg' => 5,
                ]),

            Forms\Components\Section::make(__('Items'))
                ->schema([
                    Forms\Components\Hidden::make('__items_version')
                        ->default(0)
                        ->dehydrated(false),

                    Forms\Components\Repeater::make('items')
                        ->label(__('Items'))
                        ->addActionLabel(__('Add item'))
                        ->relationship()
                        ->live(debounce: 150)
                        ->reorderable()
                        ->orderColumn('sort')
                        ->afterStateHydrated(function (?array $state, Forms\Get $get, Forms\Set $set) use ($normalizeItems) {
                            $items = (array)($state ?? $get('items') ?? []);
                            $items = $normalizeItems($items);
                            $set('items', $items);
                            $set('../../__items_version', ((int)$get('../../__items_version')) + 1);
                        })
                        ->afterStateUpdated(function (?array $state, Forms\Set $set, Forms\Get $get) use ($compute, $setNestedTotals, $extraFromPercent, $normalizeItems, $r2) {
                            $items = (array)($state ?? $get('items') ?? []);
                            $items = $normalizeItems($items);
                            $set('items', $items);

                            $rate     = (float) ($get('../../exchange_rate') ?? 1);
                            if ($rate <= 0) $rate = 1;

                            $extraUsd = $extraFromPercent($get);
                            $set('../../extra_fees', $extraUsd);

                            $t = $compute(
                                (array) ($get('../../items') ?? $items),
                                (float) ($get('../../discount') ?? 0),
                                (float) ($get('../../shipping') ?? 0),
                                (float) $extraUsd,
                                $rate
                            );
                            $setNestedTotals($set, $t);
                            $set('../../__items_version', ((int)$get('../../__items_version')) + 1);
                        })
                        ->columns([
                            'default' => 1,
                            'sm' => 6,
                            'md' => 8,
                            'lg' => 12,
                        ])
                        ->schema([
                            Forms\Components\TextInput::make('__index')
                                ->label('#')
                                ->disabled()
                                ->dehydrated(false)
                                ->extraAttributes(['class' => 'text-center font-semibold w-10'])
                                ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 1, 'lg' => 1]),

                            Forms\Components\Placeholder::make('product_image')
                                ->label('')
                                ->content(function (Forms\Get $get) use ($fallbackImg, $locale, $titleFor, $getProduct, $imageUrlFor) {
                                    $productId = (int) $get('product_id');
                                    $url = $fallbackImg; $alt = __('Product');
                                    if ($productId) {
                                        $product = $getProduct($productId);
                                        if ($product) {
                                            $title = trim($titleFor($product, $locale));
                                            $alt   = $title !== '' ? $title : ($product->sku ?? __('Product'));
                                            $img   = $imageUrlFor($product);
                                            if ($img) $url = $img;
                                        }
                                    }
                                    return new HtmlString(
                                        '<img src="'.e($url).'" alt="'.e($alt).'" class="w-12 h-12 sm:w-14 sm:h-14 md:w-16 md:h-16 object-contain rounded-md border border-gray-200" />'
                                    );
                                })
                                ->disableLabel()
                                ->extraAttributes(['class' => 'pt-4 sm:pt-5'])
                                ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 1, 'lg' => 1]),

                            Forms\Components\Hidden::make('__customer_mirror')->dehydrated(false)->reactive(),
                            Forms\Components\Hidden::make('__branch_mirror')->dehydrated(false)->reactive(),
                            Forms\Components\Hidden::make('__currency_mirror')->dehydrated(false)->reactive(),
                            Forms\Components\Hidden::make('stock_in_branch')->dehydrated(false),

                            Forms\Components\Hidden::make('sort')->dehydrated(true),

                            Forms\Components\Hidden::make('cost_price')
                                ->dehydrated(false)
                                ->reactive()
                                ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set) use ($r2, $getProduct) {
                                    if ($state === null && $pid = (int) $get('product_id')) {
                                        $set('cost_price', $r2($getProduct($pid)?->cost_price ?? 0));
                                    }
                                }),

                            Forms\Components\Hidden::make('unit_price')->dehydrated(true),
                            Forms\Components\Hidden::make('line_total')->dehydrated(false),

                            Forms\Components\Select::make('product_id')
                                ->label(__('Product'))
                                ->searchable()
                                ->preload(false)
                                ->getSearchResultsUsing(function (string $search, Forms\Get $get) use ($titleFor, $locale) {
                                    $chosenBranch = $get('../../branch_id');
                                    $userBranch   = auth()->user()?->branch_id;
                                    $branchId     = $chosenBranch ?: $userBranch;

                                    $s = mb_strtolower(trim($search));
                                    $q = Product::query()->select('id','sku','title');

                                    if ($branchId && method_exists($q->getModel(), 'scopeForBranch')) {
                                        $q->forBranch($branchId);
                                    }

                                    if ($s !== '') {
                                        $q->where(function ($qq) use ($s, $locale) {
                                            $qq->whereRaw('LOWER(sku) LIKE ?', ["%{$s}%"])
                                               ->orWhereRaw('LOWER(title) LIKE ?', ["%{$s}%"])
                                               ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(title, '$.\"$locale\"'))) LIKE ?", ["%{$s}%"])
                                               ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(title, '$.\"en\"'))) LIKE ?", ["%{$s}%"]);
                                        });
                                    }

                                    $max = 70;

                                    return $q->orderBy('sku')
                                        ->limit(50)
                                        ->get()
                                        ->mapWithKeys(function (\App\Models\Product $p) use ($titleFor, $locale, $max) {
                                            $title = trim($titleFor($p, $locale));
                                            if ($title === '') {
                                                $label = mb_strlen($p->sku ?? '') <= $max ? ($p->sku ?? '') : (mb_substr($p->sku ?? '', 0, $max - 3) . '...');
                                            } else {
                                                $label = mb_strlen($title) <= $max ? $title : (mb_substr($title, 0, $max - 3) . '...');
                                            }
                                            return [$p->id => $label];
                                        })
                                        ->toArray();
                                })

                                ->getOptionLabelUsing(function ($value) use ($titleFor, $locale) {
                                    if (!$value) return null;

                                    $p = Product::query()->select('id','sku','title')->find((int)$value);
                                    if (!$p) return null;

                                    $max   = 70;
                                    $title = trim($titleFor($p, $locale));

                                    if ($title === '') {
                                        $sku = trim((string)($p->sku ?? ''));
                                        return mb_strlen($sku) <= $max ? $sku : (mb_substr($sku, 0, $max - 3) . '...');
                                    }

                                    return mb_strlen($title) <= $max ? $title : (mb_substr($title, 0, $max - 3) . '...');
                                })

                                ->required()
                                ->reactive()
                                ->columnSpan(['default' => 1, 'sm' => 2, 'md' => 3, 'lg' => 3])
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($compute, $setNestedTotals, $canSellBelowCost, $canSeeCost, $r2, $r4, $getProduct, $getProductStockForBranch, $extraFromPercent) {
                                    if ($state) {
                                        $items = collect($get('../../items') ?? []);
                                        $dups  = $items->pluck('product_id')->filter()->countBy();
                                        if ((int)($dups[$state] ?? 0) > 1) {
                                            $set('product_id', null);
                                            $set('unit_price', null);
                                            $set('unit_price_local', null);
                                            $set('line_total', null);
                                            $set('line_total_local', null);

                                            Notification::make()
                                                ->title(__('Product already added to this order.'))
                                                ->danger()
                                                ->send();
                                            return;
                                        }
                                    }

                                    $p   = $state ? $getProduct((int)$state) : null;
                                    $qty = (float) ($get('qty') ?? 1);

                                    $cost  = (float) ($p?->cost_price ?? 0);
                                    $price = (float)($p?->sale_price ?? $p?->price ?? 0);

                                    $chosenBranch = (int) ($get('../../branch_id') ?: (auth()->user()?->branch_id ?? 0));
                                    $stock = null;
                                    if ($state && $chosenBranch) $stock = $getProductStockForBranch((int)$state, $chosenBranch);
                                    $set('stock_in_branch', $stock);
                                    $set('cost_price', $r2($cost));

                                    $margin = (float) ($get('../../margin_percent') ?? 0);
                                    if ($margin > 0 && $cost > 0) $price = $r2($cost * (1 + $margin / 100)); else $price = $r2($price);

                                    if (!$canSellBelowCost && $cost > 0 && $price < $cost) {
                                        $price = $r2($cost);
                                        $msg = $canSeeCost
                                            ? __('Unit price raised to cost (:cost USD).', ['cost' => number_format($cost, 2)])
                                            : __('Unit price raised to the minimum allowed.');
                                        Notification::make()->title($msg)->warning()->send();
                                    }

                                    $rate = (float) ($get('../../exchange_rate') ?? 1);
                                    if ($rate <= 0) $rate = 1;

                                    $set('unit_price', $r4($price));
                                    $set('unit_price_local', $r2($price * $rate));
                                    $set('line_total', $r4($qty * $price));
                                    $set('line_total_local', $r2($qty * $price * $rate));
                                    $set('__currency_mirror', microtime(true));

                                    if ($stock === 0) {
                                        Notification::make()
                                            ->title(__('Selected SKU has 0 stock in this branch'))
                                            ->body(__('You can still add it to the order.'))
                                            ->warning()
                                            ->persistent()
                                            ->send();
                                    }

                                    $extraUsd = $extraFromPercent($get);
                                    $set('../../extra_fees', $extraUsd);
                                    $t = $compute(
                                        (array) ($get('../../items') ?? []),
                                        (float) ($get('../../discount') ?? 0),
                                        (float) ($get('../../shipping') ?? 0),
                                        (float) $extraUsd,
                                        $rate
                                    );
                                    $setNestedTotals($set, $t);
                                })
                                ->helperText(function (Forms\Get $get) use ($canSeeCost, $r2, $getProduct) {
                                    $pid  = (int) ($get('product_id') ?? 0);
                                    $rate = (float) ($get('../../exchange_rate') ?? 1);
                                    if ($rate <= 0) $rate = 1;

                                    $pieces = [];

                                    if ($pid) {
                                        $prod = $getProduct($pid);
                                        $sku  = trim((string)($prod->sku ?? ''));
                                        if ($sku !== '') {
                                            $pieces[] = '<span style="color:#f97316;font-weight:600;">SKU: ' . e($sku) . '</span>';
                                        }

                                        if ($canSeeCost) {
                                            $costUsd = (float) ($prod->cost_price ?? 0);
                                            if ($costUsd > 0) {
                                                $pieces[] = e(__('Cost: :usd USD (:fx :cur)', [
                                                    'usd' => number_format($r2($costUsd), 2),
                                                    'fx'  => number_format($r2($costUsd * $rate), 2),
                                                    'cur' => $get('../../currency') ?: 'USD',
                                                ]));
                                            }
                                        }
                                    }

                                    if (empty($pieces)) {
                                        return null;
                                    }

                                    return new \Illuminate\Support\HtmlString(
                                        '<div>' . implode(' — ', $pieces) . '</div>'
                                    );
                                }),

                            Forms\Components\TextInput::make('qty')
                                ->label(__('Qty'))
                                ->numeric()->minValue(1)->default(1)
                                ->inputMode('decimal')->step('1')
                                ->live(onBlur: true)
                                ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 2, 'lg' => 2])
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($compute, $setNestedTotals, $r2, $r4, $extraFromPercent) {
                                    $qty  = (float) $state;
                                    $usd  = (float) ($get('unit_price') ?? 0);
                                    $rate = (float) ($get('../../exchange_rate') ?? 1);
                                    if ($rate <= 0) $rate = 1;

                                    $set('line_total', $r4($qty * $usd));
                                    $set('line_total_local', $r2($qty * $usd * $rate));

                                    $extraUsd = $extraFromPercent($get);
                                    $set('../../extra_fees', $extraUsd);
                                    $t = $compute(
                                        (array) ($get('../../items') ?? []),
                                        (float) ($get('../../discount') ?? 0),
                                        (float) ($get('../../shipping') ?? 0),
                                        (float) $extraUsd,
                                        $rate
                                    );
                                    $setNestedTotals($set, $t);
                                }),

                            // ***** KEY CHANGE: precise one-way local -> USD conversion with BCMath *****
                            Forms\Components\TextInput::make('unit_price_local')
                                ->label(fn (Forms\Get $get) => __('Unit price (:cur)', ['cur' => $get('../../currency') ?: 'USD']))
                                ->suffix(fn (Forms\Get $get) => $get('../../currency') ?: 'USD')
                                ->numeric()->step('0.01')->inputMode('decimal')
                                ->rule('decimal:0,2')
                                ->live(onBlur: true)
                                ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set) use ($m2, $mul2) {
                                    $rate = (string)($get('../../exchange_rate') ?? '1');
                                    if (((float)$rate) <= 0) $rate = '1';
                                    $usd  = (string)($get('unit_price') ?? '0');
                                    $qty  = (string)($get('qty') ?? '1');
                                    $set('unit_price_local', $m2($mul2($usd, $rate)));
                                    $set('line_total_local', $m2($mul2((string)($get('line_total') ?? $mul2($usd,$qty)), $rate)));
                                })
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($compute, $setNestedTotals, $canSellBelowCost, $canSeeCost, $m2, $m4, $mul2, $mul4, $div4, $extraFromPercent) {
                                    // authoritative local from user
                                    $qty   = (int) max(1, (float) ($get('qty') ?? 1));
                                    $rateS = (string) ($get('../../exchange_rate') ?? '1');
                                    if (((float)$rateS) <= 0) $rateS = '1';

                                    $localUnit = $m2($state);
                                    $lineLocal = $m2($mul2($localUnit, (string)$qty));

                                    // Convert to USD (4 d.p.), NO back conversion here
                                    $lineUsd = $m4($div4($lineLocal, $rateS));
                                    $unitUsd = $m4($div4($lineUsd, (string)$qty));

                                    // cost guard (compare in USD)
                                    $costUsd = $m4((float) ($get('cost_price') ?? 0));
                                    if (!$canSellBelowCost && bccomp($costUsd, '0', 4) > 0 && bccomp($unitUsd, $costUsd, 4) < 0) {
                                        $unitUsd  = $costUsd;
                                        $lineUsd  = $m4($mul4($unitUsd, (string)$qty));
                                        $lineLocal= $m2($mul2($lineUsd, $rateS));
                                        $localUnit= $m2($div4($lineLocal, (string)$qty));
                                        Notification::make()
                                            ->title($canSeeCost
                                                ? __('Unit price raised to cost (:cost USD).', ['cost' => number_format((float)$costUsd, 2)])
                                                : __('Unit price raised to the minimum allowed.')
                                            )->warning()->send();
                                    }

                                    // Persist authoritative + derived values
                                    $set('unit_price_local', $localUnit);
                                    $set('line_total_local',  $lineLocal);
                                    $set('unit_price',        $unitUsd);
                                    $set('line_total',        $lineUsd);

                                    // recompute totals in USD then FX
                                    $rate = (float) $rateS;
                                    $extraUsd = $extraFromPercent($get);
                                    $set('../../extra_fees', $extraUsd);

                                    $t = $compute(
                                        (array) ($get('../../items') ?? []),
                                        (float) ($get('../../discount') ?? 0),
                                        (float) ($get('../../shipping') ?? 0),
                                        (float) $extraUsd,
                                        $rate
                                    );
                                    $setNestedTotals($set, $t);
                                })
                                ->helperText(function (Forms\Get $get) use ($canSeeCost, $r2) {
                                    if (!$canSeeCost) return null;
                                    $cost   = (float) ($get('cost_price') ?? 0);
                                    $margin = (float) ($get('../../margin_percent') ?? 0);
                                    $rate   = (float) ($get('../../exchange_rate') ?? 1);
                                    if ($cost <= 0 || $margin <= 0) return null;
                                    $local = $r2($cost * (1 + $margin/100) * max(1,$rate));
                                    return __('Cost + margin ≈ :calc', ['calc' => number_format($local, 2)]);
                                })
                                ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 2, 'lg' => 2]),

                            Forms\Components\TextInput::make('line_total_local')
                                ->label(fn (Forms\Get $get) => __('Total (:cur)', ['cur' => $get('../../currency') ?: 'USD']))
                                ->suffix(fn (Forms\Get $get) => $get('../../currency') ?: 'USD')
                                ->disabled()
                                ->numeric()
                                ->extraAttributes(['class' => 'font-semibold'])
                                ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 2, 'lg' => 2]),

                            Forms\Components\Placeholder::make('stock_indicator')
                                ->label('')
                                ->reactive()
                                ->content(function (Forms\Get $get) use ($getProductStockForBranch) {
                                    $pid      = (int) ($get('product_id') ?? 0);
                                    $branchId = (int) ($get('../../branch_id') ?: (auth()->user()?->branch_id ?? 0));

                                    if (!$pid || !$branchId) {
                                        return new HtmlString('<div style="color:#6b7280;margin-top:4px;">Stock: n/a</div>');
                                    }

                                    $stock = $getProductStockForBranch($pid, $branchId);
                                    $s     = (int) ($stock ?? 0);

                                    $locale = app()->getLocale();
                                    $label  = $locale === 'ar' ? 'المخزون' : 'Stock';
                                    $notAvailable = $locale === 'ar' ? 'غير متوفر' : 'n/a';

                                    $color = $s === 0 ? '#dc2626' : ($s > 0 ? '#16a34a' : '#6b7280');

                                    $text = $stock === null ? "{$label}: {$notAvailable}" : "{$label}: {$s}";

                                    return new HtmlString(
                                        '<div style="margin-top:4px;font-weight:600;color:' . $color . ';">' . e($text) . '</div>'
                                    );
                                })
                                ->columnSpan(['default' => 1, 'sm' => 6, 'md' => 8, 'lg' => 12]),

                            Forms\Components\Placeholder::make('prev_sales')
                                ->label(__('Previous sales to this customer'))
                                ->reactive()
                                ->content(function (Forms\Get $get) use ($r2) {
                                    $productId  = (int) $get('product_id');
                                    $customerId = (int) ($get('../../customer_id') ?? 0);
                                    $get('__customer_mirror');

                                    if (!$productId || !$customerId) {
                                        return new HtmlString('<div class="text-gray-500">' . e(__('No previous sales')) . '</div>');
                                    }

                                    $user = auth()->user();
                                    $q = OrderItem::query()
                                        ->select('id','order_id','unit_price','created_at')
                                        ->with([
                                            'order:id,currency,exchange_rate,customer_id,seller_id,created_at',
                                            'order.customer:id,name',
                                            'order.seller:id,name',
                                        ])
                                        ->where('product_id', $productId)
                                        ->whereHas('order', fn ($o) => $o->where('customer_id', $customerId))
                                        ->orderByDesc('created_at');

                                    if (!$user->hasRole('admin')) {
                                        $q->whereHas('order', fn ($o) => $o->where('seller_id', $user->id));
                                    }

                                    $rows = $q->limit(10)->get();
                                    if ($rows->isEmpty()) {
                                        return new HtmlString('<div class="text-gray-500">' . e(__('No previous sales')) . '</div>');
                                    }

                                    $itemsHtml = [];
                                    foreach ($rows as $row) {
                                        $order = $row->order;
                                        if (!$order) continue;

                                        $unitUsd   = (float) ($row->unit_price ?? 0);
                                        $rate      = (float) ($order->exchange_rate ?? 1);
                                        $converted = number_format($r2($unitUsd * $rate), 2);
                                        $cur       = $order->currency ?: 'USD';
                                        $date      = optional($order->created_at)->format('Y-m-d');

                                        $itemsHtml[] = "<li class='text-red-600'>{$converted} {$cur} <span class='opacity-70'>($date)</span></li>";
                                    }

                                    return new HtmlString(
                                        '<details class="mt-1" open>
                                           <summary class="cursor-pointer select-none">' . e(__('Previous sales')) . ' (' . count($itemsHtml) . ')</summary>
                                           <div class="max-h-48 overflow-auto mt-1.5">
                                             <ul class="m-0 pl-4 list-disc">' . implode('', $itemsHtml) . '</ul>
                                           </div>
                                         </details>'
                                    );
                                })
                                ->columnSpanFull(),
                        ]),
                ]),

            Forms\Components\Section::make(__('Totals & Currency'))
                ->schema([
                    Forms\Components\Hidden::make('subtotal')->default(0),
                    Forms\Components\Hidden::make('discount')->default(0),
                    Forms\Components\Hidden::make('shipping')->default(0),
                    Forms\Components\Hidden::make('extra_fees')->default(0),
                    Forms\Components\Hidden::make('total')->default(0),

                    Forms\Components\TextInput::make('discount_local')
                        ->label(fn (Forms\Get $get) => __('Discount'))
                        ->suffix(fn (Forms\Get $get) => $get('currency') ?: 'USD')
                        ->numeric()->default(0)->step('0.01')->rule('decimal:0,2')
                        ->live(onBlur: true)
                        ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set) use ($m2, $mul2) {
                            $rate = (string)($get('exchange_rate') ?? '1');
                            if (((float)$rate) <= 0) $rate = '1';
                            $set('discount_local', $m2($mul2(($get('discount') ?? 0), $rate)));
                        })
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($r2, $m2, $div4, $extraFromPercent) {
                            $rate = (string)($get('exchange_rate') ?? '1');
                            if (((float)$rate) <= 0) $rate = '1';
                            // store USD side from local change
                            $set('discount', (float) $div4($state, $rate));

                            $items = (array) ($get('items') ?? []);
                            $subtotalUsd = $r2(collect($items)->sum(fn ($r) => (float)($r['qty'] ?? 0) * (float)($r['unit_price'] ?? 0)));
                            $shippingUsd = $r2((float) ($get('shipping') ?? 0));
                            $extraUsd    = $extraFromPercent($get);
                            $set('extra_fees', $extraUsd);
                            $totalUsd    = $r2(max(0, $subtotalUsd - (float)$get('discount') + $shippingUsd + $extraUsd));
                            $set('subtotal', $subtotalUsd);
                            $set('total', $totalUsd);
                        })
                        ->columnSpan(['default' => 1, 'sm' => 2, 'md' => 3, 'lg' => 5]),

                    Forms\Components\TextInput::make('shipping_local')
                        ->label(fn (Forms\Get $get) => __('Shipping'))
                        ->suffix(fn (Forms\Get $get) => $get('currency') ?: 'USD')
                        ->numeric()->default(0)->step('0.01')->rule('decimal:0,2')
                        ->live(onBlur: true)
                        ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set) use ($m2, $mul2) {
                            $rate = (string)($get('exchange_rate') ?? '1');
                            if (((float)$rate) <= 0) $rate = '1';
                            $set('shipping_local', $m2($mul2(($get('shipping') ?? 0), $rate)));
                        })
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($r2, $div4, $extraFromPercent) {
                            $rate = (string)($get('exchange_rate') ?? '1');
                            if (((float)$rate) <= 0) $rate = '1';
                            // store USD side from local change
                            $set('shipping', (float) $div4($state, $rate));

                            $items = (array) ($get('items') ?? []);
                            $subtotalUsd = $r2(collect($items)->sum(fn ($r) => (float)($r['qty'] ?? 0) * (float)($r['unit_price'] ?? 0)));
                            $discountUsd = $r2((float) ($get('discount') ?? 0));
                            $extraUsd    = $extraFromPercent($get);
                            $set('extra_fees', $extraUsd);
                            $totalUsd    = $r2(max(0, $subtotalUsd - $discountUsd + (float)$get('shipping') + $extraUsd));
                            $set('subtotal', $subtotalUsd);
                            $set('total', $totalUsd);
                        })
                        ->columnSpan(['default' => 1, 'sm' => 2, 'md' => 3, 'lg' => 5]),

                    Forms\Components\TextInput::make('extra_fees_percent')
                        ->label(__('Extra fees %'))
                        ->suffix('%')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->step('0.01')
                        ->rule('decimal:0,2')
                        ->dehydrated(false)
                        ->live(onBlur: true)
                        ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set, $record) use ($r2) {
                            if ($state !== null && $state !== '') return;

                            $extraUsd = (float) ($get('extra_fees') ?? ($record->extra_fees ?? 0));

                            $items = (array) ($get('items') ?? []);
                            $subtotalUsd = $r2(collect($items)->sum(fn ($r) => (float)($r['qty'] ?? 0) * (float)($r['unit_price'] ?? 0)));
                            if ($subtotalUsd <= 0 && $record?->relationLoaded('items')) {
                                $subtotalUsd = $r2((float) $record->items->sum(fn ($i) => (float)$i->qty * (float)$i->unit_price));
                            }

                            $discountUsd = $r2((float) ($get('discount') ?? ($record->discount ?? 0)));
                            $baseUsd     = max(0.0, $subtotalUsd - $discountUsd);

                            if ($baseUsd > 0 && $extraUsd > 0) {
                                $pct = $r2(($extraUsd / $baseUsd) * 100.0);
                                $set('extra_fees_percent', $pct);
                            }
                        })
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($r2, $extraFromPercent) {
                            $extraUsd = $extraFromPercent($get);
                            $set('extra_fees', $extraUsd);

                            $items       = (array) ($get('items') ?? []);
                            $subtotalUsd = $r2(collect($items)->sum(fn ($r) => (float)($r['qty'] ?? 0) * (float)($r['unit_price'] ?? 0)));
                            $discountUsd = $r2((float) ($get('discount') ?? 0));
                            $shippingUsd = $r2((float) ($get('shipping') ?? 0));
                            $totalUsd    = $r2(max(0, $subtotalUsd - $discountUsd + $shippingUsd + $extraUsd));
                            $set('subtotal', $subtotalUsd);
                            $set('total', $totalUsd);
                        })
                        ->helperText(__('Applies to (Subtotal − Discount).'))
                        ->columnSpan(['default' => 1, 'sm' => 2, 'md' => 3, 'lg' => 5]),

                    Forms\Components\Placeholder::make('exchange_rate_info')
                        ->label(__('Exchange rate'))
                        ->content(fn (Forms\Get $get) =>
                            '1 ' . __('USD') . ' = ' . number_format((float)($get('exchange_rate') ?? 1), 6) . ' ' . ($get('currency') ?: 'USD')
                        )
                        ->columnSpanFull(),

                    Forms\Components\Placeholder::make('subtotal_display')
                        ->label(fn (Forms\Get $get) => __('Subtotal (:cur)', ['cur' => $get('currency') ?: 'USD']))
                        ->content(function (Forms\Get $get) use ($r2) {
                            $rate  = (float)($get('exchange_rate') ?? 1);
                            $items = (array) ($get('items') ?? []);
                            if (empty($items) && $id = $get('id')) {
                                $order = Order::query()->select('id')->with(['items:id,order_id,qty,unit_price'])->find($id);
                                $items = $order
                                    ? $order->items->map(fn($i) => ['qty' => (float)$i->qty, 'unit_price' => (float)$i->unit_price])->toArray()
                                    : [];
                            }
                            $subtotalUsd = $r2(collect($items)->sum(fn ($r) => (float)($r['qty'] ?? 0) * (float)($r['unit_price'] ?? 0)));
                            $fx = $r2($subtotalUsd * $rate);
                            return new HtmlString('<span class="text-red-600 font-semibold">' . number_format($fx, 2) . '</span>');
                        })
                        ->columnSpan(['default' => 1, 'sm' => 2, 'md' => 3, 'lg' => 6]),

                    Forms\Components\Placeholder::make('total_display')
                        ->label(fn (Forms\Get $get) => __('Total (:cur)', ['cur' => $get('currency') ?: 'USD']))
                        ->content(function (Forms\Get $get) use ($r2) {
                            $rate  = (float) ($get('exchange_rate') ?? 1);
                            $items = (array) ($get('items') ?? []);
                            if (empty($items) && $id = $get('id')) {
                                $order = Order::query()->select('id','discount','shipping','extra_fees','exchange_rate','currency')->with(['items:id,order_id,qty,unit_price'])->find($id);
                                $items = $order
                                    ? $order->items->map(fn($i) => ['qty' => (float)$i->qty, 'unit_price' => (float)$i->unit_price])->toArray()
                                    : [];
                            }
                            $subtotalUsd = $r2(collect($items)->sum(fn ($r) => (float)($r['qty'] ?? 0) * (float)($r['unit_price'] ?? 0)));
                            $discountUsd = $r2((float) ($get('discount') ?? 0));
                            $shippingUsd = $r2((float) ($get('shipping') ?? 0));
                            $extraUsd    = $r2((float) ($get('extra_fees') ?? 0));
                            $totalUsd    = $r2(max(0, $subtotalUsd - $discountUsd + $shippingUsd + $extraUsd));
                            $fx          = $r2($totalUsd * $rate);

                            return new HtmlString('<span class="text-red-600 font-semibold">' . number_format($fx, 2) . '</span>');
                        })
                        ->columnSpan(['default' => 1, 'sm' => 2, 'md' => 3, 'lg' => 6]),
                ])
                ->columns([
                    'default' => 1,
                    'sm' => 2,
                    'md' => 6,
                    'lg' => 12,
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('code')->label(__('Order #'))->wrap()->sortable()->searchable()->copyable(),
                Tables\Columns\TextColumn::make('branch.code')->label(__('Branch'))->badge()->wrap()->sortable(),
                Tables\Columns\TextColumn::make('customer.name')->label(__('Customer'))->wrap()->searchable(),
                Tables\Columns\TextColumn::make('seller.name')
                    ->label(__('Seller'))
                    ->state(fn (Order $r) => $r->seller?->name ?? __('Admin'))
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('Type'))->badge()->wrap()
                    ->color(fn ($state) => $state === 'order' ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('payment_status')
                    ->label(__('Payment'))->badge()
                    ->color(fn ($state) => match ($state) {
                        'paid' => 'success',
                        'unpaid' => 'danger',
                        'partially_paid' => 'warning',
                        'debt' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('shipping_fx')
                    ->label(__('Shipping'))
                    ->state(fn (Order $r) => number_format(((float)$r->shipping * (float)$r->exchange_rate), 2))
                    ->suffix(fn (Order $r) => ' ' . ($r->currency ?? 'USD'))
                    ->wrap(),

                Tables\Columns\TextColumn::make('extra_fees_fx')
                    ->label(__('Extra fees'))
                    ->state(fn (Order $r) => number_format(((float)$r->extra_fees * (float)$r->exchange_rate), 2))
                    ->suffix(fn (Order $r) => ' ' . ($r->currency ?? 'USD'))
                    ->wrap(),

                Tables\Columns\TextColumn::make('total_fx')
                    ->label(__('Total (Currency)'))
                    ->state(function (Order $r) {
                        $subtotalUsd = (float) $r->items->sum(fn ($i) => (float)$i->qty * (float)$i->unit_price);
                        $totalUsd    = max(0, $subtotalUsd - (float)$r->discount + (float)$r->shipping + (float)$r->extra_fees);
                        return number_format($totalUsd * (float)$r->exchange_rate, 2);
                    })
                    ->suffix(fn (Order $r) => ' ' . ($r->currency ?? 'USD'))
                    ->wrap(),

                Tables\Columns\TextColumn::make('created_at')->label(__('Created at'))->dateTime()->wrap()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')->label(__('Branch'))
                    ->options(fn () =>
                        Cache::remember('order_table_branch_opts', 300, fn () =>
                            Branch::query()->orderBy('name')->pluck('name', 'id')
                        )
                    ),
                Tables\Filters\SelectFilter::make('type')->label(__('Type'))
                    ->options(['proforma'=>__('Proforma'), 'order'=>__('Order')]),
                Tables\Filters\SelectFilter::make('payment_status')->label(__('Payment'))
                    ->options([
                        'unpaid' => __('Unpaid'),
                        'partially_paid' => __('Partially paid'),
                        'paid' => __('Paid'),
                        'debt' => __('Debt'),
                    ]),
                Tables\Filters\SelectFilter::make('currency')->label(__('Currency'))
                    ->options(fn () =>
                        Cache::remember('order_table_currency_opts', 300, fn () =>
                            ['USD'=>'USD'] + CurrencyRate::query()->orderBy('code')->pluck('code','code')->toArray()
                        )
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('pdf')
                    ->label(__('PDF'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (Order $record) => route('admin.orders.pdf', $record) . '?lang=' . app()->getLocale())
                    ->openUrlInNewTab(),
                Tables\Actions\EditAction::make()->label(__('Edit')),
            ])
            ->bulkActions([Tables\Actions\DeleteBulkAction::make()->label(__('Delete selected'))]);
    }

    public static function getRelations(): array
    {
        return [PaymentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit'   => Pages\EditOrder::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = Auth::user();

        if ($user?->hasRole('seller')) {
            $query->where('seller_id', $user->id);
        }

        return $query->with([
            'customer:id,name',
            'seller:id,name',
            'branch:id,code',
            'items:id,order_id,product_id,qty,unit_price',
        ]);
    }
}
