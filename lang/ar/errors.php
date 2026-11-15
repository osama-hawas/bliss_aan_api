<?php

// Default messages per App\Enums\ErrorCode.
return [
    'VALIDATION_FAILED' => 'البيانات المدخلة غير صحيحة.',
    'UNAUTHENTICATED' => 'يرجى تسجيل الدخول للمتابعة.',
    'TOKEN_EXPIRED' => 'انتهت صلاحية الجلسة. يرجى تحديثها.',
    'FORBIDDEN' => 'غير مسموح لك بتنفيذ هذا الإجراء.',
    'ACCOUNT_SUSPENDED' => 'حسابك موقوف.',
    'NOT_FOUND' => 'العنصر المطلوب غير موجود.',
    'ACCOUNT_NOT_FOUND' => 'لا يوجد حساب مسجل بهذا الرقم.',
    'METHOD_NOT_ALLOWED' => 'هذا الإجراء غير مدعوم.',
    'INVALID_STATE' => 'هذا الإجراء غير متاح في الحالة الحالية.',
    'NOT_CONFIGURED' => 'هذه الميزة غير مهيأة بعد.',
    'OTP_INVALID' => 'رمز التحقق غير صحيح.',
    'OTP_EXPIRED' => 'انتهت صلاحية رمز التحقق. يرجى طلب رمز جديد.',
    'TOO_MANY_ATTEMPTS' => 'محاولات كثيرة. يرجى المحاولة لاحقًا.',
    'OUT_OF_COVERAGE' => 'مقدمة الخدمة لا تغطي المدينة المختارة.',
    'NO_MATCHING_PROVIDERS' => 'لا توجد مقدمة خدمة متاحة لهذا الطلب في المدينة المختارة حاليًا.',
    'DUPLICATE_REQUEST' => 'تم إرسال هذا الطلب سابقًا بتفاصيل مختلفة.',
    'SLOT_UNAVAILABLE' => 'مقدمة الخدمة غير متاحة في الوقت المختار. يرجى اختيار تاريخ أو وقت آخر.',
    'BLOCK_OVERLAP' => 'هذه الفترة تتداخل مع فترة حجب لديكِ بالفعل.',
    'OFFER_EXPIRED' => 'انتهت صلاحية هذا العرض.',
    'ALREADY_BOOKED' => 'هذا الطلب لديه حجز مؤكد بالفعل.',
    'WALLET_EXISTS' => 'لديكِ محفظة بالفعل.',
    'SERVER_ERROR' => 'حدث خطأ ما. يرجى المحاولة مرة أخرى.',

    // رسائل حالات INVALID_STATE المحددة.
    'application_documents_open' => 'اقبلي كل المستندات المرفقة قبل تفعيل الحساب.',
    'application_documents_missing' => 'مستند مطلوب غير مرفق.',
];
