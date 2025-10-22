<?php
// App/Filament/Resources/CustomerResource/Pages/CreateCustomer.php
namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        if ($user?->hasRole('seller')) {
            $data['seller_id'] = $user->id; // force ownership
        }
        return $data;
    }
}
