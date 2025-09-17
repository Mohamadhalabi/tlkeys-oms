<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;
    protected static ?string $navigationIcon = 'heroicon-o-users';

    public static function getModelLabel(): string { return __('Customer'); }
    public static function getPluralModelLabel(): string { return __('Customers'); }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
            Forms\Components\TextInput::make('email')->label(__('Email'))->email()->maxLength(255),
            Forms\Components\TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(255),
            Forms\Components\Textarea::make('address')->label(__('Address'))->rows(3)->columnSpanFull(),
            Forms\Components\TextInput::make('wallet_balance')->label(__('Wallet balance'))->required()->numeric()->default(0.00),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading(__('No :resource', ['resource' => __('Customers')]))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('Name'))->searchable(),
                Tables\Columns\TextColumn::make('email')->label(__('Email'))->searchable(),
                Tables\Columns\TextColumn::make('phone')->label(__('Phone'))->searchable(),
                Tables\Columns\TextColumn::make('wallet_balance')->label(__('Wallet balance'))->numeric()->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label(__('Created at'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')->label(__('Updated at'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([Tables\Actions\EditAction::make()->label(__('Edit'))])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([
                Tables\Actions\DeleteBulkAction::make()->label(__('Delete selected')),
            ])]);
    }

    public static function getRelations(): array { return []; }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit'   => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool { return false; }
}
