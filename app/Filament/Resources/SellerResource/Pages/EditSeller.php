<?php

namespace App\Filament\Resources\SellerResource\Pages;

use App\Filament\Resources\SellerResource;
use Filament\Resources\Pages\EditRecord;

class EditSeller extends EditRecord
{
    protected static string $resource = SellerResource::class;

    protected function afterSave(): void
    {
        // keep the seller role attached (harmless if already there)
        if (!$this->record->hasRole('seller')) {
            $this->record->assignRole('seller');
        }
    }
}
