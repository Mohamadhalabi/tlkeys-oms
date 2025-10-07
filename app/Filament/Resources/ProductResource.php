<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = 'Catalog';

    public static function getNavigationLabel(): string { return __('Products'); }
    public static function getModelLabel(): string { return __('product'); }
    public static function getPluralModelLabel(): string { return __('Products'); }

    // Show in menu for admins & sellers
    public static function shouldRegisterNavigation(): bool
    {
        $u = auth()->user();
        return $u?->hasRole('admin') || $u?->hasRole('seller');
    }

    // Lock down CRUD for sellers (view-only in this resource)
    public static function canCreate(): bool   { return auth()->user()?->hasRole('admin') ?? false; }
    public static function canEdit($r): bool   { return auth()->user()?->hasRole('admin') ?? false; }
    public static function canDelete($r): bool { return auth()->user()?->hasRole('admin') ?? false; }

    // locales you want to edit
    protected static array $locales = ['en' => 'English', 'ar' => 'العربية'];

    public static function form(Form $form): Form
    {
        // Build language tabs
        $localeTabs = [];
        foreach (self::$locales as $code => $label) {
            $localeTabs[] = Tab::make($label)->schema([
                TextInput::make("title.$code")
                    ->label(__('Title'))
                    ->required($code === 'en')
                    ->maxLength(255),
            ]);
        }

        return $form->schema([
            TextInput::make('sku')
                ->label(__('SKU'))
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),

            Tabs::make('Translations')
                ->tabs($localeTabs)
                ->columnSpanFull(),

            Forms\Components\Grid::make(3)->schema([
                TextInput::make('price')
                    ->label(__('Price'))
                    ->numeric()
                    ->required()
                    ->default(0.00)
                    ->prefix('$'),

                TextInput::make('sale_price')
                    ->label(__('Sale price'))
                    ->numeric()
                    ->prefix('$'),

                // Cost price (admins always see; sellers only if allowed)
                TextInput::make('cost_price')
                    ->label(__('Cost price'))
                    ->numeric()
                    ->minValue(0)
                    ->step('0.01')
                    ->prefix('$')
                    ->visible(fn () => auth()->user()?->hasRole('admin') || (auth()->user()?->can_see_cost ?? false))
                    ->helperText(__('Internal cost in USD; used for margins and order pricing.')),
            ]),

            TextInput::make('weight')->label(__('Weight'))->numeric()->suffix('kg'),

            FileUpload::make('image')
                ->label(__('Image'))
                ->image()
                ->directory('products')
                ->visibility('public')
                ->imageEditor()
                ->nullable()
                ->dehydrated(fn ($state) => filled($state)),

            Repeater::make('inventories')
                ->label(__('Inventories (per branch)'))
                ->relationship('inventories')
                ->schema([
                    Select::make('branch_id')
                        ->label(__('Branch'))
                        ->relationship('branch', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->disabledOn('edit'),

                    TextInput::make('stock')->label(__('Stock'))->numeric()->required()->default(0),
                    TextInput::make('stock_alert')->label(__('Alert at'))->numeric()->default(0),
                ])
                ->columns(3)
                ->collapsible()
                ->reorderable(false)
                ->grid(1)
                ->addActionLabel(__('Add branch stock')),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        $user = auth()->user();

        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')->label(__('Image'))->square(),
                Tables\Columns\TextColumn::make('sku')->label(__('SKU'))->searchable()->sortable(),

                // current locale title from JSON
                Tables\Columns\TextColumn::make('title')
                    ->label(__('Title'))
                    ->state(fn (Product $r) => $r->getTranslation('title', app()->getLocale() ?: 'en'))
                    ->searchable(query: function (Builder $q, string $search): Builder {
                        return $q->whereRaw("JSON_SEARCH(JSON_EXTRACT(`title`, '$'), 'one', ?) IS NOT NULL", [$search]);
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('price')->label(__('Price'))->money('usd')->sortable(),
                Tables\Columns\TextColumn::make('sale_price')->label(__('Sale price'))->money('usd')->sortable(),

                // Cost visible only to admins or sellers who may see cost
                Tables\Columns\TextColumn::make('cost_price')
                    ->label(__('Cost price'))
                    ->money('usd')
                    ->sortable()
                    ->visible(fn () => $user?->hasRole('admin') || ($user?->can_see_cost ?? false)),

                Tables\Columns\TextColumn::make('weight')->label(__('Weight'))->numeric()->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                // Only admins can edit
                Tables\Actions\EditAction::make()
                    ->visible(fn () => auth()->user()?->hasRole('admin') ?? false),
            ])
            ->bulkActions([
                // Only admins get bulk delete
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->hasRole('admin') ?? false),
                ]),
            ]);
    }

    public static function getRelations(): array { return []; }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit'   => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
