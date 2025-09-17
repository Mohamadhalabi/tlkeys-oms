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

    public static function form(Form $form): Form
    {
        $user = Auth::user();

        return $form->schema([
            Forms\Components\Section::make('Order Info')
                ->schema([
                    Forms\Components\Select::make('branch_id')
                        ->label('Branch')
                        ->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
                        ->default(fn () => $user->hasRole('seller') ? $user->branch_id : null)
                        ->disabled(fn () => $user->hasRole('seller'))
                        ->required(),

                    Forms\Components\Select::make('customer_id')
                        ->label('Customer')
                        ->options(fn () => Customer::orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->createOptionForm([
                            Forms\Components\TextInput::make('name')->required(),
                            Forms\Components\TextInput::make('email')->email()->nullable(),
                            Forms\Components\TextInput::make('phone')->nullable(),
                            Forms\Components\Textarea::make('address')->rows(3)->nullable(),
                        ])
                        ->createOptionUsing(fn (array $data) => Customer::create($data)->id),

                    Forms\Components\Select::make('seller_id')
                        ->label('Seller')
                        ->options(fn () => User::role('seller')->orderBy('name')->pluck('name', 'id'))
                        ->default(fn () => $user->id)
                        ->disabled(fn () => $user->hasRole('seller'))
                        ->required(),

                    Forms\Components\Select::make('type')
                        ->label('Type')
                        ->options(['proforma' => 'Proforma', 'order' => 'Order'])
                        ->default('proforma')
                        ->required(),

                    Forms\Components\Select::make('status')
                        ->options([
                            'draft'     => 'Draft',
                            'confirmed' => 'Confirmed',
                            'paid'      => 'Paid',
                            'cancelled' => 'Cancelled',
                        ])
                        ->default('draft')
                        ->required(),
                ])->columns(4),

            Forms\Components\Section::make('Items')
                ->schema([
                    Forms\Components\Repeater::make('items')
                        ->relationship()
                        ->columns(5)
                        ->schema([
                            // ✅ Filter products by branch (chosen on the form or user's branch)
                            Forms\Components\Select::make('product_id')
                                ->label('Product')
                                ->options(function (Forms\Get $get) {
                                    $chosenBranch = $get('../../branch_id');
                                    $userBranch   = auth()->user()?->branch_id;
                                    $branchId     = $chosenBranch ?: $userBranch;

                                    $q = Product::query();
                                    if ($branchId) {
                                        $q->forBranch($branchId);
                                    }

                                    return $q->orderBy('sku')->pluck('sku', 'id');
                                })
                                ->searchable()
                                ->preload()
                                ->required()
                                ->reactive()
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                    $p = Product::find($state);
                                    $price = $p?->sale_price ?? $p?->price ?? 0;
                                    $qty = (int) ($get('qty') ?? 1);
                                    $set('unit_price', $price);
                                    $set('line_total', $qty * $price);
                                })
                                // Optional: show available stock in chosen branch as a hint
                                ->helperText(function (Forms\Get $get) {
                                    $productId   = $get('product_id');
                                    $chosenBranch = $get('../../branch_id');
                                    $branchId     = $chosenBranch ?: auth()->user()?->branch_id;

                                    if (!$productId || !$branchId) return null;

                                    /** @var \App\Models\Product $prod */
                                    $prod = Product::with(['stocks' => fn ($q) => $q->where('branch_id', $branchId)])->find($productId);
                                    $stock = optional($prod?->stocks?->first())->stock;

                                    return is_null($stock) ? null : "Available in branch: {$stock}";
                                }),

                            Forms\Components\TextInput::make('qty')
                                ->numeric()->default(1)->minValue(1)->required()
                                ->reactive()
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                    $set('line_total', (float)$state * (float)($get('unit_price') ?? 0));
                                }),

                            Forms\Components\TextInput::make('unit_price')
                                ->numeric()->step('0.01')->required()
                                ->reactive()
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                    $set('line_total', (float)$state * (float)($get('qty') ?? 1));
                                }),

                            Forms\Components\TextInput::make('line_total')
                                ->numeric()
                                ->disabled(),
                        ])
                        ->afterStateUpdated(function (?array $state, Forms\Set $set, Forms\Get $get) {
                            $subtotal = collect($get('items') ?? [])->sum('line_total');
                            $set('subtotal', $subtotal);
                            $set('total', $subtotal - (float)($get('discount') ?? 0));
                        }),
                ]),

            Forms\Components\Section::make('Totals')
                ->schema([
                    Forms\Components\TextInput::make('subtotal')->numeric()->disabled(),
                    Forms\Components\TextInput::make('discount')->numeric()->default(0)
                        ->reactive()
                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                            $set('total', (float)($get('subtotal') ?? 0) - (float)$state);
                        }),
                    Forms\Components\TextInput::make('total')->numeric()->disabled(),
                ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('id')->sortable(),
            Tables\Columns\TextColumn::make('branch.code')->label('Branch')->badge()->sortable(),
            Tables\Columns\TextColumn::make('customer.name')->label('Customer')->searchable(),
            Tables\Columns\TextColumn::make('seller.name')->label('Seller'),
            Tables\Columns\TextColumn::make('type')->badge()->color(fn ($state) => $state === 'order' ? 'success' : 'gray'),
            Tables\Columns\TextColumn::make('status')->badge(),
            Tables\Columns\TextColumn::make('total')->money('usd')->sortable(),
            Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
        ])
        ->filters([
            Tables\Filters\SelectFilter::make('branch_id')->label('Branch')
                ->options(fn () => Branch::orderBy('name')->pluck('name', 'id')),
            Tables\Filters\SelectFilter::make('type')->options(['proforma' => 'Proforma', 'order' => 'Order']),
            Tables\Filters\SelectFilter::make('status')->options([
                'draft' => 'Draft','confirmed' => 'Confirmed','paid' => 'Paid','cancelled' => 'Cancelled',
            ]),
        ])
        ->actions([
            Tables\Actions\ViewAction::make(),
            Tables\Actions\EditAction::make(),
        ])
        ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit'   => Pages\EditOrder::route('/{record}/edit'),
            'view'   => Pages\ViewOrder::route('/{record}'),
        ];
    }

    // Sellers see only their branch’s orders
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
