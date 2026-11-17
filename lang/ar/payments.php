<?php

// Slice 10 (payments): gateway failure texts and automatic refund reasons.
return [
    'failure' => [
        'declined' => 'تم رفض البطاقة.',
        'insufficient_funds' => 'رصيد البطاقة غير كافٍ.',
        'expired_card' => 'انتهت صلاحية البطاقة.',
        'authentication_failed' => 'لم يتم تأكيد الدفع مع البنك.',
        'gateway_error' => 'تعذّرت معالجة الدفع. حاولي مرة أخرى.',
    ],
    'refund_reason' => [
        'acceptance_failed' => 'استرداد كامل تلقائي: تعذّر تأكيد الحجز بعد الدفع.',
        'superseded_attempt' => 'استرداد كامل تلقائي: نجحت محاولة دفع سابقة بعد استبدالها أو انتهائها.',
    ],
    'created' => 'بدأت عملية الدفع.',
    'method_saved' => 'تم حفظ البطاقة.',
    'method_removed' => 'تم حذف البطاقة.',
    'method_defaulted' => 'تم تحديث البطاقة الافتراضية.',
    'refund_started' => 'بدأ الاسترداد.',
    'fake_checkout_title' => 'تأكيد دفع تجريبي',
    'fake_checkout_body' => 'هذه الصفحة بديلة عن خطوة تأكيد البنك (بوابة الدفع التجريبية فقط).',
    'fake_checkout_approve' => 'موافقة',
    'fake_checkout_decline' => 'رفض',
    'fake_checkout_done' => 'تم. يمكنكِ العودة إلى التطبيق.',
];
