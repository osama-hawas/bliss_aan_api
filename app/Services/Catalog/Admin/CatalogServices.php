<?php

namespace App\Services\Catalog\Admin;

use App\Models\Addon;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceInclusion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Catalog services in the dashboard (Req 10, 12, 55; docs/slice-16-contract.md §4.2).
 *
 * - Guide price (BRD, D181): a default, never a min/max. Changing it writes `services.guide_price` only; providers on the
 *   default follow it through `ProviderService::price()`, custom prices and every snapshot stay as they are. A change
 *   needs a reason and is audit-logged with the number of default providers at save time.
 * - Inclusions (D179): the full list is sent; listed ids are updated and switched on, new texts are created, missing ones
 *   are switched off. Nothing is deleted.
 * - Add-on links: `addon_service` only; providers' own add-on rows stay (D36 hides an unlinked one).
 * - Creating a service never creates `provider_services` rows: providers pick it themselves.
 */
class CatalogServices
{
    public const MAX_ACTIVE_INCLUSIONS = 30;

    public function __construct(
        private readonly CatalogRows $rows,
        private readonly CatalogImpact $impact,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{q: ?string, status: ?string, category_id: ?int, guide_price: ?string}  $filters
     */
    public function list(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $query = $this->query();
        CatalogRows::search($query, $filters['q'], ['name', 'description']);
        $query
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('is_active', $status === 'active'))
            ->when($filters['category_id'], fn (Builder $q, int $category) => $q->where('category_id', $category))
            ->when($filters['guide_price'], fn (Builder $q, string $price) => $price === 'set' ? $q->whereNotNull('guide_price') : $q->whereNull('guide_price'));

        return CatalogRows::sort($query, $sort, 'services')->paginate($perPage);
    }

