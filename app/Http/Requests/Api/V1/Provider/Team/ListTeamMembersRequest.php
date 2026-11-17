<?php

namespace App\Http\Requests\Api\V1\Provider\Team;

use App\Enums\TeamMemberStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `?status=active|disabled` (none = both), `?page`, `?per_page` (≤ 50).
 */
class ListTeamMembersRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(TeamMemberStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function status(): ?TeamMemberStatus
    {
        return TeamMemberStatus::tryFrom((string) $this->validated('status'));
    }
}
