<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
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

        // math helper (USD for persistence; convert for display)
        $compute = function (array $items, float $discountUsd, float $shippingUsd, float $rate): array {
            $subtotalUsd = collect($items)->sum(function ($row) {
                $qty  = (float)($row['qty'] ?? 0);
                $unit = (float)($row['unit_price'] ?? 0); // stored/priced in USD
                return $qty * $unit;
            });

            $totalUsd = max(0, $subtotalUsd - $discountUsd + $shippingUsd);

            return [
                'subtotal_usd' => $subtotalUsd,
                'total_usd'    => $totalUsd,
                'subtotal_fx'  => $subtotalUsd * $rate,
                'total_fx'     => $totalUsd * $rate,
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
            # ───────────────────────────────────────────────────────────────────────
            # Order Info (Currency at the top)
            # ───────────────────────────────────────────────────────────────────────
            Forms\Components\Section::make(__('Order Info'))
                ->schema([
                    // Currency selector (drives all local inputs)
                    Forms\Components\Select::make('currency')
                        ->label(__('Currency'))
                        ->options(function () {
                            $opts = CurrencyRate::options();
                            return ['USD' => 'US Dollar'] + $opts;
                        })
                        ->default('USD')
                        ->reactive()
                        ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set) {
                            $rate = CurrencyRate::getRate($state ?: 'USD');
                            $set('exchange_rate', $rate);

                            // prime local mirrors (discount/shipping)
                            $set('discount_local', ((float)($get('discount') ?? 0)) * $rate);
                            $set('shipping_local', ((float)($get('shipping') ?? 0)) * $rate);

                            // prime each row's local unit price from persisted USD
                            foreach (array_keys((array)($get('items') ?? [])) as $i) {
                                $usd = (float) ($get("items.$i.unit_price") ?? 0);
                                $set("items.$i.unit_price_local", $usd * $rate);
                            }
                        })
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                            $rate = CurrencyRate::getRate($state ?: 'USD');
                            $set('exchange_rate', $rate);

                            // update local mirrors
                            $set('discount_local', ((float)($get('discount') ?? 0)) * $rate);
                            $set('shipping_local', ((float)($get('shipping') ?? 0)) * $rate);

                            // update each row's local unit price label/value
                            foreach (array_keys((array)($get('items') ?? [])) as $i) {
                                $usd = (float) ($get("items.$i.unit_price") ?? 0);
                                $set("items.$i.unit_price_local", $usd * $rate);
                            }
                        }),
                    // Persisted exchange rate (single source of truth)
                    Forms\Components\Hidden::make('exchange_rate')
                        ->default(fn (Forms\Get $get) => CurrencyRate::getRate($get('currency') ?: 'USD'))
                        ->dehydrated(true),

                    Forms\Components\Select::make('branch_id')
                        ->label(__('Branch'))
                        ->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
                        ->default(fn () => $user->hasRole('seller') ? $user->branch_id : null)
                        ->disabled(fn () => $user->hasRole('seller'))
                        ->required(),

                    Forms\Components\Select::make('customer_id')
                        ->label(__('Customer'))
                        ->options(fn () => Customer::orderBy('name')->pluck('name', 'id'))
                        ->searchable()->preload()->required()
                        ->reactive()
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                            // mirror to rows so "Previous sales to this customer" refreshes
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
                        ->createOptionUsing(fn (array $data) => Customer::create($data)->id),

                    Forms\Components\Select::make('type')
                        ->label(__('Type'))
                        ->options(['proforma' => __('Proforma'), 'order' => __('Order')])
                        ->default('proforma')->required(),

                    Forms\Components\Select::make('status')
                        ->label(__('Status'))
                        ->options([
                            'on_hold'   => __('On hold'),
                            'draft'     => __('Draft'),
                            'pending'   => __('Pending'),
                            'processing'=> __('Processing'),
                            'completed' => __('Completed'),
                            'cancelled' => __('Cancelled'),
                            'refunded'  => __('Refunded'),
                            'failed'    => __('Failed'),
                        ])
                        ->default('on_hold')
                        ->required(),

                    Forms\Components\TextInput::make('margin_percent')
                        ->label(__('Margin % over cost (optional)'))
                        ->numeric()->minValue(0)->step('0.01')
                ])->columns(5),

            # ───────────────────────────────────────────────────────────────────────
            # Items
            # ───────────────────────────────────────────────────────────────────────
            Forms\Components\Section::make(__('Items'))
                ->schema([
                    Forms\Components\Repeater::make('items')
                        ->label(__('Items'))
                        ->addActionLabel(__('Add item'))
                        ->relationship()
                        ->columns(12)
                        ->live(debounce: 250)
                        ->schema([
                            // Thumb
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
                                        '<img src="'.e($url).'" alt="'.e($alt).'" style="width:60px;height:60px;object-fit:contain;border-radius:6px;border:1px solid #ddd;" />'
                                    );
                                })
                                ->columnSpan(1)
                                ->disableLabel()
                                ->extraAttributes(['class' => 'pt-6']),

                            // helpers
                            Forms\Components\Hidden::make('__customer_mirror')->dehydrated(false)->reactive(),
                            Forms\Components\Hidden::make('stock_in_branch')->dehydrated(false),
                            Forms\Components\Hidden::make('cost_price')->dehydrated(false),

                            // USD price persisted (hidden) + local mirror shown to user
                            Forms\Components\Hidden::make('unit_price')->dehydrated(true),

                            Forms\Components\Select::make('product_id')
                                ->label(__('Product'))
                                ->options(function (Forms\Get $get) use ($titleFor, $locale) {
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
                                ->columnSpan(6)
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($compute, $setNestedTotals) {
                                    // prevent duplicate product rows
                                    if ($state) {
                                        $items = collect($get('../../items') ?? []);
                                        $dups  = $items->pluck('product_id')->filter()->countBy();
                                        if ((int)($dups[$state] ?? 0) > 1) {
                                            $set('product_id', null);
                                            $set('unit_price', null);
                                            $set('unit_price_local', null);
                                            $set('line_total', null);

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
                                    $price = (float)($p?->sale_price ?? $p?->price ?? 0); // USD

                                    // branch stock
                                    $chosenBranch = $get('../../branch_id') ?: auth()->user()?->branch_id;
                                    $stock = null;
                                    if ($state && $chosenBranch) {
                                        $prod  = Product::with(['stocks' => fn ($q) => $q->where('branch_id', $chosenBranch)])->find($state);
                                        $stock = optional($prod?->stocks?->first())->stock;
                                    }
                                    $stock = is_null($stock) ? null : (int)$stock;
                                    $set('stock_in_branch', $stock);
                                    $set('cost_price', $cost);

                                    // margin override if applicable (still USD)
                                    $margin = (float) ($get('../../margin_percent') ?? 0);
                                    if ($margin > 0 && $cost > 0) {
                                        $price = round($cost * (1 + $margin / 100), 2);
                                    }

                                    // persist USD and show local currency
                                    $rate = (float) ($get('../../exchange_rate') ?? 1);
                                    if ($rate <= 0) $rate = 1;

                                    $set('unit_price', $price); // USD
                                    $set('unit_price_local', $price * $rate);
                                    $set('line_total', $qty * $price); // USD

                                    if ($stock === 0) {
                                        Notification::make()
                                            ->title(__('Selected SKU has 0 stock in this branch'))
                                            ->body(__('You can still add it to the order.'))
                                            ->warning()
                                            ->persistent()
                                            ->send();
                                    }

                                    // recompute totals
                                    $t = $compute((array) ($get('../../items') ?? []), (float) ($get('../../discount') ?? 0), (float) ($get('../../shipping') ?? 0), $rate);
                                    $setNestedTotals($set, $t);
                                })
                                ->helperText(function (Forms\Get $get) {
                                    $productId    = $get('product_id');
                                    $chosenBranch = $get('../../branch_id') ?: auth()->user()?->branch_id;
                                    if (!$productId || !$chosenBranch) return null;

                                    $prod  = Product::with(['stocks' => fn ($q) => $q->where('branch_id', $chosenBranch)])->find($productId);
                                    $stock = optional($prod?->stocks?->first())->stock;

                                    return is_null($stock)
                                        ? null
                                        : ($stock == 0
                                            ? __('Available in branch: :count', ['count' => 0]) . ' — ' . __('out of stock')
                                            : __('Available in branch: :count', ['count' => $stock]));
                                }),

                            Forms\Components\TextInput::make('qty')
                                ->label(__('Qty'))
                                ->numeric()->minValue(1)->default(1)
                                ->inputMode('decimal')->step('1')
                                ->live(onBlur: true)
                                ->columnSpan(2)
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($compute, $setNestedTotals) {
                                    $qty  = (float) $state;
                                    $usd  = (float) ($get('unit_price') ?? 0);
                                    $set('line_total', $qty * $usd); // USD

                                    $rate = (float) ($get('../../exchange_rate') ?? 1);
                                    $t = $compute((array) ($get('../../items') ?? []), (float) ($get('../../discount') ?? 0), (float) ($get('../../shipping') ?? 0), $rate);
                                    $setNestedTotals($set, $t);
                                }),

                            // Local currency price editor (converts to USD under the hood)
                            Forms\Components\TextInput::make('unit_price_local')
                                ->label(fn (Forms\Get $get) => __('Unit price (:cur)', ['cur' => $get('../../currency') ?: 'USD']))
                                ->suffix(fn (Forms\Get $get) => $get('../../currency') ?: 'USD')
                                ->numeric()->step('0.01')->inputMode('decimal')
                                ->reactive()
                                ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set) {
                                    $rate = (float)($get('../../exchange_rate') ?? 1);
                                    if ($rate <= 0) $rate = 1;
                                    $usd  = (float)($get('unit_price') ?? 0);
                                    $set('unit_price_local', $usd * $rate);
                                })
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) use ($compute, $setNestedTotals) {
                                    $rate = (float)($get('../../exchange_rate') ?? 1);
                                    if ($rate <= 0) $rate = 1;

                                    // local → USD for persistence
                                    $usd = (float)$state / $rate;
                                    $set('unit_price', $usd);

                                    // update line total (USD)
                                    $qty = (float) ($get('qty') ?? 1);
                                    $set('line_total', $qty * $usd);

                                    // update totals
                                    $t = $compute((array) ($get('../../items') ?? []), (float) ($get('../../discount') ?? 0), (float) ($get('../../shipping') ?? 0), $rate);
                                    $setNestedTotals($set, $t);
                                })
                                ->helperText(function (Forms\Get $get) {
                                    $cost   = (float) ($get('cost_price') ?? 0);
                                    $margin = (float) ($get('../../margin_percent') ?? 0);
                                    $rate   = (float) ($get('../../exchange_rate') ?? 1);
                                    if ($cost <= 0 || $margin <= 0) return null;
                                    $local = round($cost * (1 + $margin/100) * max(1,$rate), 2);
                                    return __('Cost + margin ≈ :calc', ['calc' => number_format($local, 2)]);
                                })
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('line_total')
                                ->label(__('Total'))
                                ->numeric()
                                ->disabled()
                                ->columnSpan(1),

                            // Previous sales for selected customer
                            Forms\Components\Placeholder::make('prev_sales')
                                ->label(__('Previous sales to this customer'))
                                ->reactive()
                                ->content(function (Forms\Get $get) {
                                    $productId  = $get('product_id');
                                    $customerId = (int) ($get('../../customer_id') ?? 0);

                                    // Touch mirror so Filament tracks dependency
                                    $get('__customer_mirror');

                                    if (!$productId || !$customerId) {
                                        return new HtmlString('<div style="color:#6b7280;">' . e(__('No previous sales')) . '</div>');
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
                                        return new HtmlString('<div style="color:#6b7280;">' . e(__('No previous sales')) . '</div>');
                                    }

                                    $itemsHtml = [];
                                    foreach ($rows as $row) {
                                        $order = $row->order;
                                        if (!$order) continue;

                                        $unitUsd   = (float) ($row->unit_price ?? 0);
                                        $rate      = (float) ($order->exchange_rate ?? 1);
                                        $converted = number_format($unitUsd * $rate, 2);
                                        $cur       = $order->currency ?: 'USD';
                                        $date      = optional($order->created_at)->format('Y-m-d');

                                        $extra = '';
                                        if ($user->hasRole('admin')) {
                                            $parts = [];
                                            if ($order->customer?->name) $parts[] = e($order->customer->name);
                                            $seller = $order->seller;
                                            if ($seller && method_exists($seller, 'hasRole')) {
                                                if (!$seller->hasRole('admin')) $parts[] = e($seller->name);
                                            } elseif ($seller) {
                                                $parts[] = e($seller->name);
                                            }
                                            if (!empty($parts)) $extra = ' — ' . implode(' — ', $parts);
                                        }

                                        $itemsHtml[] = "<li style='color:#dc2626;'>{$converted} {$cur} <span style=\"opacity:.7\">({$date})</span>{$extra}</li>";
                                    }

                                    return new HtmlString(
                                        '<details style="margin:.25rem 0 0;" open>
                                           <summary style="cursor:pointer;user-select:none;">' . e(__('Previous sales')) . ' (' . count($itemsHtml) . ')</summary>
                                           <div style="max-height:200px;overflow:auto;margin-top:.35rem;">
                                             <ul style="margin:0;padding-left:1rem;list-style:disc;">' . implode('', $itemsHtml) . '</ul>
                                           </div>
                                         </details>'
                                    );
                                })
                                ->columnSpanFull(),
                        ])
                        ->afterStateUpdated(function (?array $state, Forms\Set $set, Forms\Get $get) use ($compute, $setNestedTotals) {
                            $rate = (float) ($get('../../exchange_rate') ?? 1);
                            $t = $compute((array) ($get('../../items') ?? $state ?? []), (float) ($get('../../discount') ?? 0), (float) ($get('../../shipping') ?? 0), $rate);
                            $setNestedTotals($set, $t);
                        }),
                ]),

            # ───────────────────────────────────────────────────────────────────────
            # Totals & Currency (no currency picker here anymore)
            # ───────────────────────────────────────────────────────────────────────
            Forms\Components\Section::make(__('Totals & Currency'))
                ->schema([
                    // persisted USD numbers
                    Forms\Components\Hidden::make('subtotal')->default(0),
                    Forms\Components\Hidden::make('discount')->default(0),
                    Forms\Components\Hidden::make('shipping')->default(0),
                    Forms\Components\Hidden::make('total')->default(0),

                    // Discount/Shipping local mirrors (use top-level currency)
                    Forms\Components\TextInput::make('discount_local')
                        ->label(fn (Forms\Get $get) => __('Discount'))
                        ->suffix(fn (Forms\Get $get) => $get('currency') ?: 'USD')
                        ->numeric()->default(0)->reactive()
                        ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set) {
                            $rate = (float)($get('exchange_rate') ?? 1);
                            if ($rate <= 0) $rate = 1;
                            $set('discount_local', ((float)($get('discount') ?? 0)) * $rate);
                        })
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                            $rate = (float)($get('exchange_rate') ?? 1);
                            if ($rate <= 0) $rate = 1;
                            $set('discount', ((float)$state) / $rate);
                        })
                        ->columnSpan(5),

                    Forms\Components\TextInput::make('shipping_local')
                        ->label(fn (Forms\Get $get) => __('Shipping'))
                        ->suffix(fn (Forms\Get $get) => $get('currency') ?: 'USD')
                        ->numeric()->default(0)->reactive()
                        ->afterStateHydrated(function ($state, Forms\Get $get, Forms\Set $set) {
                            $rate = (float)($get('exchange_rate') ?? 1);
                            if ($rate <= 0) $rate = 1;
                            $set('shipping_local', ((float)($get('shipping') ?? 0)) * $rate);
                        })
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                            $rate = (float)($get('exchange_rate') ?? 1);
                            if ($rate <= 0) $rate = 1;
                            $set('shipping', ((float)$state) / $rate);
                        })
                        ->columnSpan(5),

                    Forms\Components\Placeholder::make('exchange_rate_info')
                        ->label(__('Exchange rate'))
                        ->content(fn (Forms\Get $get) =>
                            '1 USD = ' . number_format((float)($get('exchange_rate') ?? 1), 6) . ' ' . ($get('currency') ?: 'USD')
                        )
                        ->columnSpanFull(),

                    // Subtotal (fx) – red
                    Forms\Components\Placeholder::make('subtotal_display')
                        ->label(fn (Forms\Get $get) => __('Subtotal (:cur)', ['cur' => $get('currency') ?: 'USD']))
                        ->content(function (Forms\Get $get) {
                            $rate  = (float)($get('exchange_rate') ?? 1);
                            $items = (array) ($get('items') ?? []);
                            if (empty($items) && $id = $get('id')) {
                                $order = Order::with('items')->find($id);
                                $items = $order
                                    ? $order->items->map(fn($i) => ['qty' => (float)$i->qty, 'unit_price' => (float)$i->unit_price])->toArray()
                                    : [];
                            }
                            $subtotalUsd = collect($items)->sum(fn ($r) => (float)($r['qty'] ?? 0) * (float)($r['unit_price'] ?? 0));
                            $fx = $subtotalUsd * $rate;
                            return new HtmlString('<span style="color:#dc2626;font-weight:600;">' . number_format($fx, 2) . '</span>');
                        })
                        ->columnSpan(6),

                    // Total (fx) – red
                    Forms\Components\Placeholder::make('total_display')
                        ->label(fn (Forms\Get $get) => __('Total (:cur)', ['cur' => $get('currency') ?: 'USD']))
                        ->content(function (Forms\Get $get) {
                            $rate = (float)($get('exchange_rate') ?? 1);

                            $items = (array) ($get('items') ?? []);
                            if (empty($items) && $id = $get('id')) {
                                $order = Order::with('items')->find($id);
                                $items = $order
                                    ? $order->items->map(fn($i) => ['qty' => (float)$i->qty, 'unit_price' => (float)$i->unit_price])->toArray()
                                    : [];
                            }

                            $subtotalUsd = collect($items)->sum(fn ($r) => (float)($r['qty'] ?? 0) * (float)($r['unit_price'] ?? 0));
                            $discountUsd = (float) ($get('discount') ?? 0);
                            $shippingUsd = (float) ($get('shipping') ?? 0);
                            $totalUsd    = max(0, $subtotalUsd - $discountUsd + $shippingUsd);
                            $fx          = $totalUsd * $rate;

                            return new HtmlString('<span style="color:#dc2626;font-weight:600;">' . number_format($fx, 2) . '</span>');
                        })
                        ->columnSpan(6),
                ])
                ->columns(12),
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
                    ->state(fn (Order $r) => $r->seller?->name ?? 'Admin')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('type')->label(__('Type'))->badge()->wrap()
                    ->color(fn (string $state): string => $state === 'order' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('status')->label(__('Status'))->badge()->wrap(),
                Tables\Columns\TextColumn::make('shipping_fx')
                    ->label(__('Shipping'))
                    ->state(fn (Order $r) => number_format((float)$r->shipping * (float)$r->exchange_rate, 2))
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
                Tables\Filters\SelectFilter::make('status')->label(__('Status'))->options([
                    'on_hold'=>__('On hold'),'draft'=>__('Draft'),'pending'=>__('Pending'),
                    'processing'=>__('Processing'),'completed'=>__('Completed'),
                    'cancelled'=>__('Cancelled'),'refunded'=>__('Refunded'),'failed'=>__('Failed'),
                ]),
                Tables\Filters\SelectFilter::make('currency')->label(__('Currency'))
                    ->options(fn () => ['USD'=>'USD'] + CurrencyRate::query()->orderBy('code')->pluck('code','code')->toArray()),
            ])
            ->actions([
                Tables\Actions\Action::make('pdf')->label(__('PDF'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (Order $record) => route('admin.orders.pdf', $record))
                    ->openUrlInNewTab(),
                Tables\Actions\EditAction::make()->label(__('Edit')),
            ])
            ->bulkActions([Tables\Actions\DeleteBulkAction::make()->label(__('Delete selected'))]);
    }

    public static function getRelations(): array { return []; }

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

        if ($user?->hasRole('seller') && $user->branch_id) {
            $query->where('branch_id', $user->branch_id);
        }

        // include items so list totals can be computed without N+1
        return $query->with(['customer','seller','branch','items']);
    }
}
