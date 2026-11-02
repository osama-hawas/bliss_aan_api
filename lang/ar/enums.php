<?php

// Labels for App\Enums (key = enum short name in snake_case).
return [
    'user_type' => ['client' => 'عميلة', 'provider' => 'مقدمة خدمة', 'admin' => 'الإدارة'],
    'user_status' => ['active' => 'نشط', 'suspended' => 'موقوف', 'pending_deletion' => 'بانتظار الحذف'],
    'otp_purpose' => ['login' => 'تسجيل الدخول', 'register' => 'إنشاء حساب', 'change_phone' => 'تغيير رقم الجوال'],
    'device_app' => ['client' => 'تطبيق العميلة', 'provider' => 'تطبيق مقدمة الخدمة', 'dashboard' => 'لوحة التحكم'],
    'media_visibility' => ['private' => 'خاص', 'public' => 'عام'],
    'policy_type' => [
        'payment' => 'سياسة الدفع',
        'cancellation' => 'سياسة الإلغاء',
        'commission' => 'سياسة العمولة',
        'store_return' => 'سياسة الإرجاع في المتجر',
        'terms' => 'الشروط والأحكام',
    ],
    'activity_visibility' => ['client' => 'العميلة', 'provider' => 'مقدمة الخدمة', 'internal' => 'داخلي', 'all' => 'الجميع'],
    'audience' => ['client' => 'تطبيق العميلة', 'provider' => 'تطبيق مقدمة الخدمة'],
    'document_applies_to' => ['individual' => 'فرد', 'establishment' => 'منشأة', 'both' => 'كلاهما'],
    'admin_role' => [
        'super_admin' => 'مدير النظام',
        'accounts_officer' => 'مسؤول الحسابات والانضمام',
        'operations_support' => 'مسؤول العمليات والدعم',
        'store_content_officer' => 'مسؤول المتجر والمحتوى',
        'delivery_officer' => 'مسؤول التوصيل',
    ],
    'upload_context' => [
        'provider_document' => 'مستند مقدمة الخدمة',
        'portfolio' => 'معرض الأعمال',
        'chat_image' => 'صورة محادثة',
        'chat_file' => 'ملف محادثة',
        'chat_voice' => 'رسالة صوتية',
        'booking_update' => 'مرفق تحديث الحجز',
        'catalog_image' => 'صورة الكتالوج',
        'banner' => 'بانر',
    ],
];
