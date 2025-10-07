<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SellerResource\Pages;
use App\Models\Branch;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SellerResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon  = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'Administration';
    protected static ?string $navigationLabel = 'Sellers';

    // only show to admins
    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }
    public static function canViewAny(): bool  { return auth()->user()?->hasRole('admin') ?? false; }
    public static function canCreate(): bool   { return auth()->user()?->hasRole('admin') ?? false; }
    public static function canEdit($r): bool   { return auth()->user()?->hasRole('admin') ?? false; }
    public static function canDelete($r): bool { return auth()->user()?->hasRole('admin') ?? false; }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Account')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),

                    Forms\Components\TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? \Illuminate\Support\Facades\Hash::make($state) : null)
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn ($state) => filled($state))
                        ->minLength(8),
                ])->columns(2),

            Forms\Components\Section::make('Branch & Permissions')
                ->schema([
                    Forms\Components\Select::make('branch_id')
                        ->label('Branch')
                        ->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->preload()
                        ->required(),

                    Forms\Components\Toggle::make('is_active')->label('Active')->default(true),
                    Forms\Components\Toggle::make('can_see_cost')->label('Can see cost'),
                    Forms\Components\Toggle::make('can_sell_below_cost')->label('Can sell below cost'),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->searchable(),
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean(),
                Tables\Columns\IconColumn::make('can_see_cost')->label('See cost')->boolean(),
                Tables\Columns\IconColumn::make('can_sell_below_cost')->label('Sell < cost')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (User $r) => auth()->id() !== $r->id),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    // Only list users who are sellers
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->role('seller');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSellers::route('/'),
            'create' => Pages\CreateSeller::route('/create'),
            'edit'   => Pages\EditSeller::route('/{record}/edit'),
        ];
    }
}
