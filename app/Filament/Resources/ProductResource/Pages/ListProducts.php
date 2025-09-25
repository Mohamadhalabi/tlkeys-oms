<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions;
use Filament\Forms;
use App\Models\Branch;
use App\Imports\ProductsImport;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Filament\Notifications\Notification;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),

            Actions\Action::make('importProducts')
                ->label(__('Import products'))
                ->icon('heroicon-m-arrow-up-tray')
                ->color('warning')
                ->form([
                    Forms\Components\Select::make('branch_id')
                        ->label(__('Branch for stock'))
                        ->options(Branch::query()->pluck('name','id'))
                        ->searchable()
                        ->required(),

                    Forms\Components\FileUpload::make('file')
                        ->label(__('Excel/CSV file'))
                        ->disk('local')                 // or 'public'
                        ->directory('imports')
                        ->preserveFilenames()
                        ->acceptedFileTypes([
                            'text/csv',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ])
                        ->required(),
                ])
                ->action(function (array $data) {
                    $path = Storage::disk('local')->path($data['file']); // e.g. storage/app/imports/...
                    Excel::import(new ProductsImport((int)$data['branch_id']), $path);

                    Notification::make()
                        ->title('Import queued')
                        ->success()
                        ->send();
                }),
        ];
    }
}
