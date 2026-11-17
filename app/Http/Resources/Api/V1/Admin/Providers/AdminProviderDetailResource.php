<?php

namespace App\Http\Resources\Api\V1\Admin\Providers;

use App\Models\ActivityEvent;
use App\Models\AuditLog;
use App\Models\PortfolioItem;
use App\Models\Provider;
use App\Models\ProviderService;
use App\Services\Media\MediaService;
use App\Services\Providers\ProviderRoster;
use Illuminate\Http\Request;

/**
 * Provider page in the dashboard: profile, services with prices and places, coverage, portfolio (hidden items
 * included, D176), open items (D172) and her standing history.
 *
 * @mixin Provider
 */
class AdminProviderDetailResource extends AdminProviderResource
{
    /**
     * Activity events shown in the provider history.
     */
    public const LOG_EVENTS = [
        'application_activated', 'provider_suspended', 'provider_reactivated', 'provider_verified', 'provider_unverified',
        'provider_featured', 'provider_unfeatured', 'portfolio_hidden', 'portfolio_restored',
    ];

    public function toArray(Request $request): array
    {
        $media = app(MediaService::class);

        return [
            ...parent::toArray($request),
            'legal_name' => $this->legal_name,
            'representative_name' => $this->representative_name,
            'representative_title' => $this->representative_title,
            'email' => $this->user?->email,
            'bio' => $this->bio,
            'started_year' => $this->started_year,
            'accepts_open_requests' => (bool) $this->accepts_open_requests,
            'application_id' => $this->id,
            'coverage' => $this->coverageAreas->map(fn ($a) => ['city_id' => $a->city_id, 'city' => $a->city?->getTranslations('name'), 'districts' => []])->values(),
            'services' => $this->services->map(fn (ProviderService $s) => [
                'id' => $s->id,
                'service' => [
                    'id' => $s->service_id,
                    'name' => $s->service?->getTranslations('name'),
                    'category' => $s->service?->category?->getTranslations('name'),
                ],
                'guide_price' => $this->money($s->service?->guide_price),
                'custom_price' => $this->money($s->custom_price),
                'price' => $this->money($s->price()),
                'is_active' => $s->is_active,
                'at_provider_location' => $s->at_provider_location,
                'at_event_location' => $s->at_event_location,
                'event_location_confirmed_at' => $this->dateTime($s->event_location_confirmed_at),
            ])->values(),
            'portfolio' => $this->portfolioItems->map(fn (PortfolioItem $p) => [
                'id' => $p->id,
                'image' => $media->url($p->media),
                'caption' => $p->caption,
                'hidden' => $p->hidden_by_admin,
                'hidden_reason' => $p->hidden_by_admin ? $p->hidden_reason : null,
                'created_at' => $this->dateTime($p->created_at),
            ])->values(),
            'open_items' => app(ProviderRoster::class)->openItems($this->resource),
            'logs' => $this->logs(),
        ];
    }

    /**
     * Each event with the reason of its audit row (both written in the same transaction, in the same order).
     */
    private function logs(): array
    {
        $events = ActivityEvent::query()->whereMorphedTo('subject', $this->resource)->whereIn('event', self::LOG_EVENTS)
            ->with('actor')->orderBy('id')->get();
        $audits = AuditLog::query()->whereMorphedTo('subject', $this->resource)->whereIn('action', self::LOG_EVENTS)
            ->orderBy('id')->get(['action', 'reason'])->groupBy('action')->map->values();
        $seen = [];

        return $events->map(function (ActivityEvent $e) use ($audits, &$seen) {
            $index = $seen[$e->event] = ($seen[$e->event] ?? -1) + 1;

            return [
                'id' => $e->id,
                'action' => $e->event,
                'action_label' => __('accounts.actions.'.$e->event),
                'reason' => $audits->get($e->event)?->get($index)?->reason,
                'internal_note' => $e->meta['internal_note'] ?? null,
                'actor' => $e->actor?->name,
                'created_at' => $this->dateTime($e->created_at),
            ];
        })->reverse()->values()->all();
    }

    public static function load(Provider $provider): Provider
    {
        return $provider->load(['user', 'city', 'coverageAreas.city', 'services.service.category', 'portfolioItems.media']);
    }
}
