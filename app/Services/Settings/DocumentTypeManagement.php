<?php

namespace App\Services\Settings;

use App\Enums\ErrorCode;
use App\Enums\ProviderStatus;
use App\Exceptions\ApiException;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Content\ContentVisibility;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Provider application document types (Slice 20, D309). Changes apply to the next upload / submit only.
 */
class DocumentTypeManagement
{
    private const FIELDS = ['name', 'applies_to', 'is_required', 'allowed_mimes', 'max_size_kb', 'sort'];

    public const PENDING_STATUSES = [ProviderStatus::Draft, ProviderStatus::Submitted, ProviderStatus::NeedsCompletion];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return Collection<int, DocumentType>
     */
    public function list(): Collection
    {
        return DocumentType::query()
            ->select('document_types.*')
            ->selectSub(DB::table('provider_documents')->selectRaw('count(*)')->whereColumn('document_type_id', 'document_types.id'), 'documents_count')
            ->orderBy('sort')->orderBy('id')
            ->get();
    }

    public function find(int $id): DocumentType
    {
        return $this->list()->firstWhere('id', $id) ?? throw new ApiException(ErrorCode::NotFound);
    }

    /**
     * @return array{figures: list<array{kind: string, count: int}>}
     */
    public function impact(DocumentType $type): array
    {
        $pending = array_map(fn (ProviderStatus $s) => $s->value, self::PENDING_STATUSES);

        return ['figures' => [
            ['kind' => 'documents', 'count' => DB::table('provider_documents')->where('document_type_id', $type->id)->count()],
            ['kind' => 'applications', 'count' => DB::table('providers')->whereIn('status', $pending)->count()],
        ]];
    }

    /**
     * @param  array{name: array{ar: string, en: string}, applies_to: mixed, is_required: bool, allowed_mimes: ?array, max_size_kb: ?int, sort: ?int}  $data
     */
    public function create(User $admin, array $data): DocumentType
    {
        return DB::transaction(function () use ($admin, $data) {
            $data['sort'] ??= (int) DocumentType::query()->max('sort') + 1;
            $type = DocumentType::query()->create([...$data, 'is_active' => true]);
            $this->audit->log('document_type_created', $admin, $type, null, null, ContentVisibility::snapshot($type, self::FIELDS));

            return $type;
        });
    }

    /**
     * @param  array{name: array{ar: string, en: string}, applies_to: mixed, is_required: bool, allowed_mimes: ?array, max_size_kb: ?int, sort: ?int}  $data
     */
    public function update(DocumentType $type, User $admin, array $data): DocumentType
    {
        return DB::transaction(function () use ($type, $admin, $data) {
            $type = DocumentType::query()->whereKey($type->id)->lockForUpdate()->firstOrFail();
            if ($data['sort'] === null) {
                unset($data['sort']);
            }
            $before = ContentVisibility::snapshot($type, self::FIELDS);
            $type->update($data);
            [$old, $new] = ContentVisibility::diff($before, ContentVisibility::snapshot($type, self::FIELDS));
            if ($new !== []) {
                $this->audit->log('document_type_updated', $admin, $type, null, $old, $new);
            }

            return $type;
        });
    }

    public function setActive(DocumentType $type, User $admin, bool $active, ?string $reason): DocumentType
    {
        return DB::transaction(function () use ($type, $admin, $active, $reason) {
            $type = DocumentType::query()->whereKey($type->id)->lockForUpdate()->firstOrFail();
            if ($type->is_active === $active) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            $type->update(['is_active' => $active]);
            $this->audit->log($active ? 'document_type_activated' : 'document_type_stopped', $admin, $type, $reason, ['is_active' => ! $active], ['is_active' => $active]);

            return $type;
        });
    }
}
