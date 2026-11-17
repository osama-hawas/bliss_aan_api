<?php

namespace App\Http\Requests\Api\V1\Provider\Team;

use App\Enums\TeamMemberStatus;
use App\Rules\SaudiMobile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partial update. `phone` / `photo_media_id` null clear them; `status` disables or enables (nothing is deleted).
 */
class UpdateTeamMemberRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:100'],
            'title' => ['sometimes', 'required', 'string', 'min:1', 'max:60'],
            'phone' => ['sometimes', 'nullable', new SaudiMobile],
            'photo_media_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'required', Rule::enum(TeamMemberStatus::class)],
        ];
    }

    /**
     * `title` is the role label here, not an offer title.
     */
    public function attributes(): array
    {
        return ['title' => __('validation.attributes.team_title'), 'name' => __('validation.attributes.team_name')];
    }
}
