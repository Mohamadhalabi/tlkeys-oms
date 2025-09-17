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

    public static function getNavigationLabel(): string { return __('Products'); }
    public static function getModelLabel(): string { return __('product'); }
    public static function getPluralModelLabel(): string { return __('Products'); }

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

            TextInput::make('price')->label(__('Price'))->numeric()->required()->default(0.00)->prefix('$'),
            TextInput::make('sale_price')->label(__('Sale price'))->numeric()->prefix('$'),
            TextInput::make('weight')->label(__('Weight'))->numeric()->suffix('kg'),

            FileUpload::make('image')
                ->label(__('Image'))
                ->image()
                ->directory('products')
                ->visibility('public')
                ->imageEditor(),

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
        return $table->columns([
            Tables\Columns\ImageColumn::make('image')->label(__('Image'))->square(),
            Tables\Columns\TextColumn::make('sku')->label(__('SKU'))->searchable()->sortable(),

            // Show the current locale's title from JSON
            Tables\Columns\TextColumn::make('title')
                ->label(__('Title'))
                ->state(fn (Product $r) => $r->getTranslation('title', app()->getLocale() ?: 'en'))
                ->searchable(query: function (Builder $q, string $search): Builder {
                    return $q->whereRaw("JSON_SEARCH(JSON_EXTRACT(`title`, '$'), 'one', ?) IS NOT NULL", [$search]);
                })
                ->sortable(),

            Tables\Columns\TextColumn::make('price')->label(__('Price'))->money('usd')->sortable(),
            Tables\Columns\TextColumn::make('sale_price')->label(__('Sale price'))->numeric()->sortable(),
            Tables\Columns\TextColumn::make('weight')->label(__('Weight'))->numeric()->sortable(),
            Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
        ])
        ->actions([Tables\Actions\EditAction::make()])
        ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
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
