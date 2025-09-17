<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;

use Illuminate\Database\Eloquent\Builder;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationLabel = 'Products';

    // pick your locales once here
    protected static array $locales = ['en' => 'English', 'ar' => 'العربية'];

    public static function form(Form $form): Form
    {
        $localeTabs = [];
        foreach (self::$locales as $code => $label) {
            $localeTabs[] = Tab::make($label)->schema([
                // Use state paths like title.en (Spatie will store as JSON)
                TextInput::make("title.$code")
                    ->label('Title')
                    ->required($code === 'en') // make one locale required if you want
                    ->maxLength(255),
            ]);
        }

        return $form->schema([
            TextInput::make('sku')
                ->label('SKU')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),

            // 🔹 Language tabs (pure Filament)
            Tabs::make('Translations')
                ->tabs($localeTabs)
                ->columnSpanFull(),

            TextInput::make('price')->numeric()->required()->default(0.00)->prefix('$'),
            TextInput::make('sale_price')->numeric()->prefix('$'),
            TextInput::make('weight')->numeric()->suffix('kg'),

            FileUpload::make('image')
                ->label('Image')
                ->image()
                ->directory('products')
                ->visibility('public')
                ->imageEditor(),

            // per-branch stock
            Repeater::make('inventories')
                ->label('Inventories (per branch)')
                ->relationship('inventories')
                ->schema([
                    Select::make('branch_id')
                        ->label('Branch')
                        ->relationship('branch', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->disabledOn('edit'),

                    TextInput::make('stock')->numeric()->required()->default(0),
                    TextInput::make('stock_alert')->label('Alert at')->numeric()->default(0),
                ])
                ->columns(3)
                ->collapsible()
                ->reorderable(false)
                ->grid(1)
                ->addActionLabel('Add branch stock'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\ImageColumn::make('image')->label('Image')->square(),
            Tables\Columns\TextColumn::make('sku')->label('SKU')->searchable()->sortable(),

            // read current locale from JSON
            Tables\Columns\TextColumn::make('title')
                ->label('Title')
                ->state(fn (Product $r) => $r->getTranslation('title', app()->getLocale() ?: 'en'))
                ->searchable(query: function (Builder $q, string $search): Builder {
                    return $q->whereRaw("JSON_SEARCH(JSON_EXTRACT(`title`, '$'), 'one', ?) IS NOT NULL", [$search]);
                })
                ->sortable(),

            Tables\Columns\TextColumn::make('price')->money('usd')->sortable(),
            Tables\Columns\TextColumn::make('sale_price')->numeric()->sortable(),
            Tables\Columns\TextColumn::make('weight')->numeric()->sortable(),
            Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
        ])
        ->actions([Tables\Actions\EditAction::make()])
        ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit'   => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
