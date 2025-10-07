<?php

namespace App\Filament\Resources\SellerResource\Pages;

use App\Filament\Resources\SellerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSeller extends CreateRecord
{
    protected static string $resource = SellerResource::class;

    // password is already hashed in the form; just ensure role is set
    protected function afterCreate(): void
    {
        $this->record->assignRole('seller'); // requires Spatie role 'seller' to exist
    }
}
