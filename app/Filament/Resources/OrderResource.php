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

        // 🔧 OPTIMIZED: Cache ALL expensive data ONCE
        $cachedData = Cache::remember("order_form_cache_{$user->id}_{$locale}", 300, function () use ($user, $locale) {
            return [
                'branches' => Branch::orderBy('name')->pluck('name', 'id')->toArray(),
                'currencies' => ['USD' => __('US Dollar')] + CurrencyRate::options(),
                'branch_codes' => Branch::pluck('code', 'id')->toArray(),
                'customer_options' => self::getCustomerOptions($user),
                'products_all' => Product::orderBy('sku')->get()->mapWithKeys(function ($p) use ($locale) {
                    $title = $p->getTranslation('title', $locale, false) ?? ($p->title[$locale] ?? ($p->title['en'] ?? $p->sku ?? ''));
                    return [$p->id => trim($p->sku . ($title ? ' — ' . $title : ''))];
                })->toArray(),
            ];
        });

        // force 2 decimals everywhere we compute
        $r2 = function ($v) {
            return round((float) $v, 2);
        };

        // 🔧 FIXED: Individual set calls ONLY
        $applyCurrency = function (string $cur, Forms\Get $get, Forms\Set $set) use ($r2) {
            $rate = max(1, (float) CurrencyRate::getRate($cur ?: 'USD'));
            $set('exchange_rate', $rate);
            $set('discount_local', $r2(((float)($get('discount') ?? 0)) * $rate));
            $set('shipping_local', $r2(((float)($get('shipping') ?? 0)) * $rate));
            
            // 🔧 FIXED: Loop INDIVIDUALLY - NO ARRAYS!
            foreach (array_keys((array)($get('items') ?? [])) as $i) {
                $usdUnit = (float) ($get("items.$i.unit_price") ?? 0);
                $qty     = (float) ($get("items.$i.qty") ?? 0);
                $usdLine = (float) ($get("items.$i.line_total") ?? ($qty * $usdUnit));
                $set("items.$i.unit_price_local", $r2($usdUnit * $rate));
                $set("items.$i.line_total_local", $r2($usdLine * $rate));
                $set("items.$i.__currency_mirror", microtime(true));
            }
        };

        // 🔧 OPTIMIZED: Simplified title function
        $titleFor = function (Product $p) use ($locale) {
            return $p->getTranslation('title', $locale, false) 
                ?? ($p->title[$locale] ?? ($p->title['en'] ?? $p->sku ?? ''));
        };

        // 🔧 OPTIMIZED: Memoized computation
        $compute = function (array $items, float $discountUsd, float $shippingUsd, float $rate) use ($r2) {
            $subtotalUsd = $r2(collect($items)->sum(function($row) {
                return (float)($row['qty'] ?? 0) * (float)($row['unit_price'] ?? 0);
            }));
            $totalUsd = $r2(max(0, $subtotalUsd - $r2($discountUsd) + $r2($shippingUsd)));
            return [
                'subtotal_usd' => $subtotalUsd,
                'total_usd'    => $totalUsd,
                'subtotal_fx'  => $r2($subtotalUsd * $rate),
                'total_fx'     => $r2($totalUsd * $rate),
            ];
        };

        $setNestedTotals = function (Forms\Set $set, array $t) {
            $set('../../subtotal', $t['subtotal_usd']);
            $set('../../total', $t['total_usd']);
        };

        // 🔧 FIXED: Individual stock updates
        $batchUpdateStock = function (int $branchId, Forms\Get $get, Forms\Set $set) use ($r2) {
            $items = collect((array)($get('items') ?? []))->pluck('product_id')->filter()->unique();
            if ($items->isEmpty()) return;

            // 🔧 SINGLE QUERY for ALL stocks
            $stocks = Product::with(['stocks' => function($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            }])->whereIn('id', $items)->get()->keyBy('id')->map(function($p) {
                return optional($p->stocks->first())->stock;
            });

            // 🔧 FIXED: Loop INDIVIDUALLY
            foreach ($items as $pid) {
                $set("items.*.stock_in_branch", $stocks[$pid] ? (int)$stocks[$pid] : null, true);
            }
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
                        ->options($cachedData['currencies'])
                        ->default('USD')
                        ->live(debounce: 100)
                        ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set) use ($applyCurrency) {
                            $applyCurrency($state ?: 'USD', $get, $set);
                        })
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($applyCurrency) {
                            $applyCurrency($state ?: 'USD', $get, $set);
                        })
                        ->columnSpan(['sm' => 2, 'md' => 2, 'lg' => 1]),

                    Forms\Components\Hidden::make('exchange_rate')
                        ->default(function(Forms\Get $get) {
                            return CurrencyRate::getRate($get('currency') ?: 'USD');
                        })
                        ->dehydrated(true),

                    Forms\Components\Select::make('branch_id')
                        ->label(__('Branch'))
                        ->options($cachedData['branches'])
                        ->default(function() use ($user) {
                            return $user->hasRole('seller') ? $user->branch_id : null;
                        })
                        ->disabled(function() use ($user) {
                            return $user->hasRole('seller');
                        })
                        ->required()
                        ->live(debounce: 100)
                        ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set) use ($applyCurrency, $batchUpdateStock, $cachedData) {
                            foreach (array_keys((array)($get('items') ?? [])) as $i) {
                                $set("items.$i.__branch_mirror", (int)$state);
                            }
                            if ($state) $batchUpdateStock((int)$state, $get, $set);
                            if ($state && ($cachedData['branch_codes'][(int)$state] ?? '') === 'SA') {
                                $applyCurrency('SAR', $get, $set);
                            }
                        })
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($applyCurrency, $batchUpdateStock, $cachedData) {
                            if (!$state) return;
                            if (($cachedData['branch_codes'][(int)$state] ?? '') === 'SA') {
                                $applyCurrency('SAR', $get, $set);
                            }
                            foreach (array_keys((array)($get('items') ?? [])) as $i) {
                                $set("items.$i.__branch_mirror", (int)$state);
                            }
                            $batchUpdateStock((int)$state, $get, $set);
                        })
                        ->columnSpan(['sm' => 2, 'md' => 2, 'lg' => 1]),

                    Forms\Components\Select::make('type')
                        ->label(__('Type'))
                        ->options(['proforma' => __('Proforma'), 'order' => __('Order')])
                        ->default('proforma')
                        ->required()
                        ->live(debounce: 100)
                        ->disabled(function (Forms\Get $get, $record) {
                            return $record && $record->type === 'order';
                        })
                        ->columnSpan(['sm' => 2, 'md' => 1, 'lg' => 1]),

                    Forms\Components\Select::make('customer_id')
                        ->label(__('Customer'))
                        ->options($cachedData['customer_options'])
                        ->searchable()->preload()
                        ->live(debounce: 100)
                        ->required(function(Forms\Get $get) {
                            return $get('type') === 'order';
                        })
                        ->rule(function(Forms\Get $get) {
                            return $get('type') === 'order' ? ['required', 'exists:customers,id'] : ['nullable'];
                        })
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                            collect((array)($get('items') ?? []))->keys()->each(function($i) use ($set, $state) {
                                $set("items.$i.__customer_mirror", (int)$state);
                            });
                        })
                        ->createOptionForm([
                            Forms\Components\TextInput::make('name')->label(__('Name'))->required(),
                            Forms\Components\TextInput::make('email')->label(__('Email'))->email()->nullable(),
                            Forms\Components\TextInput::make('phone')->label(__('Phone'))->nullable(),
                            Forms\Components\Textarea::make('address')->label(__('Address'))->rows(3)->nullable(),
                        ])
                        ->createOptionUsing(function (array $data) use ($user) {
                            $data['seller_id'] = $user?->hasRole('seller') ? $user->id : null;
                            return Customer::create($data)->id;
                        })
                        ->columnSpan(['sm' => 2, 'md' => 2, 'lg' => 1]),

                    Forms\Components\Select::make('payment_status')
                        ->label(__('Payment status'))
                        ->options(['unpaid' => __('Unpaid'), 'partially_paid' => __('Partially paid'), 'paid' => __('Paid'), 'debt' => __('Debt')])
                        ->default('unpaid')
                        ->visible(function(Forms\Get $get) {
                            return $get('type') === 'order';
                        })
                        ->dehydrated(function(Forms\Get $get) {
                            return $get('type') === 'order';
                        })
                        ->required(function(Forms\Get $get) {
                            return $get('type') === 'order';
                        })
                        ->live(debounce: 100)
                        ->columnSpan(['sm' => 2, 'md' => 2, 'lg' => 1]),

                    Forms\Components\TextInput::make('paid_amount')
                        ->label(__('Paid amount (USD)'))
                        ->numeric()->step('0.01')->rule('decimal:0,2')->minValue(0)
                        ->visible(function(Forms\Get $get) {
                            return $get('type') === 'order' && $get('payment_status') === 'partially_paid';
                        })
                        ->required(function(Forms\Get $get) {
                            return $get('type') === 'order' && $get('payment_status') === 'partially_paid';
                        })
                        ->live(onBlur: true)
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($r2) {
                            $set('paid_amount', $r2($state));
                        })
                        ->rule(function (Forms\Get $get) use ($r2) {
                            return function ($attribute, $value, $fail) use ($get, $r2) {
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
                            };
                        })
                        ->dehydrated(function(Forms\Get $get) {
                            return $get('payment_status') === 'partially_paid';
                        })
                        ->columnSpan(['sm' => 2, 'md' => 2, 'lg' => 1]),

                    Forms\Components\Select::make('status')
                        ->label(__('Order status'))
                        ->options(['on_hold' => __('On hold'), 'draft' => __('Draft'), 'pending' => __('Pending'), 
                                  'processing' => __('Processing'), 'completed' => __('Completed'), 'cancelled' => __('Cancelled'), 
                                  'refunded' => __('Refunded'), 'failed' => __('Failed')])
                        ->default('pending')
                        ->visible(function(Forms\Get $get) {
                            return $get('type') === 'order';
                        })
                        ->dehydrated(function(Forms\Get $get) {
                            return $get('type') === 'order';
                        })
                        ->columnSpan(['sm' => 2, 'md' => 2, 'lg' => 1]),

                    Forms\Components\TextInput::make('margin_percent')
                        ->label(__('Margin % over cost (optional)'))
                        ->numeric()->minValue(0)->step('0.01')->rule('decimal:0,2')
                        ->live(onBlur: true)
                        ->visible(function() use ($canSeeCost) {
                            return $canSeeCost;
                        })
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($canSellBelowCost, $canSeeCost, $r2) {
                            self::applyMarginToItems($state, $get, $set, $canSellBelowCost, $canSeeCost, $r2);
                        })
                        ->columnSpan(['sm' => 2, 'md' => 2, 'lg' => 1]),
                ])
                ->columns(['default' => 1, 'sm' => 2, 'md' => 3, 'lg' => 5]),

            Forms\Components\Section::make(__('Items'))
                ->schema([
                    Forms\Components\Repeater::make('items')
                        ->label(__('Items'))
                        ->addActionLabel(__('Add item'))
                        ->relationship()
                        ->live(debounce: 100)
                        ->reorderable()->orderColumn('sort')
                        ->afterStateHydrated(function (?array $state, Forms\Get $get, Forms\Set $set) use ($r2) {
                            collect((array)($state ?? $get('items') ?? []))->keys()->each(function($i, $idx) use ($set) {
                                $set("items.$i.__index", $idx + 1);
                            });
                        })
                        ->afterStateUpdated(function (?array $state, Forms\Set $set, Forms\Get $get) use ($r2) {
                            collect((array)($state ?? $get('items') ?? []))->keys()->each(function($i, $idx) use ($set) {
                                $set("items.$i.__index", $idx + 1);
                            });
                            self::updateOrderTotals($get, $set, $r2);
                        })
                        ->columns(['default' => 1, 'sm' => 6, 'md' => 8, 'lg' => 12])
                        ->schema([
                            Forms\Components\TextInput::make('__index')
                                ->label('#')->disabled()->dehydrated(false)
                                ->extraAttributes(['class' => 'text-center font-semibold w-10'])
                                ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 1, 'lg' => 1]),

                            Forms\Components\Placeholder::make('product_image')
                                ->label('')
                                ->content(function (Forms\Get $get) use ($fallbackImg, $titleFor) {
                                    $productId = $get('product_id');
                                    $url = $fallbackImg; $alt = __('Product');
                                    if ($productId) {
                                        $product = Product::find($productId);
                                        if ($product) {
                                            $title = trim($titleFor($product));
                                            $alt = $title ?: ($product->sku ?? __('Product'));
                                            if ($product->image) {
                                                $url = filter_var($product->image, FILTER_VALIDATE_URL) 
                                                    ? $product->image : Storage::disk('public')->url($product->image);
                                            }
                                        }
                                    }
                                    return new HtmlString(
                                        '<img src="'.e($url).'" alt="'.e($alt).'" class="w-12 h-12 sm:w-14 sm:h-14 md:w-16 md:h-16 object-contain rounded-md border border-gray-200" />'
                                    );
                                })
                                ->disableLabel()->extraAttributes(['class' => 'pt-4 sm:pt-5'])
                                ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 1, 'lg' => 1]),

                            Forms\Components\Hidden::make('__customer_mirror')->dehydrated(false)->live(debounce: 100),
                            Forms\Components\Hidden::make('__branch_mirror')->dehydrated(false)->live(debounce: 100),
                            Forms\Components\Hidden::make('__currency_mirror')->dehydrated(false)->live(debounce: 100),
                            Forms\Components\Hidden::make('stock_in_branch')->dehydrated(false),
                            Forms\Components\Hidden::make('sort')->dehydrated(true),
                            Forms\Components\Hidden::make('cost_price')->dehydrated(false)
                                ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set) use ($r2) {
                                    if ($state === null && $pid = (int)$get('product_id')) {
                                        $set('cost_price', $r2(Product::find($pid)?->cost_price ?? 0));
                                    }
                                }),
                            Forms\Components\Hidden::make('unit_price')->dehydrated(true),
                            Forms\Components\Hidden::make('line_total')->dehydrated(false),

                            Forms\Components\Select::make('product_id')
                                ->label(__('Product'))
                                ->options($cachedData['products_all'])
                                ->searchable()->preload()->required()
                                ->live(debounce: 100)
                                ->columnSpan(['default' => 1, 'sm' => 2, 'md' => 3, 'lg' => 5])
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($compute, $setNestedTotals, $canSellBelowCost, $canSeeCost, $r2) {
                                    self::handleProductSelection($state, $get, $set, $compute, $setNestedTotals, $canSellBelowCost, $canSeeCost, $r2);
                                }),

                            Forms\Components\TextInput::make('qty')
                                ->label(__('Qty'))->numeric()->minValue(1)->default(1)
                                ->inputMode('decimal')->step('1')
                                ->live(onBlur: true)
                                ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 2, 'lg' => 2])
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($r2) {
                                    self::updateItemLineTotal($state, $get, $set, $r2);
                                }),

                            Forms\Components\TextInput::make('unit_price_local')
                                ->label(function(Forms\Get $get) {
                                    return __('Unit price (:cur)', ['cur' => $get('../../currency') ?: 'USD']);
                                })
                                ->suffix(function(Forms\Get $get) {
                                    return $get('../../currency') ?: 'USD';
                                })
                                ->numeric()->step('0.01')->inputMode('decimal')->rule('decimal:0,2')
                                ->live(onBlur: true)
                                ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set) use ($r2) {
                                    $rate = max(1, (float)($get('../../exchange_rate') ?? 1));
                                    $usd = (float)($get('unit_price') ?? 0);
                                    $qty = (float)($get('qty') ?? 1);
                                    $set('unit_price_local', $r2($usd * $rate));
                                    $set('line_total_local', $r2($qty * $usd * $rate));
                                })
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($compute, $setNestedTotals, $canSellBelowCost, $canSeeCost, $r2) {
                                    self::updateFromLocalPrice($state, $get, $set, $compute, $setNestedTotals, $canSellBelowCost, $canSeeCost, $r2);
                                })
                                ->helperText(function (Forms\Get $get) use ($canSeeCost, $r2) {
                                    if (!$canSeeCost) return null;
                                    $cost   = (float) ($get('cost_price') ?? 0);
                                    $margin = (float) ($get('../../margin_percent') ?? 0);
                                    $rate   = max(1, (float) ($get('../../exchange_rate') ?? 1));
                                    if ($cost <= 0 || $margin <= 0) return null;
                                    $local = $r2($cost * (1 + $margin/100) * $rate);
                                    return __('Cost + margin ≈ :calc', ['calc' => number_format($local, 2)]);
                                })
                                ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 2, 'lg' => 2]),

                            Forms\Components\TextInput::make('line_total_local')
                                ->label(function(Forms\Get $get) {
                                    return __('Total (:cur)', ['cur' => $get('../../currency') ?: 'USD']);
                                })
                                ->suffix(function(Forms\Get $get) {
                                    return $get('../../currency') ?: 'USD';
                                })
                                ->disabled()->numeric()->extraAttributes(['class' => 'font-semibold'])
                                ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 2, 'lg' => 2]),

                            Forms\Components\Placeholder::make('stock_indicator')
                                ->label('')->live(debounce: 100)
                                ->content(function (Forms\Get $get) {
                                    $stock = $get('stock_in_branch');
                                    if (is_null($stock)) {
                                        return new HtmlString('<div style="color:#6b7280;margin-top:4px;">' . 
                                            e(app()->getLocale() === 'ar' ? 'المخزون: غير متوفر' : 'Stock: n/a') . '</div>');
                                    }
                                    $s = (int)$stock;
                                    $color = $s === 0 ? '#dc2626' : ($s > 0 ? '#16a34a' : '#6b7280');
                                    $label = app()->getLocale() === 'ar' ? 'المخزون' : 'Stock';
                                    return new HtmlString('<div style="margin-top:4px;font-weight:600;color:'.$color.';">' . 
                                        e($label . ': ' . $s) . '</div>');
                                })
                                ->columnSpan(['default' => 1, 'sm' => 6, 'md' => 8, 'lg' => 12]),

                            Forms\Components\Placeholder::make('prev_sales')
                                ->label(__('Previous sales to this customer'))
                                ->live(debounce: 150)
                                ->content(function (Forms\Get $get) use ($r2) {
                                    return self::getPreviousSalesHtml($get, $r2);
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
                        ->label(function(Forms\Get $get) {
                            return __('Discount');
                        })
                        ->suffix(function(Forms\Get $get) {
                            return $get('currency') ?: 'USD';
                        })
                        ->numeric()->default(0)->step('0.01')->rule('decimal:0,2')
                        ->live(onBlur: true)
                        ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set) use ($r2) {
                            $set('discount_local', $r2(((float)($get('discount') ?? 0)) * max(1, (float)($get('exchange_rate') ?? 1))));
                        })
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($r2) {
                            $set('discount', $r2(((float)$state) / max(1, (float)($get('exchange_rate') ?? 1))));
                            self::updateOrderTotals($get, $set, $r2);
                        })
                        ->columnSpan(['default' => 1, 'sm' => 2, 'md' => 3, 'lg' => 5]),

                    Forms\Components\TextInput::make('shipping_local')
                        ->label(function(Forms\Get $get) {
                            return __('Shipping');
                        })
                        ->suffix(function(Forms\Get $get) {
                            return $get('currency') ?: 'USD';
                        })
                        ->numeric()->default(0)->step('0.01')->rule('decimal:0,2')
                        ->live(onBlur: true)
                        ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set) use ($r2) {
                            $set('shipping_local', $r2(((float)($get('shipping') ?? 0)) * max(1, (float)($get('exchange_rate') ?? 1))));
                        })
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($r2) {
                            $set('shipping', $r2(((float)$state) / max(1, (float)($get('exchange_rate') ?? 1))));
                            self::updateOrderTotals($get, $set, $r2);
                        })
                        ->columnSpan(['default' => 1, 'sm' => 2, 'md' => 3, 'lg' => 5]),

                    Forms\Components\Placeholder::make('exchange_rate_info')
                        ->label(__('Exchange rate'))
                        ->content(function (Forms\Get $get) {
                            return '1 ' . __('USD') . ' = ' . number_format((float)($get('exchange_rate') ?? 1), 6) . ' ' . ($get('currency') ?: 'USD');
                        })
                        ->columnSpanFull(),

                    Forms\Components\Placeholder::make('subtotal_display')
                        ->label(function(Forms\Get $get) {
                            return __('Subtotal (:cur)', ['cur' => $get('currency') ?: 'USD']);
                        })
                        ->content(function (Forms\Get $get) use ($r2) {
                            return new HtmlString('<span class="text-red-600 font-semibold">' . 
                                number_format(self::getSubtotalFx($get, $r2), 2) . '</span>');
                        })
                        ->columnSpan(['default' => 1, 'sm' => 2, 'md' => 3, 'lg' => 6]),

                    Forms\Components\Placeholder::make('total_display')
                        ->label(function(Forms\Get $get) {
                            return __('Total (:cur)', ['cur' => $get('currency') ?: 'USD']);
                        })
                        ->content(function (Forms\Get $get) use ($r2) {
                            return new HtmlString('<span class="text-red-600 font-semibold">' . 
                                number_format(self::getTotalFx($get, $r2), 2) . '</span>');
                        })
                        ->columnSpan(['default' => 1, 'sm' => 2, 'md' => 3, 'lg' => 6]),
                ])
                ->columns(['default' => 1, 'sm' => 2, 'md' => 6, 'lg' => 12]),
        ]);
    }

    // 🔧 OPTIMIZED HELPER METHODS - ALL FIXED!
    private static function getCustomerOptions($user)
    {
        return Customer::when($user?->hasRole('seller'), function($q) use ($user) {
            return $q->where('seller_id', $user->id);
        })->orderBy('name')->pluck('name', 'id')->toArray();
    }

    private static function updateOrderTotals(Forms\Get $get, Forms\Set $set, $r2): void
    {
        $items = (array)($get('items') ?? []);
        $subtotalUsd = $r2(collect($items)->sum(function($r) {
            return (float)($r['qty'] ?? 0) * (float)($r['unit_price'] ?? 0);
        }));
        $totalUsd = $r2(max(0, $subtotalUsd - (float)$get('discount') + (float)$get('shipping')));
        $set('subtotal', $subtotalUsd);
        $set('total', $totalUsd);
    }

    private static function applyMarginToItems($margin, Forms\Get $get, Forms\Set $set, bool $canSellBelowCost, bool $canSeeCost, $r2): void
    {
        $items = collect((array)($get('items') ?? []))->filter(function($item) {
            return (int)($item['product_id'] ?? 0);
        });
        if ($items->isEmpty()) return;

        $rate = max(1, (float)($get('exchange_rate') ?? 1));

        // 🔧 FIXED: Loop INDIVIDUALLY - NO ARRAYS!
        foreach ($items as $i => $item) {
            $pid = (int)($item['product_id'] ?? 0);
            if (!$pid) continue;

            $product = Product::find($pid);
            $cost = (float)($item['cost_price'] ?? $product?->cost_price ?? 0);
            $qty = (float)($item['qty'] ?? 1);
            $usd = $margin > 0 && $cost > 0 ? $r2($cost * (1 + $margin / 100)) : $r2($product?->sale_price ?? $product?->price ?? 0);

            if (!$canSellBelowCost && $cost > 0 && $usd < $cost) {
                $usd = $r2($cost);
                Notification::make()->title($canSeeCost ? 
                    __('Unit price raised to cost (:cost USD).', ['cost' => number_format($cost, 2)]) : 
                    __('Unit price raised to the minimum allowed.'))->warning()->send();
            }

            $set("items.$i.unit_price", $usd);
            $set("items.$i.unit_price_local", $r2($usd * $rate));
            $set("items.$i.line_total", $r2($qty * $usd));
            $set("items.$i.line_total_local", $r2($qty * $usd * $rate));
            $set("items.$i.__currency_mirror", microtime(true));
        }

        self::updateOrderTotals($get, $set, $r2);
    }

    private static function handleProductSelection($state, Forms\Get $get, Forms\Set $set, $compute, $setNestedTotals, bool $canSellBelowCost, bool $canSeeCost, $r2): void
    {
        if ($state) {
            $items = collect($get('../../items') ?? []);
            if ($items->pluck('product_id')->filter()->countBy()[(int)$state] > 1) {
                $set('product_id', null); $set('unit_price', null); $set('unit_price_local', null);
                $set('line_total', null); $set('line_total_local', null);
                Notification::make()->title(__('Product already added to this order.'))->danger()->send();
                return;
            }
        }

        $p = $state ? Product::find($state) : null;
        $qty = (float)($get('qty') ?? 1);
        $cost = (float)($p?->cost_price ?? 0);
        $price = (float)($p?->sale_price ?? $p?->price ?? 0);

        $chosenBranch = $get('../../branch_id') ?: auth()->user()?->branch_id;
        $stock = $state && $chosenBranch ? Product::with(['stocks' => function($q) use ($chosenBranch) {
            $q->where('branch_id', $chosenBranch);
        }])->find($state)?->stocks?->first()?->stock : null;
        $set('stock_in_branch', $stock ? (int)$stock : null);
        $set('cost_price', $r2($cost));

        $margin = (float)($get('../../margin_percent') ?? 0);
        if ($margin > 0 && $cost > 0) $price = $r2($cost * (1 + $margin / 100));
        else $price = $r2($price);

        if (!$canSellBelowCost && $cost > 0 && $price < $cost) {
            $price = $r2($cost);
            Notification::make()->title($canSeeCost ? 
                __('Unit price raised to cost (:cost USD).', ['cost' => number_format($cost, 2)]) : 
                __('Unit price raised to the minimum allowed.'))->warning()->send();
        }

        $rate = max(1, (float)($get('../../exchange_rate') ?? 1));
        $set('unit_price', $price);
        $set('unit_price_local', $r2($price * $rate));
        $set('line_total', $r2($qty * $price));
        $set('line_total_local', $r2($qty * $price * $rate));
        $set('__currency_mirror', microtime(true));

        if ($stock === 0) {
            Notification::make()->title(__('Selected SKU has 0 stock in this branch'))
                ->body(__('You can still add it to the order.'))->warning()->persistent()->send();
        }

        $t = $compute((array)($get('../../items') ?? []), (float)($get('../../discount') ?? 0), (float)($get('../../shipping') ?? 0), $rate);
        $setNestedTotals($set, $t);
    }

    private static function updateItemLineTotal($qty, Forms\Get $get, Forms\Set $set, $r2): void
    {
        $usd = (float)($get('unit_price') ?? 0);
        $rate = max(1, (float)($get('../../exchange_rate') ?? 1));
        $set('line_total', $r2($qty * $usd));
        $set('line_total_local', $r2($qty * $usd * $rate));
        self::updateOrderTotals($get, $set, $r2);
    }

    private static function updateFromLocalPrice($state, Forms\Get $get, Forms\Set $set, $compute, $setNestedTotals, bool $canSellBelowCost, bool $canSeeCost, $r2): void
    {
        $rate = max(1, (float)($get('../../exchange_rate') ?? 1));
        $usd = $r2(((float)$state) / $rate);
        $cost = (float)($get('cost_price') ?? 0);

        if (!$canSellBelowCost && $cost > 0 && $usd < $cost) {
            $usd = $r2($cost);
            $set('unit_price_local', $r2($usd * $rate));
            Notification::make()->title($canSeeCost ? 
                __('Unit price raised to cost (:cost USD).', ['cost' => number_format($cost, 2)]) : 
                __('Unit price raised to the minimum allowed.'))->warning()->send();
        }

        $set('unit_price', $usd);
        $qty = (float)($get('qty') ?? 1);
        $set('line_total', $r2($qty * $usd));
        $set('line_total_local', $r2($qty * $usd * $rate));

        $t = $compute((array)($get('../../items') ?? []), (float)($get('../../discount') ?? 0), (float)($get('../../shipping') ?? 0), $rate);
        $setNestedTotals($set, $t);
    }

    private static function getPreviousSalesHtml(Forms\Get $get, $r2): HtmlString
    {
        $productId = $get('product_id');
        $customerId = (int)($get('../../customer_id') ?? 0);
        if (!$productId || !$customerId) {
            return new HtmlString('<div class="text-gray-500">' . e(__('No previous sales')) . '</div>');
        }

        $user = auth()->user();
        $q = OrderItem::with(['order:id,currency,exchange_rate,customer_id,seller_id,created_at'])
            ->where('product_id', $productId)
            ->whereHas('order', function($o) use ($customerId) {
                $o->where('customer_id', $customerId);
            })
            ->when(!$user->hasRole('admin'), function($q) use ($user) {
                return $q->whereHas('order', function($o) use ($user) {
                    $o->where('seller_id', $user->id);
                });
            })
            ->orderByDesc('created_at')
            ->limit(10);

        $rows = $q->get();
        if ($rows->isEmpty()) {
            return new HtmlString('<div class="text-gray-500">' . e(__('No previous sales')) . '</div>');
        }

        $itemsHtml = $rows->map(function($row) use ($r2) {
            $order = $row->order;
            return "<li class='text-red-600'>" . number_format($r2($row->unit_price * $order->exchange_rate), 2) . 
                " {$order->currency} <span class='opacity-70'>(" . $order->created_at->format('Y-m-d') . ")</span></li>";
        })->toArray();

        return new HtmlString(
            '<details class="mt-1" open>
               <summary class="cursor-pointer select-none">' . e(__('Previous sales')) . ' (' . count($itemsHtml) . ')</summary>
               <div class="max-h-48 overflow-auto mt-1.5">
                 <ul class="m-0 pl-4 list-disc">' . implode('', $itemsHtml) . '</ul>
               </div>
             </details>'
        );
    }

    private static function getSubtotalFx(Forms\Get $get, $r2): float
    {
        $rate = max(1, (float)($get('exchange_rate') ?? 1));
        $items = empty($get('items')) && $id = $get('id') ? 
            Order::with('items')->find($id)?->items->map(function($i) {
                return ['qty' => (float)$i->qty, 'unit_price' => (float)$i->unit_price];
            })->toArray() : (array)($get('items') ?? []);
        return $r2(collect($items)->sum(function($r) {
            return (float)($r['qty'] ?? 0) * (float)($r['unit_price'] ?? 0);
        }) * $rate);
    }

    private static function getTotalFx(Forms\Get $get, $r2): float
    {
        $rate = max(1, (float)($get('exchange_rate') ?? 1));
        $items = empty($get('items')) && $id = $get('id') ? 
            Order::with('items')->find($id)?->items->map(function($i) {
                return ['qty' => (float)$i->qty, 'unit_price' => (float)$i->unit_price];
            })->toArray() : (array)($get('items') ?? []);
        $subtotalUsd = $r2(collect($items)->sum(function($r) {
            return (float)($r['qty'] ?? 0) * (float)($r['unit_price'] ?? 0);
        }));
        $totalUsd = $r2(max(0, $subtotalUsd - (float)($get('discount') ?? 0) + (float)($get('shipping') ?? 0)));
        return $r2($totalUsd * $rate);
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
                    ->label(__('Seller'))->state(function(Order $r) {
                        return $r->seller?->name ?? __('Admin');
                    })->searchable()->wrap(),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('Type'))->badge()->wrap()->color(function($state) {
                        return $state === 'order' ? 'success' : 'gray';
                    }),
                Tables\Columns\TextColumn::make('payment_status')
                    ->label(__('Payment'))->badge()->color(function($state) {
                        return match($state) {
                            'paid' => 'success', 'unpaid' => 'danger', 'partially_paid' => 'warning', default => 'gray'
                        };
                    }),
                Tables\Columns\TextColumn::make('shipping_fx')
                    ->label(__('Shipping'))->state(function(Order $r) {
                        return number_format($r->shipping * $r->exchange_rate, 2);
                    })
                    ->suffix(function(Order $r) {
                        return ' ' . ($r->currency ?? 'USD');
                    })->wrap()->sortable(false),
                Tables\Columns\TextColumn::make('total_fx')
                    ->label(__('Total (Currency)'))->state(function(Order $r) {
                        return number_format(
                            max(0, $r->items->sum(function($i) {
                                return $i->qty * $i->unit_price;
                            }) - $r->discount + $r->shipping) * $r->exchange_rate, 2);
                    })
                    ->suffix(function(Order $r) {
                        return ' ' . ($r->currency ?? 'USD');
                    })->wrap()->sortable(false),
                Tables\Columns\TextColumn::make('created_at')->label(__('Created at'))->dateTime()->wrap()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')->label(__('Branch'))
                    ->options(function() {
                        return Branch::orderBy('name')->pluck('name', 'id');
                    }),
                Tables\Filters\SelectFilter::make('type')->label(__('Type'))
                    ->options(['proforma' => __('Proforma'), 'order' => __('Order')]),
                Tables\Filters\SelectFilter::make('payment_status')->label(__('Payment'))
                    ->options(['unpaid' => __('Unpaid'), 'partially_paid' => __('Partially paid'), 'paid' => __('Paid'), 'debt' => __('Debt')]),
                Tables\Filters\SelectFilter::make('currency')->label(__('Currency'))
                    ->options(function() {
                        return ['USD' => 'USD'] + CurrencyRate::orderBy('code')->pluck('code', 'code')->toArray();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('pdf')
                    ->label(__('PDF'))->icon('heroicon-o-arrow-down-tray')
                    ->url(function(Order $record) {
                        return route('admin.orders.pdf', $record) . '?lang=' . app()->getLocale();
                    })
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
        return parent::getEloquentQuery()
            ->when(Auth::user()?->hasRole('seller'), function($q) {
                return $q->where('seller_id', Auth::id());
            })
            ->with(['customer', 'seller', 'branch', 'items']);
    }
}