<?php

namespace App\Http\Requests\Api\V1\Provider\Team;

use App\Rules\SaudiMobile;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new member (D230): name, role label (free text 1–60, D236), optional Saudi mobile, optional photo (`team_photo`).
 */
class StoreTeamMemberRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'title' => ['required', 'string', 'min:1', 'max:60'],
            'phone' => ['nullable', new SaudiMobile],
            'photo_media_id' => ['nullable', 'integer'],
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
