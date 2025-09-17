<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BranchResource\Pages;
use App\Models\Branch;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    // Optional: group in the sidebar (translate the group too if you use it)
    // protected static ?string $navigationGroup = 'Settings';

    // Labels shown in the sidebar, headers, empty states, etc.
    public static function getNavigationLabel(): string     { return __('Branches'); }
    public static function getModelLabel(): string          { return __('branch'); }
    public static function getPluralModelLabel(): string    { return __('Branches'); }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('code')
                ->label(__('Code'))
                ->helperText(__('Two-letter code, e.g. SA, AE'))
                ->required()
                ->maxLength(2)
                ->unique(ignoreRecord: true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name')
                ->label(__('Name'))
                ->searchable()
                ->sortable(),

            Tables\Columns\TextColumn::make('code')
                ->label(__('Code'))
                ->badge()
                ->searchable()
                ->sortable(),

            Tables\Columns\TextColumn::make('created_at')
                ->label(__('Created at'))
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),

            Tables\Columns\TextColumn::make('updated_at')
                ->label(__('Updated at'))
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ])
        ->actions([
            Tables\Actions\EditAction::make()->label(__('Edit')),
        ])
        ->bulkActions([
            Tables\Actions\BulkActionGroup::make([
                Tables\Actions\DeleteBulkAction::make()->label(__('Delete selected')),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListBranches::route('/'),
            'create' => Pages\CreateBranch::route('/create'),
            'edit'   => Pages\EditBranch::route('/{record}/edit'),
        ];
    }
}