    public function find(int $id): Service
    {
        return $this->query()
            ->with(['allInclusions', 'addons'])
            ->withCount([
                'providerServices as providers_default' => fn (Builder $q) => $q->where('is_active', true)->whereNull('custom_price'),
                'providerServices as providers_custom' => fn (Builder $q) => $q->where('is_active', true)->whereNotNull('custom_price'),
            ])
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data  validated (§4.2)
     */
    public function create(array $data, User $actor): Service
    {
        return DB::transaction(function () use ($data, $actor) {
            $categoryId = (int) $data['category_id'];
            $service = new Service([
                'category_id' => $categoryId,
                'guide_price' => $data['guide_price'] ?? null,
                'guest_count_mode' => $data['guest_count_mode'] ?? 'optional',
                'is_active' => true,
                'sort' => $this->rows->nextSort(Service::query()->where('category_id', $categoryId)),
            ]);
            $this->rows->fillText($service, $data, ['name', 'description']);

            if (array_key_exists('image_media_id', $data)) {
                $this->rows->setImage($service, $actor, $data['image_media_id']);
            }
            if (array_key_exists('inclusions', $data)) {
                $this->syncInclusions($service, $data['inclusions']);
            }
            if (array_key_exists('addon_ids', $data)) {
                $service->addons()->sync($data['addon_ids']);
            }

            // Review L4: the created inclusions are part of the record.
            $this->audit->log('catalog_service_created', $actor, $service, null, null, [...$this->values($service), 'inclusions' => $this->inclusionValues($service)]);

            return $this->find($service->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated (§4.2); every key optional
     */
    public function update(int $id, array $data, User $actor): Service
    {
        return DB::transaction(function () use ($id, $data, $actor) {
            $service = Service::query()->lockForUpdate()->findOrFail($id);
            $before = $this->values($service);
            $inclusionsBefore = $this->inclusionValues($service);

            $priceChanged = array_key_exists('guide_price', $data)
                && self::price($data['guide_price']) !== self::price($service->guide_price);
            if ($priceChanged && blank($data['reason'] ?? null)) {
                throw ValidationException::withMessages(['reason' => __('catalog.validation.reason_for_price')]);
            }

            if (array_key_exists('category_id', $data) && (int) $data['category_id'] !== $service->category_id) {
                $service->category_id = (int) $data['category_id'];
                // Last in its new category (contract §5.5).
                $service->sort = $this->rows->nextSort(Service::query()->where('category_id', $service->category_id));
            }
            foreach (['guide_price', 'guest_count_mode'] as $field) {
                if (array_key_exists($field, $data)) {
                    $service->setAttribute($field, $data[$field]);
                }
            }
            $this->rows->fillText($service, $data, ['name', 'description']);

            if (array_key_exists('image_media_id', $data)) {
                $this->rows->setImage($service, $actor, $data['image_media_id']);
            }
            if (array_key_exists('inclusions', $data)) {
                $this->syncInclusions($service, $data['inclusions']);
            }
            if (array_key_exists('addon_ids', $data)) {
                $service->addons()->sync($data['addon_ids']);
            }

            $after = $this->values($service->refresh());
            if ($priceChanged) {
                $this->audit->log(
                    'catalog_guide_price_changed',
                    $actor,
                    $service,
                    $data['reason'],
                    ['guide_price' => $before['guide_price']],
                    ['guide_price' => $after['guide_price'], 'providers_default' => $this->impact->defaultProviders($service->id)],
                );
            }
            unset($before['guide_price'], $after['guide_price']);
            [$old, $new] = CatalogCategories::diff($before, $after);
            if ($new !== []) {
                $this->audit->log('catalog_service_updated', $actor, $service, $data['reason'] ?? null, $old, $new);
            }
            $inclusionsAfter = $this->inclusionValues($service);
            if ($inclusionsAfter !== $inclusionsBefore) {
                $this->audit->log('catalog_inclusions_updated', $actor, $service, null, ['inclusions' => $inclusionsBefore], ['inclusions' => $inclusionsAfter]);
            }

            return $this->find($service->id);
        });
    }

    public function stop(int $id, User $actor, string $reason, ?string $internalNote): Service
    {
        $this->rows->setActive(Service::class, $id, false, $actor, $reason, $internalNote, 'catalog_service');

        return $this->find($id);
    }

    public function activate(int $id, User $actor, ?string $reason): Service
    {
        $this->rows->setActive(Service::class, $id, true, $actor, $reason, null, 'catalog_service');

        return $this->find($id);
    }

    public function move(int $id, string $direction, User $actor): Service
    {
        $service = Service::query()->findOrFail($id);
        $this->rows->move($service, Service::query()->where('category_id', $service->category_id), $direction, $actor, 'catalog_service_moved');

        return $this->find($id);
    }

    /**
     * Guide-price / stop preview for one service. `$proposed` is only echoed back (formatted).
     *
     * @return array<string, mixed>
     */
    public function impact(int $id, bool $withPrice, string|int|float|null $proposed): array
    {
        $service = Service::query()->findOrFail($id);
        $impact = $this->impact->forServices([$service->id]);
        if ($withPrice) {
            $impact = [
                'current_guide_price' => Money::format($service->guide_price),
                'proposed_guide_price' => Money::format($proposed === null ? null : (string) $proposed),
            ] + $impact;
        }

        return $impact;
    }

    /**
     * @param  list<array{id?: ?int, text: array{ar: string, en: string}}>  $items
     */
    private function syncInclusions(Service $service, array $items): void
    {
        $existing = ServiceInclusion::query()->where('service_id', $service->id)->lockForUpdate()->get()->keyBy('id');
        $kept = [];

        foreach (array_values($items) as $index => $item) {
            $id = isset($item['id']) ? (int) $item['id'] : null;
            if ($id !== null && ! $existing->has($id)) {
                throw ValidationException::withMessages(["inclusions.{$index}.id" => __('catalog.validation.inclusion')]);
            }
            $inclusion = $id !== null ? $existing->get($id) : new ServiceInclusion(['service_id' => $service->id]);
            $inclusion->fill(['text' => $item['text'], 'sort' => $index + 1, 'is_active' => true])->save();
            $kept[] = $inclusion->id;
        }

        if (count($kept) > self::MAX_ACTIVE_INCLUSIONS) {
            throw ValidationException::withMessages(['inclusions' => __('catalog.validation.inclusions_max', ['max' => self::MAX_ACTIVE_INCLUSIONS])]);
        }

        // Left out → switched off, kept with its id (D179).
        ServiceInclusion::query()
            ->where('service_id', $service->id)
            ->whereNotIn('id', $kept)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    private function query(): Builder
    {
        return Service::query()
            ->with(['image', 'category'])
            ->withCount(['providerServices as providers_count' => fn (Builder $q) => $q->where('is_active', true)]);
    }

    private function values(Service $service): array
    {
        return [
            'category_id' => $service->category_id,
            'name' => $service->getTranslations('name'),
            'description' => $service->getTranslations('description') ?: null,
            'guide_price' => $service->guide_price === null ? null : (string) $service->guide_price,
            'guest_count_mode' => $service->guest_count_mode?->value,
            'image_media_id' => $this->rows->imageId($service),
            'addon_ids' => $service->addons()->pluck('addons.id')->sort()->values()->all(),
        ];
    }

    /**
     * @return list<array{id: int, text: array, is_active: bool}>
     */
    private function inclusionValues(Service $service): array
    {
        return ServiceInclusion::query()->where('service_id', $service->id)->orderBy('id')->get()
            ->map(fn (ServiceInclusion $i) => ['id' => $i->id, 'text' => $i->getTranslations('text'), 'is_active' => (bool) $i->is_active, 'sort' => (int) $i->sort])
            ->all();
    }

    private static function price(string|int|float|null $amount): ?string
    {
        return $amount === null ? null : Money::normalize((string) $amount);
    }

    /**
     * Existing category check for the request rules (an inactive category is allowed, contract §4.2).
     */
    public static function categoryExists(int $id): bool
    {
        return ServiceCategory::query()->whereKey($id)->exists();
    }

    /**
     * @param  list<int>  $ids
     */
    public static function addonsExist(array $ids): bool
    {
        return Addon::query()->whereKey($ids)->count() === count(array_unique($ids));
    }
}
