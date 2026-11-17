<?php

namespace App\Http\Resources\Api\V1\Admin\Settings;

use App\Http\Resources\Concerns\FormatsApiValues;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of SettingsEditor::list() (D307). Label/description come from lang {ar,en} content.php settings.keys.
 */
class AdminAppSettingResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $row = $this->resource;
        $labelKey = 'content.settings.keys.'.str_replace('.', '__', $row['key']);
        $label = __($labelKey);

        return [
            'key' => $row['key'],
            'group' => $row['group'],
            'label' => $label === $labelKey ? $row['key'] : $label,
            'kind' => $row['kind']->value,
            'kind_label' => $row['kind']->label(),
            'unit' => $row['unit'],
            'decision' => $row['decision'],
            'fixed' => $row['fixed'],
            'nullable' => $row['nullable'], // false: can never go back to "not configured" (review H1)
            'min' => $row['min'],
            'value' => $row['value'],
            'is_configured' => $row['is_configured'],
            'updated_at' => $this->dateTime($row['updated_at']),
            'updated_by' => $row['updated_by'],
            'last_reason' => $row['last_reason'],
        ];
    }
}
