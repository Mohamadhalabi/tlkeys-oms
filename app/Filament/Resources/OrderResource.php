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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Filament\Notifications\Notification;

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

        // force 2 decimals everywhere we compute
        $r2 = fn ($v) => round((float) $v, 2);

        $applyCurrency = function (string $cur, Forms\Get $get, Forms\Set $set) use ($r2) {
            // Set currency
            $set('currency', $cur);

            // Get rate, default to 1 if missing
            $rate = (float) \App\Models\CurrencyRate::getRate($cur ?: 'USD');
            if ($rate <= 0) { $rate = 1; }

            // Persist exchange rate + top-level local amounts
            $set('exchange_rate', $rate);
            $set('discount_local', $r2(((float)($get('discount') ?? 0)) * $rate));
            $set('shipping_local', $r2(((float)($get('shipping') ?? 0)) * $rate));

            // Recompute each item's local fields
            foreach (array_keys((array)($get('items') ?? [])) as $i) {
                $usdUnit = (float) ($get("items.$i.unit_price") ?? 0);
                $qty     = (float) ($get("items.$i.qty") ?? 0);
                $usdLine = (float) ($get("items.$i.line_total") ?? ($qty * $usdUnit));

                $set("items.$i.unit_price_local", $r2($usdUnit * $rate));
                $set("items.$i.line_total_local", $r2($usdLine * $rate));
                $set("items.$i.__currency_mirror", microtime(true));
            }
        };



        $titleFor = function (Product $p, string $locale): string {
            if (method_exists($p, 'getTranslation')) {
                try {
                    $t = $p->getTranslation('title', $locale, false);
                    if (!empty($t)) return $t;
                } catch (\Throwable $e) {}
            }
            if (is_array($p->title ?? null)) {
                return $p->title[$locale] ?? ($p->title['en'] ?? reset($p->title) ?? '');
            }
            return (string) ($p->title ?? '');
        };

        $compute = function (array $items, float $discountUsd, float $shippingUsd, float $rate) use ($r2): array {
            $subtotalUsd = $r2(collect($items)->sum(function ($row) {
                $qty  = (float)($row['qty'] ?? 0);
                $unit = (float)($row['unit_price'] ?? 0);
                return $qty * $unit;
            }));
            $totalUsd = $r2(max(0, $subtotalUsd - $r2($discountUsd) + $r2($shippingUsd)));
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

        $fallbackImg = 'data:image/svg+xml;utf8,' . rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="60" height="60">
               <rect width="100%" height="100%" fill="#f3f4f6"/>
               <g fill="#9ca3af"><rect x="12" y="18" width="36" height="24" rx="3"/>
               <circle cx="30" cy="30" r="6"/></g></svg>'
        );

        return $form->schema([
            Forms\Components\Section::make(__('Order Info'))
                ->schema([
                    Forms\Components\Select::make('currency')
                        ->label(__('Currency'))
                        ->options(function () {
                            $opts = CurrencyRate::options();
                            return ['USD' => __('US Dollar')] + $opts;
                        })
                        ->default('USD')
                        ->reactive()
                        ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set) use ($r2) {
                            $rate = (float) CurrencyRate::getRate($state ?: 'USD');
                            $set('exchange_rate', $rate);
                            $set('discount_local', $r2(((float)($get('discount') ?? 0)) * $rate));
                            $set('shipping_local', $r2(((float)($get('shipping') ?? 0)) * $rate));
                            foreach (array_keys((array)($get('items') ?? [])) as $i) {
                                $usdUnit = (float) ($get("items.$i.unit_price") ?? 0);
                                $usdLine = (float) ($get("items.$i.line_total") ?? ((float)$get("items.$i.qty") * $usdUnit));
                                $set("items.$i.unit_price_local", $r2($usdUnit * $rate));
                                $set("items.$i.line_total_local", $r2($usdLine * $rate));
                                $set("items.$i.__currency_mirror", microtime(true));
                            }
                        })
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($r2) {
                            $rate = (float) CurrencyRate::getRate($state ?: 'USD');
                            $set('exchange_rate', $rate);
                            $set('discount_local', $r2(((float)($get('discount') ?? 0)) * $rate));
                            $set('shipping_local', $r2(((float)($get('shipping') ?? 0)) * $rate));
                            foreach (array_keys((array)($get('items') ?? [])) as $i) {
                                $usdUnit = (float) ($get("items.$i.unit_price") ?? 0);
                                $usdLine = (float) ($get("items.$i.line_total") ?? ((float)$get("items.$i.qty") * $usdUnit));
                                $set("items.$i.unit_price_local", $r2($usdUnit * $rate));
                                $set("items.$i.line_total_local", $r2($usdLine * $rate));
                                $set("items.$i.__currency_mirror", microtime(true));
                            }
                        })
                        ->columnSpan(['sm' => 2, 'md' => 2, 'lg' => 1]),

                    Forms\Components\Hidden::make('exchange_rate')
                        ->default(fn (Forms\Get $get) => CurrencyRate::getRate($get('currency') ?: 'USD'))
                        ->dehydrated(true),

                    Forms\Components\Select::make('branch_id')
                        ->label(__('Branch'))
                        ->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
                        ->default(fn () => $user->hasRole('seller') ? $user->branch_id : null)
                        ->disabled(fn () => $user->hasRole('seller'))
                        ->required()
                        ->reactive()
                        ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set) use ($applyCurrency) {
                            // keep your stock-mirroring behavior
                            foreach (array_keys((array)($get('items') ?? [])) as $i) {
                                $set("items.$i.__branch_mirror", (int) $state);
                                $pid = (int) ($get("items.$i.product_id") ?? 0);
                                if ($pid) {
                                    $prod  = Product::with(['stocks' => fn ($q) => $q->where('branch_id', (int) $state)])->find($pid);
                                    $stock = optional($prod?->stocks?->first())->stock;
                                    $set("items.$i.stock_in_branch", is_null($stock) ? null : (int) $stock);
                                }
                            }

                            // >>> NEW: on load, if branch code is SA, force SAR
                            if ($state) {
                                $code = optional(Branch::find((int)$state))->code;
                                if ($code === 'SA') {
                                    $applyCurrency('SAR', $get, $set);
                                }
                            }
                        })
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($applyCurrency) {
                            // >>> NEW: when branch changes to SA, force SAR
                            if ($state) {
                                $code = optional(Branch::find((int)$state))->code;
                                if ($code === 'SA') {
                                    $applyCurrency('SAR', $get, $set);
                                }
                            }

                            // keep your stock-mirroring behavior
                            $items = (array) ($get('items') ?? []);
                            foreach (array_keys($items) as $i) {
                                $set("items.$i.__branch_mirror", (int) $state);
                                $pid = (int) ($get("items.$i.product_id") ?? 0);
                                if ($pid) {
                                    $prod  = Product::with(['stocks' => fn ($q) => $q->where('branch_id', (int) $state)])->find($pid);
                                    $stock = optional($prod?->stocks?->first())->stock;
                                    $set("items.$i.stock_in_branch", is_null($stock) ? null : (int) $stock);
                                } else {
                                    $set("items.$i.stock_in_branch", null);
                                }
                            }
                        })
                        ->columnSpan(['sm' => 2, 'md' => 2, 'lg' => 1]),


                    Forms\Components\Select::make('type')
                        ->label(__('Type'))
                        ->options([
                            'proforma' => __('Proforma'),
                            'order' => __('Order'),
                        ])
                        ->default('proforma')
                        ->required()
                        ->reactive()
                        // once converted to order, we cannot go back to proforma
                        ->disabled(fn (Forms\Get $get, $record) => $record && $record->type === 'order')
                        ->columnSpan(['sm' => 2, 'md' => 1, 'lg' => 1]),

                    Forms\Components\Select::make('customer_id')
                        ->label(__('Customer'))
                        ->options(function () use ($user) {
                            $q = Customer::query()->orderBy('name');
                            if ($user?->hasRole('seller')) {
                                $q->where('seller_id', $user->id);
                            }
                            return $q->pluck('name','id');
                        })
                        ->searchable()
                        ->preload()
                        ->reactive()
                        ->required(fn (Forms\Get $get) => $get('type') === 'order')
                        ->rule(function (Forms\Get $get) {
                            return $get('type') === 'order'
                                ? ['required', 'exists:customers,id']
                                : ['nullable'];
                        })
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                            foreach (array_keys((array)($get('items') ?? [])) as $i) {
                                $set("items.$i.__customer_mirror", (int) $state);
                            }
                        })
                        ->createOptionForm([
                            Forms\Components\TextInput::make('name')->label(__('Name'))->required(),
                            Forms\Components\TextInput::make('email')->label(__('Email'))->email()->nullable(),
                            Forms\Components\TextInput::make('phone')->label(__('Phone'))->nullable(),
                            Forms\Components\Textarea::make('address')->label(__('Address'))->rows(3)->nullable(),
                        ])
                        ->createOptionUsing(function (array $data) use ($user) {
                            if ($user?->hasRole('seller')) $data['seller_id'] = $user->id;
                            return Customer::create($data)->id;
                        })
                        ->columnSpan(['sm' => 2, 'md' => 2, 'lg' => 1]),

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
                        ->numeric()
                        ->step('0.01')
                        ->rule('decimal:0,2')
                        ->minValue(0)
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
                            $subtotal = $r2(collect($items)->sum(function ($row) {
                                $qty  = (float)($row['qty'] ?? 0);
                                $unit = (float)($row['unit_price'] ?? 0);
                                return $qty * $unit;
                            }));
                            $discount = $r2((float) ($get('discount') ?? 0));
                            $shipping = $r2((float) ($get('shipping') ?? 0));
                            $total = $r2(max(0.0, $subtotal - $discount + $shipping));
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
                        ->numeric()->minValue(0)->step('0.01')
                        ->rule('decimal:0,2')
                        ->live(onBlur: true)
                        ->visible(fn () => (auth()->user()?->hasRole('admin') ?? false) || (auth()->user()?->can_see_cost ?? false))
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($canSellBelowCost, $canSeeCost, $r2) {
                            $items = (array) ($get('items') ?? []);
                            if (empty($items)) return;

                            $rate = (float) ($get('exchange_rate') ?? 1);
                            if ($rate <= 0) $rate = 1;

                            $margin = $r2($state ?? 0);

                            foreach (array_keys($items) as $i) {
                                $pid = (int) ($get("items.$i.product_id") ?? 0);
                                if (!$pid) continue;

                                $product = Product::find($pid);
                                $cost    = (float) ($get("items.$i.cost_price") ?? ($product?->cost_price ?? 0));
                                $qty     = (float) ($get("items.$i.qty") ?? 1);

                                $usd = (float) ($product?->sale_price ?? $product?->price ?? 0);
                                if ($margin > 0 && $cost > 0) {
                                    $usd = $r2($cost * (1 + $margin / 100));
                                } else {
                                    $usd = $r2($usd);
                                }

                                if (!$canSellBelowCost && $cost > 0 && $usd < $cost) {
                                    $usd = $r2($cost);
                                    $msg = $canSeeCost
                                        ? __('Unit price raised to cost (:cost USD).', ['cost' => number_format($cost, 2)])
                                        : __('Unit price raised to the minimum allowed.');
                                    Notification::make()->title($msg)->warning()->send();
                                }

                                $set("items.$i.unit_price", $usd);
                                $set("items.$i.unit_price_local", $r2($usd * $rate));
                                $set("items.$i.line_total", $r2($qty * $usd));
                                $set("items.$i.line_total_local", $r2($qty * $usd * $rate));
                                $set("items.$i.__currency_mirror", microtime(true));
                            }

                            $itemsNow = (array) ($get('items') ?? []);
                            $subtotalUsd = $r2(collect($itemsNow)->sum(fn ($r) => (float)($r['qty'] ?? 0) * (float)($r['unit_price'] ?? 0)));
                            $totalUsd    = $r2(max(0, $subtotalUsd - (float)$get('discount') + (float)$get('shipping')));
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
                    Forms\Components\Repeater::make('items')
                        ->label(__('Items'))
                        ->addActionLabel(__('Add item'))
                        ->relationship()
                        ->live(debounce: 250)
                        ->reorderable()
                        ->orderColumn('sort')
                        ->afterStateHydrated(function (?array $state, Forms\Get $get, Forms\Set $set) {
                            $items = (array)($state ?? $get('items') ?? []);
                            $idx = 1;
                            foreach ($items as $key => $_row) {
                                $set("items.$key.__index", $idx++);
                            }
                        })
                        ->afterStateUpdated(function (?array $state, Forms\Set $set, Forms\Get $get) use ($compute, $setNestedTotals) {
                            $items = (array)($state ?? $get('items') ?? []);
                            $idx = 1;
                            foreach ($items as $key => $_row) {
                                $set("items.$key.__index", $idx++);
                            }

                            $rate = (float) ($get('../../exchange_rate') ?? 1);
                            $t = $compute(
                                (array) ($get('../../items') ?? $state ?? []),
                                (float) ($get('../../discount') ?? 0),
                                (float) ($get('../../shipping') ?? 0),
                                $rate
                            );
                            $setNestedTotals($set, $t);
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
                                ->content(function (Forms\Get $get) use ($fallbackImg, $locale, $titleFor) {
                                    $productId = $get('product_id');
                                    $url = $fallbackImg; $alt = __('Product');
                                    if ($productId) {
                                        $product = Product::find($productId);
                                        if ($product) {
                                            $title = trim($titleFor($product, $locale));
                                            $alt   = $title !== '' ? $title : ($product->sku ?? __('Product'));
                                            if ($product->image) {
                                                $image = $product->image;
                                                $url   = filter_var($image, FILTER_VALIDATE_URL)
                                                    ? $image
                                                    : Storage::disk('public')->url($image);
                                            }
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
                                ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set) use ($r2) {
                                    if ($state === null && $pid = (int) $get('product_id')) {
                                        $set('cost_price', $r2(Product::find($pid)?->cost_price ?? 0));
                                    }
                                }),

                            Forms\Components\Hidden::make('unit_price')->dehydrated(true),
                            Forms\Components\Hidden::make('line_total')->dehydrated(false),

                            Forms\Components\Select::make('product_id')
                                ->label(__('Product'))
                                ->options(function (Forms\Get $get) use ($titleFor, $locale) {
                                    $get('__branch_mirror');
                                    $chosenBranch = $get('../../branch_id');
                                    $userBranch   = auth()->user()?->branch_id;
                                    $branchId     = $chosenBranch ?: $userBranch;

                                    $q = Product::query();
                                    if ($branchId && method_exists($q->getModel(), 'scopeForBranch')) {
                                        $q->forBranch($branchId);
                                    }

                                    return $q->orderBy('sku')->get()
                                        ->mapWithKeys(function (Product $p) use ($titleFor, $locale) {
                                            $title = trim($titleFor($p, $locale));
                                            $label = trim($p->sku . ($title !== '' ? ' — ' . $title : ''));
                                            return [$p->id => $label];
                                        })
                                        ->toArray();
                                })
                                ->searchable()
                                ->preload()
                                ->required()
                                ->reactive()
                                ->columnSpan(['default' => 1, 'sm' => 2, 'md' => 3, 'lg' => 5])
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($compute, $setNestedTotals, $canSellBelowCost, $canSeeCost, $r2) {
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

                                    $p   = $state ? Product::find($state) : null;
                                    $qty = (float) ($get('qty') ?? 1);

                                    $cost  = (float) ($p?->cost_price ?? 0);
                                    $price = (float)($p?->sale_price ?? $p?->price ?? 0);

                                    $chosenBranch = $get('../../branch_id') ?: auth()->user()?->branch_id;
                                    $stock = null;
                                    if ($state && $chosenBranch) {
                                        $prod  = Product::with(['stocks' => fn ($q) => $q->where('branch_id', $chosenBranch)])->find($state);
                                        $stock = optional($prod?->stocks?->first())->stock;
                                    }
                                    $stock = is_null($stock) ? null : (int)$stock;
                                    $set('stock_in_branch', $stock);
                                    $set('cost_price', $r2($cost));

                                    $margin = (float) ($get('../../margin_percent') ?? 0);
                                    if ($margin > 0 && $cost > 0) {
                                        $price = $r2($cost * (1 + $margin / 100));
                                    } else {
                                        $price = $r2($price);
                                    }

                                    if (!$canSellBelowCost && $cost > 0 && $price < $cost) {
                                        $price = $r2($cost);
                                        $msg = $canSeeCost
                                            ? __('Unit price raised to cost (:cost USD).', ['cost' => number_format($cost, 2)])
                                            : __('Unit price raised to the minimum allowed.');
                                        Notification::make()->title($msg)->warning()->send();
                                    }

                                    $rate = (float) ($get('../../exchange_rate') ?? 1);
                                    if ($rate <= 0) $rate = 1;

                                    $set('unit_price', $price);
                                    $set('unit_price_local', $r2($price * $rate));
                                    $set('line_total', $r2($qty * $price));
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

                                    $t = $compute((array) ($get('../../items') ?? []), (float) ($get('../../discount') ?? 0), (float) ($get('../../shipping') ?? 0), $rate);
                                    $setNestedTotals($set, $t);
                                })
                                ->helperText(function (Forms\Get $get) use ($canSeeCost, $r2) {
                                    $get('__branch_mirror'); $get('__currency_mirror');
                                    $stock   = $get('stock_in_branch');
                                    $costUsd = (float) ($get('cost_price') ?? 0);
                                    $rate = (float) ($get('../../exchange_rate') ?? 1);
                                    if ($rate <= 0) $rate = 1;
                                    $cur  = $get('../../currency') ?: 'USD';

                                    $bits = [];
                                    if ($canSeeCost && $costUsd > 0) {
                                        $bits[] = __('Cost: :usd USD (:fx :cur)', [
                                            'usd' => number_format($r2($costUsd), 2),
                                            'fx'  => number_format($r2($costUsd * $rate), 2),
                                            'cur' => $cur,
                                        ]);
                                    }
                                    return empty($bits) ? null : implode(' — ', $bits);
                                }),

                            Forms\Components\TextInput::make('qty')
                                ->label(__('Qty'))
                                ->numeric()->minValue(1)->default(1)
                                ->inputMode('decimal')->step('1')
                                ->live(onBlur: true)
                                ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 2, 'lg' => 2])
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($compute, $setNestedTotals, $r2) {
                                    $qty  = (float) $state;
                                    $usd  = (float) ($get('unit_price') ?? 0);
                                    $rate = (float) ($get('../../exchange_rate') ?? 1);
                                    if ($rate <= 0) $rate = 1;

                                    $set('line_total', $r2($qty * $usd));
                                    $set('line_total_local', $r2($qty * $usd * $rate));

                                    $t = $compute((array) ($get('../../items') ?? []), (float) ($get('../../discount') ?? 0), (float) ($get('../../shipping') ?? 0), $rate);
                                    $setNestedTotals($set, $t);
                                }),

                            Forms\Components\TextInput::make('unit_price_local')
                                ->label(fn (Forms\Get $get) => __('Unit price (:cur)', ['cur' => $get('../../currency') ?: 'USD']))
                                ->suffix(fn (Forms\Get $get) => $get('../../currency') ?: 'USD')
                                ->numeric()->step('0.01')->inputMode('decimal')
                                ->rule('decimal:0,2')
                                ->live(onBlur: true)
                                ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set) use ($r2) {
                                    $rate = (float)($get('../../exchange_rate') ?? 1);
                                    if ($rate <= 0) $rate = 1;
                                    $usd  = (float)($get('unit_price') ?? 0);
                                    $qty  = (float)($get('qty') ?? 1);
                                    $set('unit_price_local', $r2($usd * $rate));
                                    $set('line_total_local', $r2($qty * $usd * $rate));
                                })
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($compute, $setNestedTotals, $canSellBelowCost, $canSeeCost, $r2) {
                                    $rate = (float)($get('../../exchange_rate') ?? 1);
                                    if ($rate <= 0) $rate = 1;

                                    $usd = $r2(((float)$state) / $rate);

                                    $cost = (float) ($get('cost_price') ?? 0);
                                    if (!$canSellBelowCost && $cost > 0 && $usd < $cost) {
                                        $usd = $r2($cost);
                                        $set('unit_price_local', $r2($usd * $rate));
                                        $msg = $canSeeCost
                                            ? __('Unit price raised to cost (:cost USD).', ['cost' => number_format($cost, 2)])
                                            : __('Unit price raised to the minimum allowed.');
                                        Notification::make()->title($msg)->warning()->send();
                                    }

                                    $set('unit_price', $usd);

                                    $qty = (float) ($get('qty') ?? 1);
                                    $set('line_total', $r2($qty * $usd));
                                    $set('line_total_local', $r2($qty * $usd * $rate));

                                    $t = $compute((array) ($get('../../items') ?? []), (float) ($get('../../discount') ?? 0), (float) ($get('../../shipping') ?? 0), $rate);
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
                                ->content(function (Forms\Get $get) {
                                    $pid      = (int) ($get('product_id') ?? 0);
                                    $branchId = (int) ($get('../../branch_id') ?: (auth()->user()?->branch_id ?? 0));

                                    if (!$pid || !$branchId) {
                                        return new \Illuminate\Support\HtmlString(
                                            '<div style="color:#6b7280;margin-top:4px;">Stock: n/a</div>'
                                        );
                                    }

                                    $prod  = \App\Models\Product::with(['stocks' => fn ($q) => $q->where('branch_id', $branchId)])->find($pid);
                                    $stock = optional($prod?->stocks?->first())->stock;
                                    $s     = (int) ($stock ?? 0);

                                    // Detect language from app locale
                                    $locale = app()->getLocale();
                                    $label  = $locale === 'ar' ? 'المخزون' : 'Stock';
                                    $notAvailable = $locale === 'ar' ? 'غير متوفر' : 'n/a';

                                    // Pick color
                                    $color = $s === 0
                                        ? '#dc2626' // red
                                        : ($s > 0 ? '#16a34a' : '#6b7280'); // green or gray

                                    // Text
                                    $text = $stock === null
                                        ? "{$label}: {$notAvailable}"
                                        : "{$label}: {$s}";

                                    return new \Illuminate\Support\HtmlString(
                                        '<div style="margin-top:4px;font-weight:600;color:' . $color . ';">' . e($text) . '</div>'
                                    );
                                })
                                ->columnSpan(['default' => 1, 'sm' => 6, 'md' => 8, 'lg' => 12]),


                            Forms\Components\Placeholder::make('prev_sales')
                                ->label(__('Previous sales to this customer'))
                                ->reactive()
                                ->content(function (Forms\Get $get) use ($r2) {
                                    $productId  = $get('product_id');
                                    $customerId = (int) ($get('../../customer_id') ?? 0);
                                    $get('__customer_mirror');

                                    if (!$productId || !$customerId) {
                                        return new HtmlString('<div class="text-gray-500">' . e(__('No previous sales')) . '</div>');
                                    }

                                    $user = auth()->user();
                                    $q = OrderItem::query()
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
                    Forms\Components\Hidden::make('total')->default(0),

                    Forms\Components\TextInput::make('discount_local')
                        ->label(fn (Forms\Get $get) => __('Discount'))
                        ->suffix(fn (Forms\Get $get) => $get('currency') ?: 'USD')
                        ->numeric()->default(0)
                        ->step('0.01')
                        ->rule('decimal:0,2')
                        ->live(onBlur: true)
                        ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set) use ($r2) {
                            $rate = (float)($get('exchange_rate') ?? 1);
                            if ($rate <= 0) $rate = 1;
                            $set('discount_local', $r2(((float)($get('discount') ?? 0)) * $rate));
                        })
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($r2) {
                            $rate = (float)($get('exchange_rate') ?? 1);
                            if ($rate <= 0) $rate = 1;
                            $set('discount', $r2(((float)$state) / $rate));

                            $items = (array) ($get('items') ?? []);
                            $subtotalUsd = $r2(collect($items)->sum(fn ($r) => (float)($r['qty'] ?? 0) * (float)($r['unit_price'] ?? 0)));
                            $totalUsd    = $r2(max(0, $subtotalUsd - (float)$get('discount') + (float)$get('shipping')));
                            $set('subtotal', $subtotalUsd);
                            $set('total', $totalUsd);
                        })
                        ->columnSpan(['default' => 1, 'sm' => 2, 'md' => 3, 'lg' => 5]),

                    Forms\Components\TextInput::make('shipping_local')
                        ->label(fn (Forms\Get $get) => __('Shipping'))
                        ->suffix(fn (Forms\Get $get) => $get('currency') ?: 'USD')
                        ->numeric()->default(0)
                        ->step('0.01')
                        ->rule('decimal:0,2')
                        ->live(onBlur: true)
                        ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set) use ($r2) {
                            $rate = (float)($get('exchange_rate') ?? 1);
                            if ($rate <= 0) $rate = 1;
                            $set('shipping_local', $r2(((float)($get('shipping') ?? 0)) * $rate));
                        })
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($r2) {
                            $rate = (float)($get('exchange_rate') ?? 1);
                            if ($rate <= 0) $rate = 1;
                            $set('shipping', $r2(((float)$state) / $rate));

                            $items = (array) ($get('items') ?? []);
                            $subtotalUsd = $r2(collect($items)->sum(fn ($r) => (float)($r['qty'] ?? 0) * (float)($r['unit_price'] ?? 0)));
                            $totalUsd    = $r2(max(0, $subtotalUsd - (float)$get('discount') + (float)$get('shipping')));
                            $set('subtotal', $subtotalUsd);
                            $set('total', $totalUsd);
                        })
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
                                $order = Order::with('items')->find($id);
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
                            $rate = (float) ($get('exchange_rate') ?? 1);
                            $items = (array) ($get('items') ?? []);
                            if (empty($items) && $id = $get('id')) {
                                $order = Order::with('items')->find($id);
                                $items = $order
                                    ? $order->items->map(fn($i) => ['qty' => (float)$i->qty, 'unit_price' => (float)$i->unit_price])->toArray()
                                    : [];
                            }
                            $subtotalUsd = $r2(collect($items)->sum(fn ($r) => (float)($r['qty'] ?? 0) * (float)($r['unit_price'] ?? 0)));
                            $discountUsd = $r2((float) ($get('discount') ?? 0));
                            $shippingUsd = $r2((float) ($get('shipping') ?? 0));
                            $totalUsd    = $r2(max(0, $subtotalUsd - $discountUsd + $shippingUsd));
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
                    ->label(__('Type'))
                    ->badge()
                    ->wrap()
                    ->color(fn ($state) => $state === 'order' ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('payment_status')
                    ->label(__('Payment'))
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'paid'            => 'success',
                        'unpaid'          => 'danger',
                        'partially_paid'  => 'warning',
                        'debt'            => 'gray',
                        default           => 'gray',
                    }),

                Tables\Columns\TextColumn::make('shipping_fx')
                    ->label(__('Shipping'))
                    ->state(fn (Order $r) => number_format(((float)$r->shipping * (float)$r->exchange_rate), 2))
                    ->suffix(fn (Order $r) => ' ' . ($r->currency ?? 'USD'))
                    ->wrap()
                    ->sortable(false),

                Tables\Columns\TextColumn::make('total_fx')
                    ->label(__('Total (Currency)'))
                    ->state(function (Order $r) {
                        $subtotalUsd = (float) $r->items->sum(fn ($i) => (float)$i->qty * (float)$i->unit_price);
                        $totalUsd    = max(0, $subtotalUsd - (float)$r->discount + (float)$r->shipping);
                        return number_format($totalUsd * (float)$r->exchange_rate, 2);
                    })
                    ->suffix(fn (Order $r) => ' ' . ($r->currency ?? 'USD'))
                    ->wrap()
                    ->sortable(false),

                Tables\Columns\TextColumn::make('created_at')->label(__('Created at'))->dateTime()->wrap()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')->label(__('Branch'))
                    ->options(fn () => Branch::orderBy('name')->pluck('name', 'id')),
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
                    ->options(fn () => ['USD'=>'USD'] + CurrencyRate::query()->orderBy('code')->pluck('code','code')->toArray()),
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
        return [
            PaymentsRelationManager::class,
        ];
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

        return $query->with(['customer','seller','branch','items']);
    }
}