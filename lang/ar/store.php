<?php

// Slice 9 store catalog and cart.
return [
    'attributes' => [
        'size' => 'المقاس',
        'color' => 'اللون',
    ],
    'out_of_stock' => 'لا يمكن إضافة كمية أكبر من المتوفر في المخزون.',
    // Slice 9b.
    'max_quantity' => 'يمكنكِ طلب :max قطع كحد أقصى من هذا المنتج.',
    'promo' => [
        'unknown' => 'رمز الخصم غير موجود أو لم يعد فعّالًا.',
        'not_started' => 'رمز الخصم لم يبدأ بعد.',
        'ended' => 'انتهت صلاحية رمز الخصم.',
        'usage_limit' => 'وصل رمز الخصم إلى الحد الأقصى للاستخدام.',
        'user_limit' => 'استخدمتِ رمز الخصم هذا الحد الأقصى من المرات.',
        'min_amount' => 'يتطلب رمز الخصم ألا يقل مجموع المنتجات عن :amount ر.س.',
        'covers_order' => 'لا يمكن أن يغطي رمز الخصم الطلب بالكامل.',
        'code_taken' => 'هذا الرمز مستخدم لرمز خصم آخر.',
        'locked_after_use' => 'لا يمكن تغيير النوع أو القيمة بعد استخدام الرمز.',
    ],
    'cancel_reason' => ['stock_unavailable' => 'نفدت إحدى القطع قبل تأكيد دفعكِ. بدأ استرداد المبلغ كاملًا.', 'staff' => 'ألغاه فريق المتجر.'],
    'reorder' => ['unavailable' => 'لم يعد متاحًا', 'out_of_stock' => 'نفدت الكمية', 'reduced' => 'خُفّضت الكمية إلى المتوفر'],
    'internal_requires_officer' => 'اختاري مندوبة التوصيل للتوصيل الداخلي.',
    'officer_invalid' => 'لا يمكن إسناد التوصيل لهذه الموظفة.',
    'shipment_failed_reason' => 'اكتبي سبب تعذّر التوصيل.',

    // Slice 18 (S18) — store management in the dashboard.
    'admin' => [
        'image_invalid' => 'أعيدي رفع الصورة: يجب أن تكون صورة متجر رفعتِها ولم تُستخدم في مكان آخر.',
        'already_active' => 'هذا مفعّل بالفعل.',
        'already_stopped' => 'هذا موقوف بالفعل.',
        'cannot_move' => 'العنصر في طرف القائمة بالفعل.',
        'category_name_taken' => 'يوجد تصنيف آخر بنفس الاسم.',
        'sku_taken' => 'رمز المنتج (SKU) مستخدم بالفعل.',
        'variant_duplicate' => 'يوجد خيار مفعّل آخر لهذا المنتج بنفس المقاس واللون.',
        'note_required' => 'أضيفي ملاحظة لهذا السبب.',
        'stock_now' => 'المخزون الحالي :stock.',
        'stock_unchanged' => 'الكمية الجديدة مساوية للمخزون الحالي.',
        'low_stock_not_configured' => 'لم يُحدَّد حد المخزون المنخفض بعد.',
        'messages' => [
            'category_created' => 'تم إنشاء التصنيف.',
            'category_updated' => 'تم تحديث التصنيف.',
            'category_stopped' => 'تم إيقاف التصنيف وإخفاء منتجاته من التطبيق.',
            'category_activated' => 'تم تفعيل التصنيف.',
            'product_created' => 'تم إنشاء المنتج.',
            'product_updated' => 'تم تحديث المنتج.',
            'product_stopped' => 'تم إيقاف المنتج وإخفاؤه من التطبيق.',
            'product_activated' => 'تم تفعيل المنتج.',
            'variant_created' => 'تمت إضافة الخيار.',
            'variant_updated' => 'تم تحديث الخيار.',
            'variant_stopped' => 'تم إيقاف الخيار.',
            'variant_activated' => 'تم تفعيل الخيار.',
            'stock_adjusted' => 'تم تحديث المخزون.',
        ],
    ],
];
