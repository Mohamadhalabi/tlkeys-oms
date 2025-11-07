<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Carbon\Carbon;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use App\Models\Order as OrderModel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\Cache;

class OrderResource extends \Filament\Resources\Resource
{
    protected static ?string $model = Order::class;
    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';
    protected static ?string $navigationGroup = 'Sales';
    protected static ?string $navigationLabel = 'Orders';

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit'   => Pages\EditOrder::route('/{record}/edit'),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Grid::make(12)->schema([

                /* ===================== LEFT ===================== */
                Grid::make()->schema([

                    Section::make(__('orders.type_parties'))->schema([
                        Grid::make(12)->schema([

                            Select::make('type')
                                ->label(__('orders.type'))
                                ->options([
                                    'proforma' => __('orders.proforma'),
                                    'order'    => __('orders.order'),
                                ])
                                ->default('proforma')
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set) {
                                    if ($get('type') !== 'order') {
                                        $set('status', null);
                                        $set('payment_status', null);
                                        $set('paid_amount', 0);
                                    }
                                })
                                ->columnSpan(3),

                            Select::make('branch_id')
                                ->label(__('orders.branch'))
                                ->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
                                ->searchable()
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set) {
                                    $rows = $get('items') ?? [];
                                    foreach (array_keys($rows) as $i) {
                                        $pid    = (int) ($get("items.$i.product_id") ?? 0);
                                        $branch = (int) ($get('branch_id') ?? 0);
                                        $set("items.$i.stock_info", static::branchStock($pid, $branch));
                                    }
                                })
                                ->columnSpan(9),

                            Placeholder::make('seller_info')
                                ->content(fn () => __('orders.seller') . ': ' . e(Auth::user()?->name ?? ''))
                                ->columnSpan(12),

                            Select::make('customer_id')
                                ->label(__('orders.customer'))
                                ->helperText(__('orders.customer_hint'))
                                ->searchable()
                                ->preload()
                                ->createOptionForm([
                                    TextInput::make('name')->label(__('orders.customer_name'))->required(),
                                    TextInput::make('email')->email()->label('Email')->nullable(),
                                    TextInput::make('phone')->label('Phone')->nullable(),
                                    TextInput::make('address')->label('Address')->nullable(),
                                ])
                                ->createOptionUsing(function (array $data) {
                                    $user = Auth::user();
                                    $isSeller = $user?->hasRole('Seller') ?? false;

                                    return Customer::create([
                                        'name'      => $data['name'],
                                        'email'     => $data['email'] ?? null,
                                        'phone'     => $data['phone'] ?? null,
                                        'address'   => $data['address'] ?? null,
                                        'seller_id' => $isSeller ? $user->id : null,
                                    ])->getKey();
                                })
                                ->getSearchResultsUsing(function (string $search) {
                                    return Customer::query()
                                        ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                                            ->orWhere('email', 'like', "%{$search}%")
                                            ->orWhere('phone', 'like', "%{$search}%"))
                                        ->limit(50)
                                        ->pluck('name', 'id');
                                })
                                ->getOptionLabelUsing(function ($value): ?string {
                                    if (!$value) return null;
                                    return Cache::remember("customer:name:$value", 86400, function () use ($value) {
                                        return Customer::whereKey($value)->value('name');
                                    });
                                })
                                ->columnSpan(12),

                            Select::make('status')
                                ->label(__('orders.status'))
                                ->options([
                                    'draft'      => __('orders.status_draft'),
                                    'pending'    => __('orders.status_pending'),
                                    'processing' => __('orders.status_processing'),
                                    'completed'  => __('orders.status_completed'),
                                    'cancelled'  => __('orders.status_cancelled'),
                                    'refunded'   => __('orders.status_refunded'),
                                    'failed'     => __('orders.status_failed'),
                                    'on_hold'    => __('orders.status_on_hold'),
                                ])
                                ->required(fn (Get $get) => $get('type') === 'order')
                                ->visible(fn (Get $get) => $get('type') === 'order')
                                ->columnSpan(6),

                            Select::make('payment_status')
                                ->label(__('orders.payment_status'))
                                ->options([
                                    'unpaid'  => __('orders.unpaid'),
                                    'partial' => __('orders.partial'),
                                    'paid'    => __('orders.paid'),
                                ])
                                ->required(fn (Get $get) => $get('type') === 'order')
                                ->visible(fn (Get $get) => $get('type') === 'order')
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set, ?string $state) {
                                    if ($state !== 'partial') {
                                        $set('paid_amount', 0);
                                    }
                                })
                                ->columnSpan(6),
                        ]),
                    ]),

                    Section::make(__('orders.items'))
                        ->description(__('orders.items_desc'))
                        ->schema([
                            Repeater::make('items')
                                ->relationship(
                                    name: 'items',
                                    modifyQueryUsing: fn (EloquentBuilder $query) => $query->orderBy('sort')
                                )
                                ->orderable('sort')
                                ->defaultItems(0)
                                ->columns(12)
                                ->mutateRelationshipDataBeforeFillUsing(function (array $data): array {
                                    $pid = (int) ($data['product_id'] ?? 0);

                                    if ($pid > 0) {
                                        $prod = Product::select('sku','image','price','sale_price')->find($pid);
                                        if ($prod) {
                                            $data['thumb'] = self::productThumbUrl($prod->image);
                                            $data['sku']   = $data['sku'] ?? $prod->sku;

                                            if (!isset($data['base_unit_usd']) || (float)$data['base_unit_usd'] <= 0) {
                                                $data['base_unit_usd'] = (float) ($prod->sale_price ?? $prod->price ?? 0);
                                            }
                                        }
                                    }

                                    $data['row_index'] = $data['sort'] ?? null;
                                    return $data;
                                })
                                ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                                    unset($data['thumb'],$data['row_index'],$data['stock_info'],$data['previous_sales'],$data['base_unit_usd']);
                                    return $data;
                                })
                                ->schema([
                                    Placeholder::make('row_index_disp')
                                        ->label('#')
                                        ->content(fn (Get $get) => (string)($get('row_index') ?? ''))
                                        ->extraAttributes(['style' => 'font-weight:600; color:#6b7280;'])
                                        ->columnSpan(1),

                                    Placeholder::make('thumb')
                                        ->label(' ')
                                        ->content(function (Get $get) {
                                            $url = $get('thumb');
                                            return $url
                                                ? new HtmlString('<img src="'.e($url).'" alt="" style="width:90px;height:90px;object-fit:cover;border-radius:10px;border:1px solid #e5e7eb;" />')
                                                : new HtmlString('<div style="width:90px;height:90px;border-radius:10px;background:#f3f4f6;border:1px dashed #e5e7eb;"></div>');
                                        })
                                        ->columnSpan(2),

                                    Select::make('product_id')
                                        ->label(__('orders.product'))
                                        ->searchable()
                                        ->preload(false)
                                        ->getSearchResultsUsing(function (string $search) {
                                            $search = trim($search);
                                            $limit  = 20;

                                            // Always try fast, indexed SKU prefix first
                                            $skuResults = Product::query()
                                                ->select('id', 'sku', 'title')
                                                ->when($search !== '', fn ($q) =>
                                                    $q->where('sku', 'like', $search.'%') // uses idx on sku
                                                )
                                                ->orderBy('sku')
                                                ->limit($limit)
                                                ->get();

                                            $results = collect($skuResults);

                                            // If we still need more, try FULLTEXT first; if not present, gracefully fall back to title prefix
                                            if ($results->count() < $limit && $search !== '') {
                                                $remaining = $limit - $results->count();
                                                $existingIds = $results->pluck('id')->all();

                                                try {
                                                    // Try FULLTEXT (requires index ft_products_title_sku)
                                                    $fulltext = Product::query()
                                                        ->select('id', 'sku', 'title')
                                                        ->whereRaw("MATCH (title, sku) AGAINST (? IN BOOLEAN MODE)", [$search.'*'])
                                                        ->whereNotIn('id', $existingIds)
                                                        ->limit($remaining)
                                                        ->get();

                                                    $results = $results->concat($fulltext);
                                                } catch (\Illuminate\Database\QueryException $e) {
                                                    // Error 1191 = FULLTEXT index missing → fallback to indexed title prefix search
                                                    // Build an ANDed series of prefix terms for multi-word searches, but keep it index-friendly:
                                                    // Use only the first word for prefix (indexed), and the rest as broad contains (small result set anyway)
                                                    $words = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                                                    $first = $words[0] ?? $search;

                                                    $fallback = Product::query()
                                                        ->select('id', 'sku', 'title')
                                                        ->where('title', 'like', $first.'%') // uses idx on title
                                                        ->when(count($words) > 1, function ($q) use ($words) {
                                                            // extra words as contains filters (may not use index but the set is already reduced by prefix)
                                                            foreach (array_slice($words, 1) as $w) {
                                                                $q->where('title', 'like', '%'.$w.'%');
                                                            }
                                                        })
                                                        ->whereNotIn('id', $existingIds)
                                                        ->orderBy('title')
                                                        ->limit($remaining)
                                                        ->get();

                                                    $results = $results->concat($fallback);
                                                }
                                            }

                                            return $results->mapWithKeys(fn (Product $p) => [
                                                $p->id => "{$p->sku} — ".str($p->title)->limit(70),
                                            ])->toArray();
                                        })

                                        ->getOptionLabelUsing(function ($value): ?string {
                                            if (!$value) return null;
                                            return Cache::remember("product:title:$value", 86400, function () use ($value) {
                                                return Product::whereKey($value)->value('title');
                                            });
                                        })
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                            if ($state) {
                                                // duplicate guard — early exit & notify
                                                $rows = $get('../../items') ?? [];
                                                $count = 0;
                                                foreach ($rows as $row) {
                                                    if ((int)($row['product_id'] ?? 0) === (int)$state) $count++;
                                                }
                                                if ($count > 1) {
                                                    $set('product_id', null);
                                                    Notification::make()->title(__('orders.item_exists'))->warning()->send();
                                                    return;
                                                }
                                            }

                                            $branchId = (int) ($get('../../branch_id') ?? 0);

                                            // One joined fetch for product + branch stock
                                            $p = $state
                                                ? Product::query()
                                                    ->leftJoin('product_branch as pb', function ($j) use ($branchId) {
                                                        $j->on('pb.product_id', '=', 'products.id')
                                                          ->where('pb.branch_id', '=', $branchId);
                                                    })
                                                    ->where('products.id', $state)
                                                    ->first([
                                                        'products.id',
                                                        'products.sku',
                                                        'products.price',
                                                        'products.sale_price',
                                                        'products.image',
                                                        DB::raw('COALESCE(pb.stock, 0) as branch_stock'),
                                                    ])
                                                : null;

                                            if (!$p) {
                                                $set('product_id', null);
                                                return;
                                            }

                                            $base = (float) ($p->sale_price ?? $p->price ?? 0);
                                            $rate = (float) ($get('../../exchange_rate') ?? 1);
                                            $converted = round($base * $rate, 2);

                                            $set('base_unit_usd', $base);
                                            $set('unit_price', $converted);
                                            $set('sku', $p->sku);
                                            $set('thumb', self::productThumbUrl($p->image));
                                            if ((int) $get('qty') <= 0) $set('qty', 1);

                                            $set('stock_info', (int) $p->branch_stock);

                                            self::recomputeLineAndTotals($get, $set);
                                        })
                                        ->columnSpan(6),

                                    Placeholder::make('sku_display')
                                        ->label('SKU')
                                        ->content(fn (Get $get) => new HtmlString('<span style="color:#f97316;">' . e((string)($get('sku') ?? '—')) . '</span>'))
                                        ->columnSpan(2),

                                    TextInput::make('sku')->hidden()->dehydrated(false),

                                    TextInput::make('qty')
                                        ->label(__('orders.qty'))
                                        ->numeric()
                                        ->minValue(1)
                                        ->default(1)
                                        ->live(debounce: 600) // reduce chatter
                                        ->afterStateUpdated(fn (Get $get, Set $set) => self::recomputeLineAndTotals($get, $set))
                                        ->columnSpan(2),

                                    TextInput::make('unit_price')
                                        ->label(__('orders.unit'))
                                        ->numeric()
                                        ->default(0)
                                        ->live(onBlur: true) // compute on blur, not every keystroke
                                        ->afterStateUpdated(fn (Get $get, Set $set) => self::recomputeLineAndTotals($get, $set))
                                        ->columnSpan(3),

                                    TextInput::make('line_total')
                                        ->label(__('orders.line_total'))
                                        ->numeric()
                                        ->readOnly()
                                        ->default(0)
                                        ->dehydrated(true)
                                        ->columnSpan(3),

                                    Placeholder::make('info_row')
                                        ->label(' ')
                                        ->content(function (Get $get) {
                                            $parts = [];

                                            $stock = $get('stock_info');
                                            if ($stock !== null) {
                                                $color = ((int)$stock > 0) ? '#065f46' : '#b91c1c';
                                                $parts[] = '<span style="font-weight:700;color:'.$color.'">'.__('orders.stock').':</span> '
                                                         . e((string)$stock);
                                            }

                                            $customerId = (int) ($get('../../customer_id') ?? 0);
                                            $productId  = (int) ($get('product_id') ?? 0);
                                            if ($customerId > 0 && $productId > 0) {
                                                $prev = self::previousCustomerSales($customerId, $productId, 10);
                                                if (!empty($prev)) {
                                                    $items = array_map(function ($row) {
                                                        $price = number_format((float)$row['unit_price'], 2);
                                                        $cur   = e($row['currency']);
                                                        $date  = e($row['date']);
                                                        return "<div>{$price} {$cur} " . __('orders.on') . " {$date}</div>";
                                                    }, $prev);

                                                    $parts[] = '<div style="color:#374151;"><strong>'.__('orders.prev_sales').':</strong><div>'
                                                             . implode('', $items)
                                                             . '</div></div>';
                                                }
                                            }

                                            if (empty($parts)) {
                                                return new HtmlString('<span style="color:#9CA3AF;">'.e(__('orders.no_extra')).'</span>');
                                            }

                                            return new HtmlString('<div style="margin-top:6px">'.implode(' &nbsp; • &nbsp; ', $parts).'</div>');
                                        })
                                        ->columnSpan(12),

                                    TextInput::make('base_unit_usd')->hidden()->dehydrated(false),
                                    TextInput::make('row_index')->hidden()->dehydrated(false),
                                    TextInput::make('stock_info')->hidden()->dehydrated(false),
                                ])
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set) {
                                    $rows = $get('items') ?? [];
                                    $i = 1;
                                    foreach (array_keys($rows) as $idx) {
                                        $set("items.$idx.row_index", $i++);
                                    }
                                    self::recomputeTotals($get, $set);
                                }),
                        ]),

                ])->columnSpan(['default' => 12, 'xl' => 8]),

                /* ===================== RIGHT (sticky) ===================== */
                Section::make(__('orders.currency_totals'))
                    ->schema([
                        Grid::make(12)->schema([

                            Select::make('currency')
                                ->label(__('orders.currency'))
                                ->options(fn () => CurrencyRate::orderBy('code')->pluck('code','code'))
                                ->default('USD')
                                ->live()
                                ->afterStateUpdated(function (string $state, Get $get, Set $set) {
                                    $oldRate = (float) ($get('exchange_rate') ?? 1.0);
                                    $newRate = Cache::remember("rate:$state", 3600, function () use ($state) {
                                        return (float) (CurrencyRate::where('code', $state)->value('usd_to_currency') ?? 1);
                                    });

                                    $set('exchange_rate', $newRate);

                                    $rows = $get('items') ?? [];
                                    foreach (array_keys($rows) as $i) {
                                        $base = (float) ($get("items.$i.base_unit_usd") ?? 0);
                                        if ($base <= 0) {
                                            $unit = (float) ($get("items.$i.unit_price") ?? 0);
                                            $base = $unit / max($oldRate, 0.000001);
                                            $set("items.$i.base_unit_usd", $base);
                                        }

                                        $qty     = (float) ($get("items.$i.qty") ?? 0);
                                        $newUnit = round($base * $newRate, 2);

                                        $set("items.$i.unit_price", $newUnit);
                                        $set("items.$i.line_total", round($qty * $newUnit, 2));
                                    }

                                    static::recomputeTotals($get, $set);
                                })
                                ->required()
                                ->columnSpan(4),

                            TextInput::make('exchange_rate')
                                ->label(fn (Get $get) => __('orders.usd_to_rate'))
                                ->numeric()
                                ->default(1.0)
                                ->disabled()
                                ->dehydrated()
                                ->columnSpan(8),

                            TextInput::make('subtotal')
                                ->label(__('orders.subtotal'))
                                ->numeric()
                                ->readOnly()
                                ->default(0)
                                ->dehydrated(true)
                                ->columnSpan(6),

                            TextInput::make('discount')
                                ->label(__('orders.discount'))
                                ->numeric()
                                ->default(0)
                                ->dehydrated(true)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Get $get, Set $set) => static::recomputeTotals($get, $set))
                                ->columnSpan(6),

                            TextInput::make('shipping')
                                ->label(__('orders.shipping'))
                                ->numeric()
                                ->default(0)
                                ->dehydrated(true)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Get $get, Set $set) => static::recomputeTotals($get, $set))
                                ->columnSpan(6),

                            TextInput::make('extra_fees_percent')
                                ->label(__('orders.extra_fees'))
                                ->helperText(__('orders.extra_fees_hint'))
                                ->numeric()
                                ->default(0)
                                ->dehydrated(true)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Get $get, Set $set) => static::recomputeTotals($get, $set))
                                ->columnSpan(6),

                            TextInput::make('paid_amount')
                                ->label(__('orders.paid_amount'))
                                ->numeric()
                                ->minValue(0)
                                ->helperText(__('orders.paid_amount_hint'))
                                ->default(0)
                                ->visible(fn (Get $get) =>
                                    $get('type') === 'order' && $get('payment_status') === 'partial'
                                )
                                ->required(fn (Get $get) =>
                                    $get('type') === 'order' && $get('payment_status') === 'partial'
                                )
                                ->dehydrated(true)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                    $total = (float) ($get('total') ?? 0);
                                    $val   = max(0, min((float) $state, $total));
                                    if ($val !== (float) $state) {
                                        $set('paid_amount', $val);
                                    }
                                })
                                ->columnSpan(12),

                        ]),
                    ])
                    ->extraAttributes([
                        'class' => 'xl:sticky',
                        'style' => 'position:sticky; top:96px; max-height:calc(100vh - 96px); overflow-y:auto; padding-right:.25rem; scrollbar-gutter:stable;'
                    ])
                    ->columnSpan(['default' => 12, 'xl' => 4]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('index')->label('#')->rowIndex(),
                TextColumn::make('id')->label('ID')->sortable()->toggleable(),
                TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state) => $state === 'order' ? 'success' : 'gray')
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->sortable(),
                TextColumn::make('branch.name')->label(__('orders.branch'))->sortable()->toggleable(),
                TextColumn::make('customer.name')->label(__('orders.customer'))->searchable()->toggleable(),

                TextColumn::make('subtotal')
                    ->label(__('orders.subtotal'))
                    ->formatStateUsing(fn (OrderModel $r) => number_format((float)$r->subtotal, 2).' '.($r->currency ?? 'USD'))
                    ->sortable(),

                TextColumn::make('total')
                    ->label(__('orders.total'))
                    ->formatStateUsing(fn (OrderModel $r) => number_format((float)$r->total, 2).' '.($r->currency ?? 'USD'))
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make()->visible(false),
                Tables\Actions\Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (Order $record) => route('admin.orders.pdf', $record))
                    ->openUrlInNewTab(),
                Tables\Actions\EditAction::make(),
            ]);
    }

    /* ===================== helpers ===================== */

    public static function recomputeLineAndTotals(Get $get, Set $set): void
    {
        $qty  = (float) ($get('qty') ?? 0);
        $unit = (float) ($get('unit_price') ?? 0);
        $set('line_total', round($qty * $unit, 2));

        static::recomputeTotals($get, $set, true);
    }

    public static function recomputeTotals(Get $get, Set $set, bool $calledFromItem = false): void
    {
        $items = $calledFromItem ? ($get('../../items') ?? []) : ($get('items') ?? []);
        $subtotal = 0.0;

        foreach ($items as $row) {
            $qty  = (float) ($row['qty'] ?? 0);
            $unit = (float) ($row['unit_price'] ?? 0);
            $subtotal += $qty * $unit;
        }

        $discount = (float) ($get('discount') ?? 0);
        $shipping = (float) ($get('shipping') ?? 0);
        $feesPct  = (float) ($get('extra_fees_percent') ?? 0);

        $fees  = $subtotal * ($feesPct / 100.0);
        $total = max(0, $subtotal - $discount + $shipping + $fees);

        if ($calledFromItem) {
            $set('../../subtotal', round($subtotal, 2));
            $set('../../total', round($total, 2));
        } else {
            $set('subtotal', round($subtotal, 2));
            $set('total', round($total, 2));
        }
    }

    protected static function branchStock(int $productId, int $branchId): ?int
    {
        if ($productId <= 0) return null;

        if ($branchId > 0) {
            $row = DB::table('product_branch')
                ->where('product_id', $productId)
                ->where('branch_id', $branchId)
                ->select('stock')
                ->first();

            if ($row) return (int) ($row->stock ?? 0);
        }

        return null;
    }

    public static function previousCustomerSales(int $customerId, int $productId, int $limit = 10): array
    {
        if ($customerId <= 0 || $productId <= 0) return [];
        $rows = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.customer_id', $customerId)
            ->where('order_items.product_id', $productId)
            ->orderByDesc('orders.created_at')
            ->limit($limit)
            ->get([
                'order_items.unit_price as unit_price',
                'orders.currency as currency',
                'orders.created_at as date',
            ]);

        return $rows->map(fn ($r) => [
            'unit_price' => (float) $r->unit_price,
            'currency'   => (string) ($r->currency ?? 'USD'),
            'date'       => Carbon::parse($r->date)->toDateString(),
        ])->all();
    }

    public static function productThumbUrl(?string $image): ?string
    {
        if (!$image) return null;
        if (preg_match('~^https?://~i', $image)) return $image;
        if (str_starts_with($image, 'storage/')) return url($image);

        return Cache::remember("thumb:$image", 86400, function () use ($image) {
            try {
                return Storage::disk('public')->url(ltrim($image, '/'));
            } catch (\Throwable $e) {
                return null;
            }
        });
    }
}
