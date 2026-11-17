<?php

namespace Database\Seeders;

use App\Models\DeclineReason;
use Illuminate\Database\Seeder;

/**
 * Reasons a provider picks when declining a request (D51). Codes are fixed; the wording is D243 (Q15, SFIX). Idempotent
 * on `code`. An existing row keeps its active flag and sort; its wording is replaced only while it still holds the
 * earlier proposed text, so an admin edit is never overwritten.
 */
class DeclineReasonSeeder extends Seeder
{
    /** D243 wording. */
    public const REASONS = [
        'fully_booked' => ['ar' => 'لدي حجز آخر في هذا الموعد', 'en' => 'Already booked at this time'],
        'outside_coverage' => ['ar' => 'موقع المناسبة خارج نطاق تغطيتي', 'en' => 'Event location is outside my coverage'],
        'service_unavailable' => ['ar' => 'لا أقدم هذه الخدمة حالياً', 'en' => 'Not offering this service right now'],
        'requirements_not_met' => ['ar' => 'لا أستطيع تلبية متطلبات الطلب', 'en' => "Can't meet the request's requirements"],
        'insufficient_details' => ['ar' => 'تفاصيل الطلب غير كافية لتقديم عرض', 'en' => 'Not enough details to make an offer'],
        'short_notice' => ['ar' => 'موعد المناسبة قريب جداً ولا يكفي للتحضير', 'en' => 'Too short notice to prepare'],
        'other' => ['ar' => 'سبب آخر', 'en' => 'Other reason'],
    ];

    /** The proposed Slice 4 wording (before D243). */
    public const PREVIOUS = [
        'fully_booked' => ['ar' => 'محجوزة بالكامل في هذا الموعد', 'en' => 'Fully booked at this time'],
        'outside_coverage' => ['ar' => 'خارج نطاق التغطية', 'en' => 'Outside my coverage area'],
        'service_unavailable' => ['ar' => 'الخدمة غير متاحة حالياً', 'en' => 'Service currently unavailable'],
        'requirements_not_met' => ['ar' => 'لا يمكنني تلبية المتطلبات', 'en' => 'Cannot meet the requirements'],
        'insufficient_details' => ['ar' => 'تفاصيل الطلب غير كافية', 'en' => 'Not enough request details'],
        'short_notice' => ['ar' => 'الموعد قريب جداً', 'en' => 'Too short notice'],
        'other' => ['ar' => 'سبب آخر', 'en' => 'Other'],
    ];

    public function run(): void
    {
        $sort = 0;
        foreach (self::REASONS as $code => $name) {
            $reason = DeclineReason::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true, 'sort' => ++$sort]);
            if (! $reason->wasRecentlyCreated && $reason->getTranslations('name') == self::PREVIOUS[$code]) {
                $reason->update(['name' => $name]);
            }
        }
    }
}
