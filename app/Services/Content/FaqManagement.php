<?php

namespace App\Services\Content;

use App\Enums\Audience;
use App\Models\Faq;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * FAQs of each app's support screen (`shared/faqs?app=`, Slice 12b). Ordered by `sort` (D303).
 */
class FaqManagement
{
    public const SORTS = ['sort', '-sort', 'updated_at', '-updated_at'];

    private const FIELDS = ['audience', 'question', 'answer', 'sort'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ContentVisibility $visibility,
    ) {}

    /**
     * @param  array{q: ?string, status: ?string, audience: ?string}  $filters
     */
    public function list(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= 'sort';

        return Faq::query()
            ->when($filters['q'], fn (Builder $q, string $text) => $q->where(fn (Builder $w) => $w
                ->where('question->ar', 'like', "%{$text}%")->orWhere('question->en', 'like', "%{$text}%")
                ->orWhere('answer->ar', 'like', "%{$text}%")->orWhere('answer->en', 'like', "%{$text}%")))
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('is_active', $status === 'visible'))
            ->when($filters['audience'], fn (Builder $q, string $audience) => $q->where('audience', $audience))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function find(int $id): Faq
    {
        return Faq::query()->findOrFail($id);
    }

    /**
     * @param  array{audience: Audience, question: array{ar: string, en: string}, answer: array{ar: string, en: string}, sort: ?int}  $data
     */
    public function create(User $admin, array $data): Faq
    {
        return DB::transaction(function () use ($admin, $data) {
            $data['sort'] ??= (int) Faq::query()->where('audience', $data['audience'])->max('sort') + 1;
            $faq = Faq::query()->create([...$data, 'is_active' => false]);
            $this->audit->log('faq_created', $admin, $faq, null, null, ContentVisibility::snapshot($faq, self::FIELDS));

            return $faq;
        });
    }

    /**
     * @param  array{audience: Audience, question: array{ar: string, en: string}, answer: array{ar: string, en: string}, sort: ?int}  $data
     */
    public function update(Faq $faq, User $admin, array $data): Faq
    {
        return DB::transaction(function () use ($faq, $admin, $data) {
            $faq = Faq::query()->whereKey($faq->id)->lockForUpdate()->firstOrFail();
            ContentVisibility::guardVisibleEdit($faq, ['question' => $data['question'], 'answer' => $data['answer']]);
            if ($data['sort'] === null) {
                unset($data['sort']);
            }
            $before = ContentVisibility::snapshot($faq, self::FIELDS);
            $faq->update($data);
            [$old, $new] = ContentVisibility::diff($before, ContentVisibility::snapshot($faq, self::FIELDS));
            if ($new !== []) {
                $this->audit->log('faq_updated', $admin, $faq, null, $old, $new);
            }

            return $faq;
        });
    }

    public function show(Faq $faq, User $admin): Faq
    {
        return $this->visibility->show($faq, $admin, 'faq_shown', ['question', 'answer']);
    }

    public function hide(Faq $faq, User $admin, string $reason): Faq
    {
        return $this->visibility->hide($faq, $admin, 'faq_hidden', $reason);
    }
}
