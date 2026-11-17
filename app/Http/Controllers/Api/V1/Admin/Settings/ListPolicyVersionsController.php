<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Enums\PolicyType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Settings\AdminPolicyVersionResource;
use App\Models\AuditLog;
use App\Models\PolicyVersion;
use App\Services\Settings\PolicyPublisher;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `settings.manage`: every policy version, newest first per type, with the one in effect marked (D310).
 */
class ListPolicyVersionsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PolicyPublisher $policies) {}

    public function __invoke(Request $request)
    {
        $request->validate(['filter.type' => ['nullable', Rule::enum(PolicyType::class)]]);
        $type = $request->filled('filter.type') ? PolicyType::from($request->input('filter.type')) : null;

        $versions = $this->policies->list($type);
        $current = array_filter(array_map(fn (PolicyType $t) => $this->policies->currentId($t), PolicyType::cases()));
        $publishers = AuditLog::query()
            ->where('action', 'policy_published')
            ->where('subject_type', (new PolicyVersion)->getMorphClass())
            ->whereIn('subject_id', $versions->pluck('id'))
            ->with('actor:id,name')
            ->get()
            ->mapWithKeys(fn (AuditLog $log) => [$log->subject_id => $log->actor?->name]);

        $versions->each(function (PolicyVersion $version) use ($current, $publishers) {
            $version->setAttribute('is_current', in_array($version->id, $current, true));
            $version->setAttribute('published_by', $publishers->get($version->id));
        });

        return $this->success(AdminPolicyVersionResource::collection($versions));
    }
}
