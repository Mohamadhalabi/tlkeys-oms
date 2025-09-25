<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CurrencyRateResource\Pages;
use App\Models\CurrencyRate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Resources\Resource;

class CurrencyRateResource extends Resource
{
    protected static ?string $model = CurrencyRate::class;

    protected static ?string $navigationIcon  = 'heroicon-o-currency-dollar';
    protected static ?string $navigationGroup = 'Settings';
    protected static ?string $navigationLabel = 'Currencies';
    protected static ?string $modelLabel      = 'Currency';
    protected static ?string $pluralModelLabel= 'Currencies';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('code')
                ->label(__('Code'))
                ->required()
                ->maxLength(3)
                ->unique(ignoreRecord: true)
                ->disabled(fn (string $operation) => $operation === 'edit')
                ->dehydrateStateUsing(fn ($state) => strtoupper(trim((string) $state))),

            Forms\Components\TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(100),

            Forms\Components\TextInput::make('usd_to_currency')
                ->label(__('Exchange rate (1 USD = ?)'))
                ->numeric()
                ->required()
                ->step('0.000001')
                ->rule('gte:0.000001'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label(__('Code'))
                    ->sortable()
                    ->searchable()
                    ->formatStateUsing(fn ($state) => strtoupper((string) $state)),

                Tables\Columns\TextColumn::make('name')
                    ->label(__('Name'))
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('usd_to_currency')
                    ->label(__('Rate (1 USD = ?)'))
                    ->sortable()
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 6)),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('Updated at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCurrencyRates::route('/'),
            'create' => Pages\CreateCurrencyRate::route('/create'),
            'edit'   => Pages\EditCurrencyRate::route('/{record}/edit'),
        ];
    }
}
