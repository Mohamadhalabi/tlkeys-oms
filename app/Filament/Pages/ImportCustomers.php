<?php

namespace App\Filament\Pages;

use App\Jobs\ImportCustomersJob;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Cache;

class ImportCustomers extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cloud-arrow-down';
    protected static bool $shouldRegisterNavigation = false;
    protected static string $view = 'filament.pages.import-customers';

    // progress cache key
    protected string $progressKey = 'import_customers_progress';

    /** Page <title> (must be public) */
    public function getTitle(): string|Htmlable
    {
        return __('Import customers');
    }

    /** H1 heading (also public) */
    public function getHeading(): string|Htmlable
    {
        return __('Import customers');
    }

    protected function getFormSchema(): array
    {
        return [];
    }

    public function import(): void
    {
        Cache::forget($this->progressKey);

        ImportCustomersJob::dispatch($this->progressKey);

        Notification::make()
            ->title(__('Import started'))
            ->body(__('The import is running in the background. You can continue using the app.'))
            ->success()
            ->send();
    }

    /** Polled by the Livewire view to show progress */
    public function getProgress(): array
    {
        return Cache::get($this->progressKey, ['status' => 'idle']);
    }
}
