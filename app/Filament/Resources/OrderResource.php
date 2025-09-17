<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

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
        $user = Auth::user();

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

                    Forms\Components\Select::make('seller_id')
                        ->label(__('Seller'))
                        ->options(fn () => User::role('seller')->orderBy('name')->pluck('name', 'id'))
                        ->default(fn () => $user->id)
                        ->disabled(fn () => $user->hasRole('seller'))
                        ->required(),

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
                        ->label(__('Items'))                 // 🔹 label
                        ->addActionLabel(__('Add item'))      // 🔹 button text
                        ->relationship()
                        ->columns(5)
                        ->schema([
                            Forms\Components\Select::make('product_id')
                                ->label(__('Product'))
                                ->options(function (Forms\Get $get) {
                                    $chosenBranch = $get('../../branch_id');
                                    $userBranch   = auth()->user()?->branch_id;
                                    $branchId     = $chosenBranch ?: $userBranch;

                                    $q = Product::query();
                                    if ($branchId) $q->forBranch($branchId);

                                    return $q->orderBy('sku')->pluck('sku', 'id');
                                })
                                ->searchable()->preload()->required()->reactive()
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                    $p = Product::find($state);
                                    $price = $p?->sale_price ?? $p?->price ?? 0;
                                    $qty = (int) ($get('qty') ?? 1);
                                    $set('unit_price', $price);
                                    $set('line_total', $qty * $price);
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
                                ->numeric()->default(1)->minValue(1)->required()
                                ->reactive()
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                    $set('line_total', (float)$state * (float)($get('unit_price') ?? 0));
                                }),

                            Forms\Components\TextInput::make('unit_price')
                                ->label(__('Unit price'))
                                ->numeric()->step('0.01')->required()
                                ->reactive()
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                    $set('line_total', (float)$state * (float)($get('qty') ?? 1));
                                }),

                            Forms\Components\TextInput::make('line_total')
                                ->label(__('Line total'))
                                ->numeric()->disabled(),
                        ])
                        ->afterStateUpdated(function (?array $state, Forms\Set $set, Forms\Get $get) {
                            $subtotal = collect($get('items') ?? [])->sum('line_total');
                            $set('subtotal', $subtotal);
                            $set('total', $subtotal - (float)($get('discount') ?? 0));
                        }),
                ]),

            Forms\Components\Section::make(__('Totals'))
                ->schema([
                    Forms\Components\TextInput::make('subtotal')->label(__('Subtotal'))->numeric()->disabled(),
                    Forms\Components\TextInput::make('discount')->label(__('Discount'))->numeric()->default(0)
                        ->reactive()
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                            $set('total', (float)($get('subtotal') ?? 0) - (float)$state);
                        }),
                    Forms\Components\TextInput::make('total')->label(__('Total'))->numeric()->disabled(),
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
                Tables\Columns\TextColumn::make('total')->label(__('Total'))->money('usd')->sortable(),
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
