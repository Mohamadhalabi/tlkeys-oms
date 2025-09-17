<?php

namespace App\Filament\Resources\BranchResource\Pages;

use App\Filament\Resources\BranchResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBranches extends ListRecords
{
    protected static string $resource = BranchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label(__('Add :label', ['label' => __('branch')])),
        ];
    }

    public function getTitle(): string
    {
        return __('Branches');
    }
}
