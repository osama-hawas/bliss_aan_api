<?php

namespace App\Services\Audit;

use App\Enums\UserType;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns `subject_type`/`subject_id` into something staff can read and open (D321): a type key, a name or reference and
 * a dashboard link. Subjects are loaded once per type; a type whose class does not exist (yet), or a deleted row,
 * still shows its type and id.
 */
class AuditSubjects
{
    /** Dashboard page per subject type key; `{id}` = subject id, `{provider_id}` = the subject's provider. */
    private const HREFS = [
        'provider' => '/providers/{id}',
        'provider_document' => '/provider-applications/{provider_id}',
        'role' => '/settings/roles',
        'cancellation_request' => '/cancellations/{id}',
        'service_request' => '/requests/{id}',
        'booking' => '/bookings/{id}',
        'support_ticket' => '/support/{id}',
        'review' => '/reviews',
        'content_report' => '/content-reports',
        'payment' => '/finance/payments/{id}',
        'refund' => '/finance/refunds',
        'payout_request' => '/finance/payouts/{id}',
        'store_order' => '/store/orders/{id}',
        'store_return' => '/store/returns/{id}', // S23
        'product' => '/store/products/{id}',
        'service' => '/catalog/services/{id}',
        'service_category' => '/catalog/categories',
        'addon' => '/catalog/addons',
        'package' => '/catalog/packages/{id}', // Slice 24
        'banner' => '/content/banners',
        'page' => '/content/pages',
        'faq' => '/content/faqs',
    ];

    /**
     * Sets `subject_view` {type, type_label, name, href} on each row.
     *
     * @param  iterable<AuditLog>  $logs
     */
    public function describe(iterable $logs): void
    {
        $logs = Collection::make($logs);
        $models = [];

        foreach ($logs->whereNotNull('subject_type')->groupBy('subject_type') as $type => $rows) {
            $class = Relation::getMorphedModel($type) ?? $type;
            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }
            $query = $class::query()->withoutGlobalScopes();
            if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
                $query->withTrashed();
            }
            $models[$type] = $query->whereKey($rows->pluck('subject_id')->unique()->all())->get()->keyBy(fn (Model $m) => $m->getKey());
        }

        foreach ($logs as $log) {
            $log->setAttribute('subject_view', $log->subject_type === null ? null : $this->view($log, $models[$log->subject_type][$log->subject_id] ?? null));
        }
    }

    /**
     * @return array{type: string, type_label: string, id: ?int, name: string, href: ?string}
     */
    private function view(AuditLog $log, ?Model $subject): array
    {
        $class = Relation::getMorphedModel($log->subject_type) ?? $log->subject_type;
        $type = Str::snake(class_basename($class));

        if ($subject !== null && $type === 'user') {
            $type = match ($subject->getAttribute('type')?->value ?? $subject->getAttribute('type')) {
                UserType::Client->value => 'client',
                UserType::Admin->value => 'staff',
                UserType::Provider->value => 'provider_user',
                default => 'user',
            };
        }

        return [
            'type' => $type,
            'type_label' => AuditLabels::subjectType($type),
            'id' => $log->subject_id,
            'name' => $this->name($subject) ?? '#'.$log->subject_id,
            'href' => $this->href($type, $log, $subject),
        ];
    }

    private function name(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        foreach (['reference', 'display_name', 'name', 'code', 'title', 'slug'] as $attribute) {
            $value = $subject->getAttribute($attribute);
            if (is_array($value)) {
                $value = $value[app()->getLocale()] ?? reset($value) ?: null;
            } elseif (method_exists($subject, 'getTranslation') && in_array($attribute, $subject->translatable ?? [], true)) {
                $value = $subject->getTranslation($attribute, app()->getLocale());
            }
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function href(string $type, AuditLog $log, ?Model $subject): ?string
    {
        if ($type === 'client') {
            return "/clients/{$log->subject_id}";
        }
        if ($type === 'staff') {
            return '/settings/staff';
        }
        if ($type === 'provider' && str_starts_with($log->action, 'application_')) {
            return "/provider-applications/{$log->subject_id}";
        }

        $pattern = self::HREFS[$type] ?? null;
        if ($pattern === null) {
            return null;
        }
        if (str_contains($pattern, '{provider_id}')) {
            $providerId = $subject?->getAttribute('provider_id');
            if ($providerId === null) {
                return null;
            }
            $pattern = str_replace('{provider_id}', (string) $providerId, $pattern);
        }

        return str_replace('{id}', (string) $log->subject_id, $pattern);
    }
}
