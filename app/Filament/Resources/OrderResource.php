<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\Product;
use App\Models\OrderItem;
use App\Models\ProductBranch;
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
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class OrderResource extends \Filament\Resources\Resource
{
    protected static ?string $model = Order::class;
    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';
    protected static ?string $navigationGroup = 'Sales';
    protected static ?string $navigationLabel = 'Orders';

    // ... (Keep existing methods: getEloquentQuery, getPages) ...
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();
        if ($user && $user->hasAnyRole(['Seller', 'seller']) && !$user->hasAnyRole(['Admin', 'admin'])) {
            $query->where('seller_id', $user->id);
        }
        return $query;
    }

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
            Grid::make(12)
                // =========================================================
                // 1. DEFINE GLOBAL ALPINE LOGIC AT THE ROOT GRID LEVEL
                //    This ensures functions are available everywhere in the form
                // =========================================================
                ->extraAttributes([
                    'x-data' => '{
                        calculateRow(el) {
                            // Find the row (Filament V3 structure)
                            let row = el.closest(".fi-fo-repeater-item"); 
                            if(!row) return;

                            // Select inputs by the classes we added via extraInputAttributes
                            let qtyInput = row.querySelector(".js-qty");
                            let priceInput = row.querySelector(".js-price");
                            let totalInput = row.querySelector(".js-line-total");

                            let qty = parseFloat(qtyInput?.value || 0);
                            let price = parseFloat(priceInput?.value || 0);
                            let total = (qty * price).toFixed(2);

                            if(totalInput) {
                                totalInput.value = total;
                                // Dispatch input event so Livewire/Alpine knows it changed
                                totalInput.dispatchEvent(new Event("input", { bubbles: true }));
                            }

                            this.calculateGrandTotal();
                        },

                        calculateGrandTotal() {
                            let subtotal = 0;
                            
                            // Sum up all line totals found in the DOM
                            document.querySelectorAll(".js-line-total").forEach(input => {
                                subtotal += parseFloat(input.value || 0);
                            });

                            // Get Footer Values
                            let discount = parseFloat(document.querySelector(".js-discount")?.value || 0);
                            let shipping = parseFloat(document.querySelector(".js-shipping")?.value || 0);
                            let feePercent = parseFloat(document.querySelector(".js-fee-percent")?.value || 0);

                            let fees = subtotal * (feePercent / 100);
                            let total = subtotal - discount + shipping + fees;

                            // Update Footer Fields
                            let subEl = document.querySelector(".js-subtotal");
                            let totEl = document.querySelector(".js-total");
                            let feeEl = document.querySelector(".js-extra-fees");

                            if(subEl) subEl.value = subtotal.toFixed(2);
                            if(totEl) totEl.value = (total > 0 ? total : 0).toFixed(2);
                            if(feeEl) feeEl.value = fees.toFixed(2);
                        }
                    }'
                ])
                ->schema([

                /* ===================== LEFT COLUMN ===================== */
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
                                ->columnSpan(9),

                            Placeholder::make('seller_info')
                                ->label(__('orders.seller'))
                                ->content(fn () => e(Auth::user()?->name ?? ''))
                                ->columnSpan(12),

                            Select::make('customer_id')
                                ->label(__('orders.customer'))
                                ->relationship('customer', 'name', function (Builder $query) {
                                    $user = Auth::user();
                                    if ($user && $user->hasAnyRole(['Seller', 'seller']) && !$user->hasAnyRole(['Admin', 'admin'])) {
                                        $query->where('seller_id', $user->id);
                                    }
                                    return $query;
                                })
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
                                        'seller_id' => ($user && $user->hasRole(['Seller', 'seller'])) ? $user->id : null,
                                    ])->getKey();
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
                                Action::make('bulk_add')
                                    ->label(__('Bulk Add via SKU'))
                                    ->icon('heroicon-m-rectangle-stack')
                                    ->color('info')
                                    ->form([
                                        Textarea::make('skus')
                                            ->label('Enter SKUs (one per line)')
                                            ->placeholder("TL123\nTL234\nTL555")
                                            ->rows(10)
                                            ->required()
                                    ])
                                    ->action(function (array $data, Get $get, Set $set) {
                                        $rawSkus = explode("\n", $data['skus']);
                                        $skus = array_filter(array_map('trim', $rawSkus));
                                        
                                        if (empty($skus)) return;

                                        $rate = (float) ($get('exchange_rate') ?? 1);
                                        $products = Product::whereIn('sku', $skus)->get()->keyBy('sku');

                                        $currentItems = $get('items') ?? [];
                                        $currentItems = array_values(is_array($currentItems) ? $currentItems : []);
                                        $nextIndex = count($currentItems) + 1;

                                        foreach ($skus as $sku) {
                                            $p = $products->get($sku);
                                            if (!$p) continue;

                                            $base = (float) ($p->sale_price ?? $p->price ?? 0);
                                            
                                            $currentItems[] = [
                                                'row_index'     => $nextIndex++,
                                                'is_custom'     => false,
                                                'product_id'    => $p->id,
                                                'product_name'  => $p->title,
                                                'sku'           => $p->sku,
                                                'qty'           => 1,
                                                'base_unit_usd' => $base,
                                                'unit_price'    => round($base * $rate, 2),
                                                'line_total'    => round($base * $rate, 2),
                                                'thumb'         => self::productThumbUrl($p->image),
                                                'note'          => null,
                                            ];
                                        }

                                        $set('items', $currentItems);
                                        static::recomputeTotals($get, $set, true);
                                        Notification::make()->title('Items added successfully')->success()->send();
                                    }),

                                Action::make('add_item_top')
                                    ->label('+ ' . __('orders.add_item'))
                                    ->button()
                                    ->action(function ($get, Set $set) {
                                        $items = $get('items') ?? [];
                                        $items = array_values(is_array($items) ? $items : []);
                                        
                                        // Validation
                                        if (!empty($items)) {
                                            $firstItem = $items[0]; 
                                            if ((!$firstItem['is_custom'] && empty($firstItem['product_id'])) || ($firstItem['is_custom'] && empty($firstItem['product_name']))) {
                                                Notification::make()
                                                    ->title(__('orders.validation_empty_item'))
                                                    ->warning()
                                                    ->send();
                                                return;
                                            }
                                        }

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
                                ->mutateRelationshipDataBeforeFillUsing(function (array $data): array {
                                    $pid = $data['product_id'] ?? null;
                                    $data['is_custom'] = empty($pid);
                                    
                                    $orderId = $data['order_id'] ?? null;
                                    $rate = 1;
                                    if ($orderId) {
                                        $order = Order::find($orderId);
                                        $rate = (float) ($order?->exchange_rate ?? 1);
                                    }
                                    if ($rate <= 0) $rate = 1;

                                    $savedPrice = (float)($data['unit_price'] ?? 0);
                                    $data['base_unit_usd'] = $savedPrice / $rate;

                                    if (!$data['is_custom'] && !empty($pid)) {
                                        $prod = Product::select('image', 'sku')->find($pid);
                                        $data['thumb'] = self::productThumbUrl($prod?->image);
                                        $data['sku']   = $prod?->sku;
                                    }
                                    
                                    return $data;
                                })
                                ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                                    if (empty($data['product_id'])) {
                                        $data['product_id'] = null;
                                        $data['sku'] = $data['sku'] ?? 'CUSTOM'; 
                                    } else {
                                        $p = Product::select('title','sku')->find($data['product_id']);
                                        $data['product_name'] = $p?->title;
                                        $data['sku'] = $p?->sku;
                                    }
                                    unset($data['thumb'], $data['row_index'], $data['base_unit_usd'], $data['is_custom']);
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
                                            ->label(__('Custom'))
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
                                                $set('base_unit_usd', 0);
                                                $set('line_total', 0);
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
                                                $set('product_id', null); 
                                                return;
                                            }

                                            $p = Product::find($state);

                                            if ($p) {
                                                $base = (float) ($p->sale_price ?? $p->price ?? 0);
                                                $rate = (float) ($get('../../exchange_rate') ?? 1);
                                                
                                                $set('product_name', $p->title);
                                                $set('base_unit_usd', $base);
                                                $set('unit_price', round($base * $rate, 2));
                                                $set('sku', $p->sku);
                                                $set('thumb', self::productThumbUrl($p->image));
                                                $set('qty', max(1, (int)$get('qty')));

                                                self::recomputeLineAndTotals($get, $set);
                                            }
                                        })
                                        ->columnSpan(5),

                                    TextInput::make('product_name') 
                                        ->label(__('Item Name'))
                                        ->visible(fn ($get) => $get('is_custom'))
                                        ->required(fn ($get) => $get('is_custom'))
                                        ->dehydrated(true)
                                        ->columnSpan(7),

                                    Placeholder::make('sku_display')
                                        ->label('SKU')
                                        ->content(fn ($get) => new HtmlString('<span style="color:#f97316;">' . e((string)($get('sku') ?? '—')) . '</span>'))
                                        ->visible(fn ($get) => ! $get('is_custom'))
                                        ->columnSpan(2),

                                    TextInput::make('sku')
                                        ->label('SKU')
                                        ->visible(fn ($get) => $get('is_custom'))
                                        ->dehydrated(true)
                                        ->columnSpan(2),

                                    // 2. USE extraInputAttributes to put class directly ON THE INPUT
                                    TextInput::make('qty')
                                        ->label(__('orders.qty'))
                                        ->numeric()
                                        ->minValue(1)
                                        ->default(1)
                                        ->extraInputAttributes(['class' => 'js-qty', 'x-on:input' => 'calculateRow($el)'])
                                        ->columnSpan(2),

                                    TextInput::make('unit_price')
                                        ->label(__('orders.unit'))
                                        ->numeric()
                                        ->default(0)
                                        ->extraInputAttributes(['class' => 'js-price', 'x-on:input' => 'calculateRow($el)'])
                                        ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                            $rate = (float) ($get('../../exchange_rate') ?? 1);
                                            if ($rate <= 0) $rate = 1;
                                            $set('base_unit_usd', (float)$state / $rate);
                                        })
                                        ->columnSpan(3),

                                    TextInput::make('line_total')
                                        ->label(__('orders.line_total'))
                                        ->numeric()
                                        ->readOnly()
                                        ->default(0)
                                        ->dehydrated(true)
                                        ->extraInputAttributes(['class' => 'js-line-total'])
                                        ->columnSpan(3),

                                    Placeholder::make('info_row')
                                        ->label(' ')
                                        ->content(function (Get $get) {
                                            if ($get('is_custom')) return null;
                                            $productId  = (int) $get('product_id');
                                            $branchId   = (int) $get('../../branch_id');
                                            $customerId = (int) $get('../../customer_id');
                                            if (!$productId) return null;
                                            $html = OrderResource::generateItemExtrasHtml($productId, $branchId, $customerId);
                                            return new HtmlString($html);
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

                 /* ===================== RIGHT COLUMN (TOTALS) ===================== */
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
                            
                            TextInput::make('subtotal')
                                ->numeric()
                                ->readOnly()
                                ->default(0)
                                ->extraInputAttributes(['class' => 'js-subtotal'])
                                ->columnSpan(6),
                            
                            TextInput::make('discount')
                                ->numeric()
                                ->default(0)
                                ->extraInputAttributes(['class' => 'js-discount', 'x-on:input' => 'calculateGrandTotal()'])
                                ->columnSpan(6),

                            TextInput::make('shipping')
                                ->numeric()
                                ->default(0)
                                ->extraInputAttributes(['class' => 'js-shipping', 'x-on:input' => 'calculateGrandTotal()'])
                                ->columnSpan(6),

                            TextInput::make('extra_fees_percent')
                                ->label('Extra Fees (%)')
                                ->numeric()
                                ->default(0)
                                ->extraInputAttributes(['class' => 'js-fee-percent', 'x-on:input' => 'calculateGrandTotal()'])
                                ->columnSpan(6),                         
                            
                            TextInput::make('paid_amount')
                                ->numeric()
                                ->default(0)
                                ->visible(fn($get) => $get('type') === 'order' && $get('payment_status') === 'partial')
                                ->columnSpan(12),
                         
                            TextInput::make('extra_fees')->hidden()->dehydrated()->numeric()->default(0)
                                ->extraInputAttributes(['class' => 'js-extra-fees']),

                            TextInput::make('total')
                                ->numeric()
                                ->readOnly()
                                ->default(0)
                                ->extraInputAttributes(['class' => 'js-total'])
                                ->columnSpan(12),

                            Actions::make([
                                Action::make('save_order')
                                    ->label('Save')
                                    ->button()
                                    ->color('primary')
                                    ->icon('heroicon-o-check-circle')
                                    ->action(function ($livewire) {
                                        if ($livewire instanceof \Filament\Resources\Pages\CreateRecord) {
                                            $livewire->create();
                                        } else {
                                            $livewire->save();
                                        }
                                    }),
                            ])->columnSpan(12)->alignment('center'),

                        ]),
                    ])
                    ->extraAttributes(['class' => 'xl:sticky', 'style' => 'position:sticky; top:96px;'])
                    ->columnSpan(['default' => 12, 'xl' => 4]),
            ]),
        ]);
    }

    // ... (Helper functions remain unchanged: generateItemExtrasHtml, recomputeLineAndTotals, recomputeTotals, table, productThumbUrl) ...

    public static function generateItemExtrasHtml(int $productId, int $branchId, int $customerId): string
    {
        if ($productId <= 0) return '';
        $parts = [];
        
        if ($branchId > 0) {
            $stock = ProductBranch::where('product_id', $productId)
                ->where('branch_id', $branchId)
                ->value('stock');

            $displayStock = $stock ?? 0;
            $color = ((int)$displayStock > 0) ? '#065f46' : '#b91c1c';
            $parts[] = "<span style='font-weight:700;color:{$color}'>".__('orders.stock').":</span> ". $displayStock;
        }

        if ($customerId > 0) {
            $prev = OrderItem::query()
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.customer_id', $customerId)
                ->where('order_items.product_id', $productId)
                ->orderByDesc('orders.created_at')
                ->limit(3) 
                ->get(['order_items.unit_price', 'orders.currency', 'orders.created_at']);
            
            if ($prev->count() > 0) {
                $items = $prev->map(function ($row) {
                    $price = number_format((float)$row->unit_price, 2);
                    $cur   = $row->currency;
                    $date  = Carbon::parse($row->created_at)->toDateString();
                    return "<div>{$price} {$cur} ({$date})</div>";
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
            $q = (float)($row['qty'] ?? 0);
            $u = (float)($row['unit_price'] ?? 0);
            $subtotal += ($q * $u);
        }
        
        $discount = (float) ($get('discount') ?? 0);
        $shipping = (float) ($get('shipping') ?? 0);
        $feesPct  = (float) ($get('extra_fees_percent') ?? 0); 
        
        $fees     = $subtotal * ($feesPct / 100.0);
        $total    = max(0, $subtotal - $discount + $shipping + $fees);
        
        if ($calledFromItem) {
            $set('../../subtotal', round($subtotal, 2));
            $set('../../total', round($total, 2));
            $set('../../extra_fees', round($fees, 2)); 
        } else {
            $set('subtotal', round($subtotal, 2));
            $set('total', round($total, 2));
            $set('extra_fees', round($fees, 2));
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('seller.name')
                    ->label(__('orders.seller'))
                    ->placeholder('No Seller')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('customer.name')->label(__('orders.customer'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('branch.name')->label(__('orders.branch'))->sortable(),
                Tables\Columns\TextColumn::make('type')->label(__('orders.type'))->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'order' => 'success',
                        'proforma' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('status')->label(__('orders.status'))->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'processing', 'pending' => 'warning',
                        'cancelled', 'failed' => 'danger',
                        'on_hold' => 'info',
                        default => 'primary',
                    }),
                Tables\Columns\TextColumn::make('payment_status')->label(__('orders.payment_status'))->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'partial' => 'warning',
                        'unpaid' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('total')->label(__('orders.total'))->money(fn ($record) => $record->currency ?? 'USD')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('Date')->date()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'completed' => 'Completed',
                    ]),
                Tables\Filters\SelectFilter::make('seller')
                    ->relationship('seller', 'name')
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