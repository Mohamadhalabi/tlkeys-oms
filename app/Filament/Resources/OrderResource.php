<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\CurrencyRate;
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

        // Helper: get localized title from Product (works with Spatie Translatable or JSON/array or plain string)
        $titleFor = function (Product $p, string $locale): string {
            // Spatie\Translatable support
            if (method_exists($p, 'getTranslation')) {
                try {
                    $t = $p->getTranslation('title', $locale, false);
                    if (!empty($t)) return $t;
                } catch (\Throwable $e) {
                    // fall through
                }
            }
            // JSON/array column
            if (is_array($p->title ?? null)) {
                return $p->title[$locale] ?? ($p->title['en'] ?? reset($p->title) ?? '');
            }
            // Fallback plain string
            return (string) ($p->title ?? '');
        };

        // Compute totals from given pieces.
        $compute = function (array $items, float $discount, float $shipping, float $rate): array {
            $subtotal = collect($items)->sum(function ($row) {
                $qty  = (float)($row['qty'] ?? 0);
                $unit = (float)($row['unit_price'] ?? 0);
                return $qty * $unit;
            });
            $totalUsd = max(0, $subtotal - $discount + $shipping);

            return [
                'subtotal'                    => $subtotal,
                'total'                       => $totalUsd,
                'display_subtotal_converted'  => $subtotal * $rate,
                'display_total_converted'     => $totalUsd * $rate,
            ];
        };

        // Write totals from ROOT context.
        $setRootTotals = function (Forms\Set $set, array $t): void {
            $set('subtotal', $t['subtotal']);
            $set('total', $t['total']);
            $set('display_subtotal_converted', $t['display_subtotal_converted']);
            $set('display_total_converted', $t['display_total_converted']);
        };

        // Write totals from inside a REPEATER ROW context (note the "../../" prefixes).
        $setNestedTotals = function (Forms\Set $set, array $t): void {
            $set('../../subtotal', $t['subtotal']);
            $set('../../total', $t['total']);
            $set('../../display_subtotal_converted', $t['display_subtotal_converted']);
            $set('../../display_total_converted', $t['display_total_converted']);
        };

        // Tiny inline SVG fallback (works with no assets)
        $fallbackImg = 'data:image/svg+xml;utf8,' . rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="60" height="60">
               <rect width="100%" height="100%" fill="#f3f4f6"/>
               <g fill="#9ca3af">
                 <rect x="12" y="18" width="36" height="24" rx="3"/>
                 <circle cx="30" cy="30" r="6"/>
               </g>
             </svg>'
        );

        return $form->schema([
            Forms\Components\Section::make(__('Order Info'))
                ->schema([
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
                            'draft'     => __('Draft'),
                            'confirmed' => __('Confirmed'),
                            'paid'      => __('Paid'),
                            'cancelled' => __('Cancelled'),
                        ])->default('draft')->required(),
                ])->columns(4),

            Forms\Components\Section::make(__('Items'))
                ->schema([
                    Forms\Components\Repeater::make('items')
                        ->label(__('Items'))
                        ->addActionLabel(__('Add item'))
                        ->relationship()
                        ->columns(12)
                        ->live(debounce: 300)
                        ->schema([
                            // Product image (with default)
                            Forms\Components\Placeholder::make('product_image')
                                ->label('')
                                ->content(function (Forms\Get $get) use ($fallbackImg, $locale, $titleFor) {
                                    $productId = $get('product_id');

                                    $url   = $fallbackImg;
                                    $alt   = __('Product');
                                    if ($productId) {
                                        $product = Product::find($productId);
                                        if ($product) {
                                            // alt text = localized title (fallback to SKU)
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
                                        '<img src="' . e($url) . '" alt="' . e($alt) . '" ' .
                                        'style="width:60px;height:60px;object-fit:contain;' .
                                        'border-radius:6px;border:1px solid #ddd;" />'
                                    );
                                })
                                ->columnSpan(1)
                                ->reactive()
                                ->disableLabel()
                                ->extraAttributes(['class' => 'pt-6']),

                            // Product select: "SKU — Localized Title"
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
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set)
                                        use ($compute, $setNestedTotals) {

                                    // Duplicate guard
                                    if ($state) {
                                        $items = collect($get('../../items') ?? []);
                                        $dups  = $items->pluck('product_id')->filter()->countBy();
                                        if ((int)($dups[$state] ?? 0) > 1) {
                                            $set('product_id', null);
                                            $set('unit_price', null);
                                            $set('line_total', null);

                                            Notification::make()
                                                ->title(__('orders.product_exists'))
                                                ->danger()
                                                ->send();
                                            return;
                                        }
                                    }

                                    // Set unit price / line total for this row
                                    $p = $state ? Product::find($state) : null;
                                    $price = (float)($p?->sale_price ?? $p?->price ?? 0);
                                    $qty   = (float) ($get('qty') ?? 1);

                                    $set('unit_price', $price);
                                    $set('line_total', $qty * $price);

                                    // Recompute totals (from nested scope)
                                    $items    = (array) ($get('../../items') ?? []);
                                    $discount = (float) ($get('../../discount') ?? 0);
                                    $shipping = (float) ($get('../../shipping') ?? 0);
                                    $rate     = (float) ($get('../../exchange_rate') ?? 1);

                                    $t = $compute($items, $discount, $shipping, $rate);
                                    $setNestedTotals($set, $t);
                                })
                                ->helperText(function (Forms\Get $get) {
                                    $productId    = $get('product_id');
                                    $chosenBranch = $get('../../branch_id');
                                    $branchId     = $chosenBranch ?: auth()->user()?->branch_id;
                                    if (!$productId || !$branchId) return null;

                                    $prod  = Product::with(['stocks' => fn ($q) => $q->where('branch_id', $branchId)])->find($productId);
                                    $stock = optional($prod?->stocks?->first())->stock;

                                    return is_null($stock) ? null : __('Available in branch: :count', ['count' => $stock]);
                                }),

                            Forms\Components\TextInput::make('qty')
                                ->label(__('Qty'))
                                ->numeric()
                                ->minValue(1)
                                ->default(1)
                                ->inputMode('decimal')
                                ->step('1')
                                ->live(onBlur: true)
                                ->columnSpan(1)
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set)
                                        use ($compute, $setNestedTotals) {
                                    $qty   = (float) $state;
                                    $price = (float) ($get('unit_price') ?? 0);
                                    $set('line_total', $qty * $price);

                                    $items    = (array) ($get('../../items') ?? []);
                                    $discount = (float) ($get('../../discount') ?? 0);
                                    $shipping = (float) ($get('../../shipping') ?? 0);
                                    $rate     = (float) ($get('../../exchange_rate') ?? 1);

                                    $t = $compute($items, $discount, $shipping, $rate);
                                    $setNestedTotals($set, $t);
                                }),

                            Forms\Components\TextInput::make('unit_price')
                                ->label(__('Unit price'))
                                ->numeric()
                                ->step('0.01')
                                ->inputMode('decimal')
                                ->live(onBlur: true)
                                ->columnSpan(1)
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set)
                                        use ($compute, $setNestedTotals) {
                                    $qty = (float) ($get('qty') ?? 1);
                                    $set('line_total', $qty * (float) $state);

                                    $items    = (array) ($get('../../items') ?? []);
                                    $discount = (float) ($get('../../discount') ?? 0);
                                    $shipping = (float) ($get('../../shipping') ?? 0);
                                    $rate     = (float) ($get('../../exchange_rate') ?? 1);

                                    $t = $compute($items, $discount, $shipping, $rate);
                                    $setNestedTotals($set, $t);
                                }),

                            Forms\Components\TextInput::make('line_total')
                                ->label(__('Total'))
                                ->numeric()
                                ->disabled()
                                ->columnSpan(2),
                        ])
                        ->afterStateUpdated(function (?array $state, Forms\Set $set, Forms\Get $get)
                                use ($compute, $setNestedTotals) {
                            $items    = (array) ($get('../../items') ?? $state ?? []);
                            $discount = (float) ($get('../../discount') ?? 0);
                            $shipping = (float) ($get('../../shipping') ?? 0);
                            $rate     = (float) ($get('../../exchange_rate') ?? 1);

                            $t = $compute($items, $discount, $shipping, $rate);
                            $setNestedTotals($set, $t);
                        }),
                ]),

            Forms\Components\Section::make(__('Totals & Currency'))
                ->schema([
                    Forms\Components\TextInput::make('subtotal')
                        ->label(__('Subtotal (USD)'))
                        ->numeric()->disabled(),

                    Forms\Components\TextInput::make('discount')
                        ->label(__('Discount (USD)'))
                        ->numeric()->default(0)
                        ->reactive()
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set)
                                use ($compute, $setRootTotals) {
                            $items    = (array) ($get('items') ?? []);
                            $discount = (float) $state;
                            $shipping = (float) ($get('shipping') ?? 0);
                            $rate     = (float) ($get('exchange_rate') ?? 1);

                            $t = $compute($items, $discount, $shipping, $rate);
                            $setRootTotals($set, $t);
                        }),

                    Forms\Components\TextInput::make('shipping')
                        ->label(__('Shipping (USD)'))
                        ->numeric()->default(0)
                        ->reactive()
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set)
                                use ($compute, $setRootTotals) {
                            $items    = (array) ($get('items') ?? []);
                            $discount = (float) ($get('discount') ?? 0);
                            $shipping = (float) $state;
                            $rate     = (float) ($get('exchange_rate') ?? 1);

                            $t = $compute($items, $discount, $shipping, $rate);
                            $setRootTotals($set, $t);
                        }),

                    Forms\Components\TextInput::make('total')
                        ->label(__('Total (USD)'))
                        ->numeric()->disabled(),

                    Forms\Components\Select::make('currency')
                        ->label(__('Currency'))
                        ->options(function () {
                            $opts = CurrencyRate::options();
                            return ['USD' => 'US Dollar'] + $opts;
                        })
                        ->default('USD')
                        ->reactive()
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set)
                                use ($compute, $setRootTotals) {
                            $rate = CurrencyRate::getRate($state ?: 'USD');
                            $set('exchange_rate', $rate);

                            $items    = (array) ($get('items') ?? []);
                            $discount = (float) ($get('discount') ?? 0);
                            $shipping = (float) ($get('shipping') ?? 0);

                            $t = $compute($items, $discount, $shipping, $rate);
                            $setRootTotals($set, $t);
                        })
                        ->helperText(__('Product prices are stored in USD. Converted totals are for display only.')),

                    Forms\Components\Placeholder::make('exchange_rate_info')
                        ->label(__('Exchange rate'))
                        ->content(fn (Forms\Get $get) => '1 USD = ' .
                            number_format((float)($get('exchange_rate') ?? 1), 6) . ' ' .
                            ($get('currency') ?: 'USD')
                        ),

                    Forms\Components\Placeholder::make('display_subtotal_converted')
                        ->label(fn (Forms\Get $get) => __('Subtotal (:cur)', ['cur' => $get('currency') ?: 'USD']))
                        ->content(fn (Forms\Get $get) => number_format((float)($get('display_subtotal_converted') ?? 0), 2)),

                    Forms\Components\Placeholder::make('display_total_converted')
                        ->label(fn (Forms\Get $get) => __('Total (:cur)', ['cur' => $get('currency') ?: 'USD']))
                        ->content(fn (Forms\Get $get) => number_format((float)($get('display_total_converted') ?? 0), 2)),
                ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading(__('No :resource', ['resource' => __('Orders')]))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('branch.code')->label(__('Branch'))->badge()->sortable(),
                Tables\Columns\TextColumn::make('customer.name')->label(__('Customer'))->searchable(),
                Tables\Columns\TextColumn::make('seller.name')->label(__('Seller')),
                Tables\Columns\TextColumn::make('type')->label(__('Type'))->badge()
                    ->color(fn ($state) => $state === 'order' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('status')->label(__('Status'))->badge(),

                Tables\Columns\TextColumn::make('shipping')
                    ->label(__('Shipping (USD)'))
                    ->money('usd')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total')
                    ->label(__('Total (USD)'))
                    ->money('usd')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_converted_display')
                    ->label(__('Total (Currency)'))
                    ->state(fn (Order $record) => number_format((float)$record->total * (float)$record->exchange_rate, 2))
                    ->suffix(fn (Order $record) => ' ' . $record->currency)
                    ->sortable(),

                Tables\Columns\TextColumn::make('exchange_rate')
                    ->label(__('Rate (1 USD = X)'))
                    ->formatStateUsing(fn ($state) => number_format((float)$state, 6)),

                Tables\Columns\TextColumn::make('created_at')->label(__('Created at'))->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')->label(__('Branch'))
                    ->options(fn () => Branch::orderBy('name')->pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('type')->label(__('Type'))->options([
                    'proforma' => __('Proforma'),
                    'order'    => __('Order'),
                ]),
                Tables\Filters\SelectFilter::make('status')->label(__('Status'))->options([
                    'draft'     => __('Draft'),
                    'confirmed' => __('Confirmed'),
                    'paid'      => __('Paid'),
                    'cancelled' => __('Cancelled'),
                ]),
                Tables\Filters\SelectFilter::make('currency')->label(__('Currency'))
                    ->options(fn () => ['USD' => 'USD'] + CurrencyRate::query()->orderBy('code')->pluck('code', 'code')->toArray()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label(__('View')),
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
            'view'   => Pages\ViewOrder::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        if ($user?->hasRole('seller') && $user->branch_id) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query->with(['customer','seller','branch']);
    }
}
