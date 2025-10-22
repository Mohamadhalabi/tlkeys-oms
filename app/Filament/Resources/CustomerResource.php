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
    protected static ?string $navigationGroup = 'Customers';

    public static function getModelLabel(): string { return __('Customer'); }
    public static function getPluralModelLabel(): string { return __('Customers'); }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('Name'))
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('email')
                        ->label(__('Email'))
                        ->email()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('phone')
                        ->label(__('Phone'))
                        ->tel()
                        ->maxLength(255),

                    Forms\Components\Textarea::make('address')
                        ->label(__('Address'))
                        ->rows(3)
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('wallet_balance')
                        ->label(__('Wallet balance'))
                        ->required()
                        ->numeric()
                        ->default(0.00),

                    // Admin can choose the seller
                    // App/Filament/Resources/CustomerResource.php (form())
                    Forms\Components\Select::make('seller_id')
                        ->label(__('Seller'))
                        ->relationship('seller', 'name')
                        ->preload()
                        ->searchable()
                        ->hidden(fn () => auth()->user()?->hasRole('seller') ?? false) // sellers don’t see it
                        ->required(fn () => auth()->user()?->hasRole('admin') ?? false),


                    // Seller auto-assigns self
                    Forms\Components\Hidden::make('seller_id')
                        ->default(fn () => auth()->id())
                        ->visible(fn () => auth()->user()?->hasRole('seller') ?? false),
                ])
                ->columns(2),
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

                Tables\Columns\TextColumn::make('seller.name')
                    ->label(__('Seller'))
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')->label(__('Created at'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')->label(__('Updated at'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([Tables\Actions\EditAction::make()->label(__('Edit'))])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label(__('Delete selected')),
                ])
                // Optional: hide destructive bulk actions for sellers
                ->visible(fn () => auth()->user()?->hasRole('admin') ?? false),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\CustomerResource\RelationManagers\OrdersRelationManager::class,
            \App\Filament\Resources\CustomerResource\RelationManagers\WalletTransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit'   => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }

    // Show in both panels
    public static function shouldRegisterNavigation(): bool
    {
        $u = auth()->user();
        return $u?->hasRole('admin') || $u?->hasRole('seller');
    }

    // Sellers only see their customers
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $q = parent::getEloquentQuery();
        $u = auth()->user();
        if ($u?->hasRole('seller')) {
            $q->where('seller_id', $u->id);
        }
        return $q;
    }
}
