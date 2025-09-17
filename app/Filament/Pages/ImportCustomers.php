<?php

namespace App\Filament\Pages;

use App\Jobs\ImportCustomersJob;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

class ImportCustomers extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cloud-arrow-down';
    protected static ?string $title = 'Import Customers';
    protected static bool $shouldRegisterNavigation = false;
    protected static string $view = 'filament.pages.import-customers';

    // Optional: a fixed cache key for progress (could also be per-user)
    protected string $progressKey = 'import_customers_progress';

    protected function getFormSchema(): array
    {
        return [];
    }

    public function import(): void
    {
        // clear last run’s progress
        Cache::forget($this->progressKey);

        // queue the import (returns immediately)
        ImportCustomersJob::dispatch($this->progressKey);

        Notification::make()
            ->title('Import started')
            ->body('The import is running in the background. You can continue using the app.')
            ->success()
            ->send();
    }

    // You can add a small endpoint-like method to poll progress from the page via Livewire
    public function getProgress(): array
    {
        return Cache::get($this->progressKey, ['status' => 'idle']);
    }
}
