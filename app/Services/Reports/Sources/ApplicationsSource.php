<?php

namespace App\Services\Reports\Sources;

use App\Enums\ProviderStatus;

/** Join requests (provider applications, D38) per review status (`providers.review`). */
class ApplicationsSource extends ProvidersSource
{
    public function key(): string
    {
        return 'applications';
    }

    public function permissions(): array
    {
        return ['providers.review'];
    }

    protected function statuses(): array
    {
        return [ProviderStatus::Submitted->value, ProviderStatus::NeedsCompletion->value, ProviderStatus::Active->value];
    }

    protected function listPath(): string
    {
        return '/provider-applications';
    }

    protected function detailHref(object $row): string
    {
        return "/provider-applications/{$row->id}";
    }
}
