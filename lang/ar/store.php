<?php

// Slice 9 store catalog and cart.
return [
    'attributes' => [
        'size' => 'المقاس',
        'color' => 'اللون',
    ],
    'out_of_stock' => 'لا يمكن إضافة كمية أكبر من المتوفر في المخزون.',

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
