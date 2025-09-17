<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\Branch;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'Administration';
    protected static ?string $navigationLabel = 'Users';

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
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),

                    Forms\Components\TextInput::make('password')
                        ->label('Password')
                        ->password()
                        ->revealable()
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? Hash::make($state) : null)
                        ->required(fn (string $context): bool => $context === 'create')
                        ->dehydrated(fn ($state) => filled($state))
                        ->minLength(8),
                ])->columns(2),

            Forms\Components\Section::make('Access & Branch')
                ->schema([
                    // Choose branch by name (uses relation or options)
                    Forms\Components\Select::make('branch_id')
                        ->label('Branch')
                        ->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->nullable(),

                    // Assign role(s) with spatie/permission relationship
                    Forms\Components\Select::make('roles')
                        ->label('Roles')
                        ->relationship('roles', 'name') // requires HasRoles on User model
                        ->multiple()
                        ->preload()
                        ->required()
                        ->helperText('Select at least one role (admin or seller).'),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->searchable(),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->separator(', ')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(fn () => Branch::orderBy('name')->pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('roles')
                    ->label('Role')
                    ->relationship('roles', 'name'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (User $record) => auth()->id() !== $record->id), // small safety
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            // You can add relation managers later (e.g., Orders created by this seller)
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    /**
     * Optional: limit what sellers can see in the Users list (admins see all).
     * For now we let only admins access this resource via Filament auth/policies.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }
    
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

}
