<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\Product;
use App\Models\OrderItem;
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
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
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
                                ->afterStateUpdated(function ($get, Set $set) {
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
                                ->afterStateUpdated(function ($get, Set $set, $state) {
                                    $items = $get('items') ?? [];
                                    $customerId = (int) ($get('customer_id') ?? 0);
                                    $branchId = (int) $state;

                                    foreach ($items as $uuid => $item) {
                                        if (empty($item['is_custom']) && !empty($item['product_id'])) {
                                            $html = self::generateItemExtrasHtml((int)$item['product_id'], $branchId, $customerId);
                                            $set("items.$uuid.extra_info_html", $html);
                                        }
                                    }
                                })
                                ->columnSpan(9),

                            Placeholder::make('seller_info')
                                ->content(fn () => __('orders.seller') . ': ' . e(Auth::user()?->name ?? ''))
                                ->columnSpan(12),

                            Select::make('customer_id')
                                ->label(__('orders.customer'))
                                ->searchable()
                                ->preload()
                                ->createOptionForm([
                                    TextInput::make('name')->required(),
                                    TextInput::make('email')->email(),
                                    TextInput::make('phone'),
                                    TextInput::make('address'),
                                ])
                                ->createOptionUsing(function (array $data) {
                                    $user = Auth::user();
                                    return Customer::create([
                                        'name'      => $data['name'],
                                        'email'     => $data['email'] ?? null,
                                        'phone'     => $data['phone'] ?? null,
                                        'address'   => $data['address'] ?? null,
                                        'seller_id' => $user?->hasRole('Seller') ? $user->id : null,
                                    ])->getKey();
                                })
                                ->getSearchResultsUsing(fn (string $search) => Customer::query()
                                    ->where('name', 'like', "%{$search}%")
                                    ->limit(50)->pluck('name', 'id'))
                                ->getOptionLabelUsing(fn ($value) => Customer::whereKey($value)->value('name'))
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
                                ->required(fn ($get) => $get('type') === 'order')
                                ->visible(fn ($get) => $get('type') === 'order')
                                ->columnSpan(6),

                            Select::make('payment_status')
                                ->label(__('orders.payment_status'))
                                ->options([
                                    'unpaid'  => __('orders.unpaid'),
                                    'partial' => __('orders.partial'),
                                    'paid'    => __('orders.paid'),
                                ])
                                ->required(fn ($get) => $get('type') === 'order')
                                ->visible(fn ($get) => $get('type') === 'order')
                                ->live()
                                ->afterStateUpdated(fn (Set $set, $state) => $state !== 'partial' ? $set('paid_amount', 0) : null)
                                ->columnSpan(6),
                        ]),
                    ]),

                    Section::make(__('orders.items'))
                        ->description(__('orders.items_desc'))
                        ->schema([

                            Actions::make([
                                Action::make('add_item_top')
                                    ->label('+ ' . __('orders.add_item'))
                                    ->button()
                                    ->action(function ($get, Set $set) {
                                        $items = $get('items') ?? [];
                                        $items = array_values(is_array($items) ? $items : []);
                                        array_unshift($items, [
                                            'is_custom'     => false,
                                            'product_id'    => null,
                                            'product_name'  => null,
                                            'sku'           => null,
                                            'qty'           => 1,
                                            'unit_price'    => 0,
                                            'line_total'    => 0,
                                            'base_unit_usd' => 0,
                                            'thumb'         => null,
                                            'note'          => null,
                                            'extra_info_html' => null, 
                                        ]);
                                        
                                        $i = 1; 
                                        foreach ($items as &$row) {
                                            $row = is_array($row) ? $row : [];
                                            $row['row_index'] = $i++;
                                        }
                                        $set('items', $items);
                                        static::recomputeTotals($get, $set, true);
                                    }),
                            ])->alignment('left'),

                            Repeater::make('items')
                                ->relationship('items', fn (EloquentBuilder $query) => $query->orderBy('sort'))
                                ->orderable('sort')
                                ->defaultItems(0)
                                ->columns(12)
                                ->addActionLabel('')
                                ->live()
                                ->afterStateUpdated(function ($get, Set $set, $state) {
                                    $items = is_array($state) ? $state : ($get('items') ?? []);
                                    $items = array_values(array_filter($items, fn ($r) => is_array($r)));

                                    $i = 1; 
                                    foreach ($items as $idx => $row) {
                                        $qty  = (float) ($row['qty'] ?? 0);
                                        $unit = (float) ($row['unit_price'] ?? 0);
                                        $items[$idx]['row_index']  = $i++;
                                        $items[$idx]['line_total'] = round($qty * $unit, 2);
                                    }
                                    $set('items', $items);
                                    static::recomputeTotals($get, $set, true);
                                })
                                
                                ->mutateRelationshipDataBeforeFillUsing(function (array $data): array {
                                    $pid = $data['product_id'] ?? null;
                                    $data['is_custom'] = empty($pid);

                                    if (!$data['is_custom'] && !empty($pid)) {
                                        $prod = Product::select('image','sku')->find($pid);
                                        $data['thumb'] = self::productThumbUrl($prod?->image);
                                        $data['sku']   = $prod?->sku;
                                    }
                                    return $data;
                                })

                                ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                                    if (empty($data['product_id'])) {
                                        // Custom Item: Use the 'sku' manually entered by user
                                        $data['product_id'] = null;
                                        $data['sku'] = $data['sku'] ?? 'CUSTOM'; 
                                    } else {
                                        // Standard Item: Snapshot the product details
                                        $p = Product::select('title','sku')->find($data['product_id']);
                                        $data['product_name'] = $p?->title;
                                        $data['sku'] = $p?->sku;
                                    }
                                    
                                    unset($data['thumb'], $data['row_index'], $data['base_unit_usd'], $data['is_custom'], $data['extra_info_html']);
                                    return $data; 
                                })

                                ->schema([
                                    Grid::make(12)->schema([
                                        Placeholder::make('row_index_disp')
                                            ->label('#')
                                            ->content(fn ($get) => (string)($get('row_index') ?? ''))
                                            ->extraAttributes(['style' => 'font-weight:600; color:#6b7280; padding-top:5px'])
                                            ->columnSpan(1),

                                        Toggle::make('is_custom')
                                            ->label('Custom Product')
                                            ->onIcon('heroicon-m-pencil-square')
                                            ->offIcon('heroicon-m-cube')
                                            ->inline(false)
                                            ->dehydrated(false) 
                                            ->live()
                                            ->afterStateUpdated(function (Set $set) {
                                                $set('product_id', null);
                                                $set('product_name', null);
                                                $set('sku', null);
                                                $set('thumb', null);
                                                $set('unit_price', 0);
                                                $set('line_total', 0);
                                                $set('extra_info_html', null);
                                            })
                                            ->columnSpan(11),
                                    ]),

                                    Placeholder::make('thumb')
                                        ->label(' ')
                                        ->content(function ($get) {
                                            if($get('is_custom')) return new HtmlString('');
                                            $url = $get('thumb');
                                            return $url
                                                ? new HtmlString('<img src="'.e($url).'" alt="" style="width:70px;height:70px;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb;" />')
                                                : new HtmlString('<div style="width:70px;height:70px;border-radius:8px;background:#f3f4f6;border:1px dashed #e5e7eb;"></div>');
                                        })
                                        ->columnSpan(fn($get) => $get('is_custom') ? 0 : 2),

                                    Select::make('product_id')
                                        ->label(__('orders.product'))
                                        ->searchable()
                                        ->preload(false)
                                        ->visible(fn ($get) => ! $get('is_custom'))
                                        ->required(fn ($get) => ! $get('is_custom'))
                                        ->getSearchResultsUsing(function (string $search) {
                                            $search = trim($search);
                                            $limit  = 20;
                                            $digitsOnly = preg_match('/^\d+$/', $search) === 1;

                                            $skuQuery = Product::query()->select('id', 'sku', 'title');
                                            if ($search !== '') {
                                                if ($digitsOnly) {
                                                    $skuQuery->where(function ($q) use ($search) {
                                                        $q->where('sku', 'like', '%' . $search)->orWhere('sku', 'like', '%' . $search . '%');
                                                    });
                                                } else {
                                                    $skuQuery->where(function ($q) use ($search) {
                                                        $q->where('sku', 'like', $search . '%')->orWhere('sku', 'like', '%' . $search . '%');
                                                    });
                                                }
                                            }
                                            $results = $skuQuery->limit($limit)->get();
                                            if ($results->count() < $limit) {
                                                $results = $results->concat(Product::where('title', 'like', "%$search%")->limit($limit - $results->count())->get());
                                            }
                                            
                                            return $results->mapWithKeys(fn (Product $p) => [
                                                $p->id => "{$p->sku} — ".str($p->title)->limit(70),
                                            ])->toArray();
                                        })
                                        ->getOptionLabelUsing(fn ($value) => Product::whereKey($value)->value('title'))
                                        ->live()
                                        ->afterStateUpdated(function ($state, $get, Set $set) {
                                            if (!$state) {
                                                $set('product_id', null); return;
                                            }

                                            $branchId = (int) ($get('../../branch_id') ?? 0);
                                            $customerId = (int) ($get('../../customer_id') ?? 0);

                                            $p = Product::query()
                                                ->leftJoin('product_branch as pb', function ($j) use ($branchId) {
                                                    $j->on('pb.product_id', '=', 'products.id')->where('pb.branch_id', '=', $branchId);
                                                })
                                                ->where('products.id', $state)
                                                ->first(['products.*']);

                                            if ($p) {
                                                $base = (float) ($p->sale_price ?? $p->price ?? 0);
                                                $rate = (float) ($get('../../exchange_rate') ?? 1);
                                                
                                                $set('product_name', $p->title);
                                                $set('base_unit_usd', $base);
                                                $set('unit_price', round($base * $rate, 2));
                                                $set('sku', $p->sku);
                                                $set('thumb', self::productThumbUrl($p->image));
                                                $set('qty', max(1, (int)$get('qty')));

                                                $html = self::generateItemExtrasHtml($p->id, $branchId, $customerId);
                                                $set('extra_info_html', $html);

                                                self::recomputeLineAndTotals($get, $set);
                                            }
                                        })
                                        ->columnSpan(5),

                                    TextInput::make('product_name') 
                                        ->label(__('Item Name'))
                                        ->placeholder('Enter item name')
                                        ->visible(fn ($get) => $get('is_custom'))
                                        ->required(fn ($get) => $get('is_custom'))
                                        ->dehydrated(true)
                                        ->columnSpan(7),

                                    // === CHANGED LOGIC FOR SKU DISPLAY ===
                                    
                                    // 1. Display Text for Standard Products
                                    Placeholder::make('sku_display')
                                        ->label('SKU')
                                        ->content(fn ($get) => new HtmlString('<span style="color:#f97316;">' . e((string)($get('sku') ?? '—')) . '</span>'))
                                        ->visible(fn ($get) => ! $get('is_custom'))
                                        ->columnSpan(2),

                                    // 2. Editable Input for Custom Products
                                    TextInput::make('sku')
                                        ->label('SKU')
                                        ->placeholder('Custom SKU')
                                        ->visible(fn ($get) => $get('is_custom'))
                                        ->dehydrated(true) // Make sure this saves!
                                        ->columnSpan(2),

                                    TextInput::make('qty')
                                        ->label(__('orders.qty'))
                                        ->numeric()
                                        ->minValue(1)
                                        ->default(1)
                                        ->live(debounce: 600)
                                        ->afterStateUpdated(fn ($get, Set $set) => self::recomputeLineAndTotals($get, $set))
                                        ->columnSpan(2),

                                    TextInput::make('unit_price')
                                        ->label(__('orders.unit'))
                                        ->numeric()
                                        ->default(0)
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(fn ($get, Set $set) => self::recomputeLineAndTotals($get, $set))
                                        ->columnSpan(3),

                                    TextInput::make('line_total')
                                        ->label(__('orders.line_total'))
                                        ->numeric()
                                        ->readOnly()
                                        ->default(0)
                                        ->dehydrated(true)
                                        ->columnSpan(3),

                                    TextInput::make('extra_info_html')->hidden()->dehydrated(false),

                                    Placeholder::make('info_row')
                                        ->label(' ')
                                        ->content(function ($get) {
                                            if ($get('is_custom')) {
                                                return null;
                                            }
                                            $html = $get('extra_info_html');
                                            if (!empty($html)) {
                                                return new HtmlString($html);
                                            }
                                            return new HtmlString('<span style="color:#9CA3AF;">'.e(__('orders.no_extra')).'</span>');
                                        })
                                        ->columnSpan(12),

                                    Textarea::make('note')
                                        ->label(__('orders.item_note'))
                                        ->rows(2)
                                        ->columnSpan(12),

                                    TextInput::make('base_unit_usd')->hidden()->dehydrated(false),
                                    TextInput::make('row_index')->hidden()->dehydrated(false),
                                ]),
                        ]),
                    
                     Section::make(__('orders.invoice_note'))
                        ->schema([
                            Textarea::make('invoice_note')
                                ->label(__('orders.invoice_note'))
                                ->rows(3)
                                ->dehydrated(true),
                        ])
                        ->collapsible()
                        ->collapsed()
                        ->columnSpanFull(),

                ])->columnSpan(['default' => 12, 'xl' => 8]),

                 Section::make(__('orders.currency_totals'))
                    ->schema([
                        Grid::make(12)->schema([
                            Select::make('currency')
                                ->label(__('orders.currency'))
                                ->options(fn () => CurrencyRate::orderBy('code')->pluck('code','code'))
                                ->default('USD')
                                ->live()
                                ->afterStateUpdated(function (string $state, $get, Set $set) {
                                    $rate = (float) (CurrencyRate::where('code', $state)->value('usd_to_currency') ?? 1);
                                    $set('exchange_rate', $rate);
                                    
                                    $rows = $get('items') ?? [];
                                    foreach (array_keys($rows) as $i) {
                                        if (!$get("items.$i.is_custom")) {
                                            $base = (float)($get("items.$i.base_unit_usd") ?? 0);
                                            if ($base > 0) {
                                                $set("items.$i.unit_price", round($base * $rate, 2));
                                                $q = (float)($get("items.$i.qty") ?? 0);
                                                $set("items.$i.line_total", round($q * $base * $rate, 2));
                                            }
                                        }
                                    }
                                    static::recomputeTotals($get, $set);
                                })
                                ->required()
                                ->columnSpan(4),

                            TextInput::make('exchange_rate')->numeric()->default(1.0)->disabled()->dehydrated()->columnSpan(8),
                            TextInput::make('subtotal')->numeric()->readOnly()->default(0)->columnSpan(6),
                            TextInput::make('discount')->numeric()->default(0)->live(onBlur:true)->afterStateUpdated(fn($g, Set $s)=>static::recomputeTotals($g,$s))->columnSpan(6),
                            TextInput::make('shipping')->numeric()->default(0)->live(onBlur:true)->afterStateUpdated(fn($g, Set $s)=>static::recomputeTotals($g,$s))->columnSpan(6),
                            TextInput::make('extra_fees_percent')->numeric()->default(0)->live(onBlur:true)->afterStateUpdated(fn($g, Set $s)=>static::recomputeTotals($g,$s))->columnSpan(6),
                            
                            TextInput::make('paid_amount')
                                ->numeric()
                                ->default(0)
                                ->visible(fn($get) => $get('type') === 'order' && $get('payment_status') === 'partial')
                                ->columnSpan(12),
                                
                            TextInput::make('total')->numeric()->readOnly()->default(0)->columnSpan(12),
                        ]),
                    ])
                    ->extraAttributes(['class' => 'xl:sticky', 'style' => 'position:sticky; top:96px;'])
                    ->columnSpan(['default' => 12, 'xl' => 4]),
            ]),
        ]);
    }

    // ... (Helpers unchanged)
    public static function generateItemExtrasHtml(int $productId, int $branchId, int $customerId): string
    {
        if ($productId <= 0) return '';
        $parts = [];
        if ($branchId > 0) {
            $stock = DB::table('product_branch')->where('product_id', $productId)->where('branch_id', $branchId)->value('stock');
            if ($stock !== null) {
                $color = ((int)$stock > 0) ? '#065f46' : '#b91c1c';
                $parts[] = "<span style='font-weight:700;color:{$color}'>".__('orders.stock').":</span> ". $stock;
            }
        }
        if ($customerId > 0) {
            $prev = OrderItem::query()
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.customer_id', $customerId)
                ->where('order_items.product_id', $productId)
                ->orderByDesc('orders.created_at')
                ->limit(10)
                ->get(['order_items.unit_price', 'orders.currency', 'orders.created_at']);
            if ($prev->count() > 0) {
                $items = $prev->map(function ($row) {
                    $price = number_format((float)$row->unit_price, 2);
                    $cur   = $row->currency;
                    $date  = Carbon::parse($row->created_at)->toDateString();
                    return "<div>{$price} {$cur} " . __('orders.on') . " {$date}</div>";
                })->implode('');
                $parts[] = "<div style='color:#374151;'><strong>".__('orders.prev_sales').":</strong><div>{$items}</div></div>";
            }
        }
        if (empty($parts)) return '<span style="color:#9CA3AF;">'.e(__('orders.no_extra')).'</span>';
        return '<div style="margin-top:6px">'.implode(' &nbsp; • &nbsp; ', $parts).'</div>';
    }

    public static function recomputeLineAndTotals($get, Set $set): void
    {
        $qty  = (float) ($get('qty') ?? 0);
        $unit = (float) ($get('unit_price') ?? 0);
        $set('line_total', round($qty * $unit, 2));
        static::recomputeTotals($get, $set, true);
    }

    public static function recomputeTotals($get, Set $set, bool $calledFromItem = false): void
    {
        $items = $calledFromItem ? ($get('../../items') ?? []) : ($get('items') ?? []);
        $items = array_values(array_filter($items, fn ($r) => is_array($r)));
        $subtotal = 0.0;
        foreach ($items as $row) {
            $subtotal += ((float)($row['qty']??0) * (float)($row['unit_price']??0));
        }
        $discount = (float) ($get('discount') ?? 0);
        $shipping = (float) ($get('shipping') ?? 0);
        $feesPct  = (float) ($get('extra_fees_percent') ?? 0);
        $fees     = $subtotal * ($feesPct / 100.0);
        $total    = max(0, $subtotal - $discount + $shipping + $fees);
        if ($calledFromItem) {
            $set('../../subtotal', round($subtotal, 2));
            $set('../../total', round($total, 2));
        } else {
            $set('subtotal', round($subtotal, 2));
            $set('total', round($total, 2));
        }
    }

public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label(__('orders.customer'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label(__('orders.branch'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label(__('orders.type'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'order' => 'success',
                        'proforma' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('orders.status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'processing', 'pending' => 'warning',
                        'cancelled', 'failed' => 'danger',
                        'draft', 'on_hold' => 'gray',
                        default => 'primary',
                    }),

                Tables\Columns\TextColumn::make('payment_status')
                    ->label(__('orders.payment_status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'partial' => 'warning',
                        'unpaid' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('total')
                    ->label(__('orders.total'))
                    ->money(fn ($record) => $record->currency ?? 'USD')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->date()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                // You can add filters here later (e.g., Filter by Status)
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'completed' => 'Completed',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('pdf')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (Order $record) => route('admin.orders.pdf', $record))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function productThumbUrl(?string $image): ?string
    {
        if (!$image) return null;
        if (str_starts_with($image, 'http')) return $image;
        return Storage::disk('public')->url($image);
    }
}