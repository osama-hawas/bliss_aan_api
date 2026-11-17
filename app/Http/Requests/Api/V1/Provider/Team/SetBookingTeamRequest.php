<?php

namespace App\Http\Requests\Api\V1\Provider\Team;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `member_ids`: the whole team of the booking (may be empty), distinct, at most 50 (D234 technical cap).
 */
class SetBookingTeamRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'member_ids' => ['present', 'array', 'max:50'],
            'member_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return list<int>
     */
    public function memberIds(): array
    {
        return array_values(array_map('intval', $this->validated('member_ids') ?? []));
    }
}
