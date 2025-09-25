<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = \App\Filament\Resources\ProductResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (blank($data['image'] ?? null)) {
            unset($data['image']);  // ← keep current image value
        }

        return $data;
    }
}
