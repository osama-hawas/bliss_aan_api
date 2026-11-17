<?php

namespace App\Services\Settings;

use App\Enums\ErrorCode;
use App\Enums\ProviderStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\City;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Content\ContentVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cities and coverage (Slice 20, D308). Stopping a city hides it from pickers and coverage editing only; nothing that
 * points at it changes (BRD: disabled, not deleted).
 */
class CityManagement
{
    private const FIELDS = ['name', 'sort'];

    public const OPEN_REQUEST_STATUSES = [ServiceRequestStatus::AwaitingOffers, ServiceRequestStatus::OffersReceived];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Every city with its coverage figures (providers covering it, clients living in it).
     *
     * @return Collection<int, City>
     */
    public function list(?string $q, ?string $status): Collection
    {
        return City::query()
            ->select('cities.*')
            ->selectSub(DB::table('provider_coverage_areas')->selectRaw('count(distinct provider_id)')->whereColumn('city_id', 'cities.id'), 'providers_count')
            ->selectSub(DB::table('users')->selectRaw('count(*)')->whereColumn('city_id', 'cities.id')->where('type', UserType::Client->value), 'clients_count')
            ->when($q, fn (Builder $query, string $text) => $query->where(fn (Builder $w) => $w
                ->where('name->ar', 'like', "%{$text}%")->orWhere('name->en', 'like', "%{$text}%")))
            ->when($status, fn (Builder $query, string $s) => $query->where('is_active', $s === 'active'))
            ->orderBy('sort')->orderBy('id')
            ->get();
    }

    public function find(int $id): City
    {
        return $this->list(null, null)->firstWhere('id', $id) ?? throw new ApiException(ErrorCode::NotFound);
    }

    /**
     * @return array{figures: list<array{kind: string, count: int}>}
     */
    public function impact(City $city): array
    {
        return ['figures' => [
            ['kind' => 'providers', 'count' => (int) DB::table('provider_coverage_areas')
                ->join('providers', 'providers.id', '=', 'provider_coverage_areas.provider_id')
                ->where('provider_coverage_areas.city_id', $city->id)->where('providers.status', ProviderStatus::Active->value)
                ->distinct()->count('provider_coverage_areas.provider_id')],
            ['kind' => 'clients', 'count' => User::query()->where('type', UserType::Client)->where('city_id', $city->id)->count()],
            ['kind' => 'requests', 'count' => DB::table('service_requests')->where('city_id', $city->id)
                ->whereIn('status', array_map(fn ($s) => $s->value, self::OPEN_REQUEST_STATUSES))->count()],
        ]];
    }

    /**
     * @param  array{name: array{ar: string, en: string}, sort: ?int}  $data
     */
    public function create(User $admin, array $data): City
    {
        return DB::transaction(function () use ($admin, $data) {
            $data['sort'] ??= (int) City::query()->max('sort') + 1;
            $city = City::query()->create([...$data, 'is_active' => true]);
            $this->audit->log('city_created', $admin, $city, null, null, ContentVisibility::snapshot($city, self::FIELDS));

            return $city;
        });
    }

    /**
     * @param  array{name: array{ar: string, en: string}, sort: ?int}  $data
     */
    public function update(City $city, User $admin, array $data): City
    {
        return DB::transaction(function () use ($city, $admin, $data) {
            $city = City::query()->whereKey($city->id)->lockForUpdate()->firstOrFail();
            if ($data['sort'] === null) {
                unset($data['sort']);
            }
            $before = ContentVisibility::snapshot($city, self::FIELDS);
            $city->update($data);
            [$old, $new] = ContentVisibility::diff($before, ContentVisibility::snapshot($city, self::FIELDS));
            if ($new !== []) {
                $this->audit->log('city_updated', $admin, $city, null, $old, $new);
            }

            return $city;
        });
    }

    public function stop(City $city, User $admin, string $reason): City
    {
        return $this->setActive($city, $admin, false, $reason);
    }

    public function activate(City $city, User $admin, ?string $reason): City
    {
        return $this->setActive($city, $admin, true, $reason);
    }

    private function setActive(City $city, User $admin, bool $active, ?string $reason): City
    {
        return DB::transaction(function () use ($city, $admin, $active, $reason) {
            $city = City::query()->whereKey($city->id)->lockForUpdate()->firstOrFail();
            if ($city->is_active === $active) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            $city->update(['is_active' => $active]);
            $this->audit->log($active ? 'city_activated' : 'city_stopped', $admin, $city, $reason, ['is_active' => ! $active], ['is_active' => $active]);

            return $city;
        });
    }
}
