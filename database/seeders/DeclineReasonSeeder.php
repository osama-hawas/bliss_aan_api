<?php

namespace Database\Seeders;

use App\Models\DeclineReason;
use Illuminate\Database\Seeder;

/**
 * Reasons a provider picks when declining a request (D51). Codes are fixed; the wording is proposed and may change
 * before go-live (Q15). Idempotent on `code`; an existing row keeps its wording and active flag.
 */
class DeclineReasonSeeder extends Seeder
{
    public function run(): void
    {
        $reasons = [
            'fully_booked' => ['ar' => 'محجوزة بالكامل في هذا الموعد', 'en' => 'Fully booked at this time'],
            'outside_coverage' => ['ar' => 'خارج نطاق التغطية', 'en' => 'Outside my coverage area'],
            'service_unavailable' => ['ar' => 'الخدمة غير متاحة حالياً', 'en' => 'Service currently unavailable'],
            'requirements_not_met' => ['ar' => 'لا يمكنني تلبية المتطلبات', 'en' => 'Cannot meet the requirements'],
            'insufficient_details' => ['ar' => 'تفاصيل الطلب غير كافية', 'en' => 'Not enough request details'],
            'short_notice' => ['ar' => 'الموعد قريب جداً', 'en' => 'Too short notice'],
            'other' => ['ar' => 'سبب آخر', 'en' => 'Other'],
        ];

        $sort = 0;
        foreach ($reasons as $code => $name) {
            DeclineReason::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true, 'sort' => ++$sort]);
        }
    }
}
