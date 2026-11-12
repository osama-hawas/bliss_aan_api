<?php

namespace App\Http\Requests\Concerns;

/**
 * `filter[status]` as one value or a comma list, turned into a list before validation.
 */
trait ListsServiceRequests
{
    protected function prepareForValidation(): void
    {
        $status = $this->input('filter.status');
        if (is_string($status)) {
            $filter = (array) $this->input('filter', []);
            $filter['status'] = array_values(array_filter(array_map('trim', explode(',', $status)), fn ($s) => $s !== ''));
            $this->merge(['filter' => $filter]);
        }
    }

    /**
     * @return list<string>
     */
    protected function statuses(): array
    {
        return array_values($this->validated('filter.status') ?? []);
    }
}
