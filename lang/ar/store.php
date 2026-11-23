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
        // QA 2026-11-23: plain messages for the product variant fields.
        'variant_fields' => [
            'sku_required' => 'أدخلي رمز SKU للخيار.',
            'sku_format' => 'رمز SKU يقبل حروفًا إنجليزية وأرقامًا و - و _ فقط، بدون مسافات. مثال: DRESS-RED-M.',
            'sku_length' => 'رمز SKU من 2 إلى 64 حرفًا.',
            'price_required' => 'أدخلي سعر الخيار.',
            'price_number' => 'أدخلي سعر الخيار أرقامًا فقط، مثل 150 أو 99.50.',
            'price_positive' => 'سعر الخيار يجب أن يكون أكبر من صفر.',
            'price_max' => 'سعر الخيار أكبر من الحد المسموح.',
            'price_decimals' => 'سعر الخيار يقبل رقمين بعد الفاصلة كحد أقصى، مثل 99.50.',
            'stock_required' => 'أدخلي كمية المخزون للخيار (0 أو أكثر).',
            'stock_integer' => 'كمية المخزون رقم صحيح من 0 فأكثر، بدون كسور.',
            'stock_max' => 'كمية المخزون أكبر من الحد المسموح.',
            'attribute_ar_required' => 'أدخلي قيمة الخيار بالعربية (مثل المقاس أو اللون).',
            'attribute_en_required' => 'أدخلي قيمة الخيار بالإنجليزية (مثل المقاس أو اللون).',
            'attribute_max' => 'قيمة الخيار 40 حرفًا كحد أقصى.',
        ],
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
    // S23 — إرجاع المتجر.
    'returns' => [
        'not_returnable' => 'يمكن إرجاع الطلبات التي تم توصيلها فقط.',
        'not_in_order' => 'هذا المنتج ليس ضمن الطلب.',
        'quantity_max' => 'يمكنك إرجاع :max كحد أقصى من هذا المنتج.',
        'photo_invalid' => 'اختاري صورًا رفعتِها لهذا الإرجاع.',
        'refund_reason' => 'إرجاع من المتجر :reference',
    ],
];
