<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions;
use Filament\Forms;
use App\Models\Branch;
use App\Jobs\SyncProductsFromApi;
use App\Imports\ProductsImport;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Filament\Notifications\Notification;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        if (!auth()->user()?->hasRole('admin')) {
            return [];
        }

        // Construct the default URL: https://dev-srv.tlkeys.com/api/export-products
        $defaultApiUrl = rtrim(config('tlkeys.crm.base_url'), '/') . '/export-products';

        return [
            Actions\CreateAction::make(),

            Actions\ActionGroup::make([
                
                // --- OPTION 1: IMPORT EXCEL ---
                Actions\Action::make('importExcel')
                    ->label(__('Import Excel'))
                    ->icon('heroicon-m-document-arrow-up')
                    ->form([
                        Forms\Components\Select::make('branch_id')
                            ->label(__('Branch'))
                            ->options(Branch::query()->pluck('name', 'id'))
                            ->searchable()
                            ->required(),

                        Forms\Components\FileUpload::make('file')
                            ->label(__('Excel File'))
                            ->disk('local')
                            ->directory('imports')
                            ->preserveFilenames()
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $path = Storage::disk('local')->path($data['file']);
                        Excel::import(new ProductsImport((int)$data['branch_id']), $path);
                        Notification::make()->title('Excel Import Started')->success()->send();
                    }),

                // --- OPTION 2: SYNC API ---
                Actions\Action::make('syncApi')
                    ->label(__('Sync via API'))
                    ->icon('heroicon-m-cloud-arrow-down')
                    ->form([
                        Forms\Components\Select::make('branch_id')
                            ->label(__('Target Branch'))
                            ->options(Branch::query()->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                            
                        Forms\Components\TextInput::make('api_url')
                            ->label('API Endpoint')
                            ->default($defaultApiUrl) // Uses your config
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        
                        SyncProductsFromApi::dispatch(
                            (int) $data['branch_id'],
                            $data['api_url']
                        );

                        Notification::make()
                            ->title('API Sync Started')
                            ->body('Background process started.')
                            ->success()
                            ->send();
                    }),
            ])
            ->label(__('Import / Sync'))
            ->icon('heroicon-m-arrow-path')
            ->color('info')
            ->button(),
        ];
    }
}