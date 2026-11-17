<?php

// نصوص إشعارات الشريحة 8 (slice-8-contract.md §6). المتغيرات: :name و :reference و :service فقط (D141).
return [
    'someone' => 'مستخدمة',
    'events' => [
        'direct_request_received' => ['title' => 'طلب جديد', 'body' => 'أرسلت لكِ :name طلبًا لخدمة :service (:reference).'],
        'open_request_received' => ['title' => 'طلب مفتوح جديد', 'body' => 'طلب جديد لخدمة :service يناسب خدماتكِ (:reference).'],
        'request_cancelled' => ['title' => 'أُلغي الطلب', 'body' => 'ألغت :name الطلب :reference.'],
        'offer_received' => ['title' => 'عرض جديد', 'body' => 'أرسلت لكِ :name عرضًا لخدمة :service (:reference).'],
        'offer_withdrawn' => ['title' => 'سُحب العرض', 'body' => 'سحبت :name عرضها واعتذرت عن الطلب :reference.'],
        'request_declined' => ['title' => 'اعتذار عن الطلب', 'body' => 'اعتذرت :name عن الطلب :reference.'],
        'offer_expired' => ['title' => 'انتهت صلاحية العرض', 'body' => 'انتهت صلاحية عرضكِ على الطلب :reference.'],
        'offer_accepted' => ['title' => 'قُبل عرضكِ', 'body' => 'قبلت :name عرضكِ، وتأكد الحجز :reference.'],
        'offer_not_selected' => ['title' => 'لم يُختر عرضكِ', 'body' => 'اختير عرض آخر للطلب :reference.'],
        'booking_started' => ['title' => 'بدأت الخدمة', 'body' => 'بدأت :name تنفيذ الحجز :reference.'],
        'booking_completed' => ['title' => 'اكتملت الخدمة', 'body' => 'اكتمل الحجز :reference. قيّمي تجربتكِ مع :name.'],
        'cancellation_approved' => ['title' => 'قُبل طلب الإلغاء', 'body' => 'تمت الموافقة على طلب إلغاء الحجز :reference.'],
        'cancellation_rejected' => ['title' => 'لم يُقبل طلب الإلغاء', 'body' => 'لم تتم الموافقة على طلب إلغاء الحجز :reference، ويبقى الحجز مؤكدًا.'],
        'booking_cancelled' => ['title' => 'أُلغي الحجز', 'body' => 'أُلغي الحجز :reference.'],
        'review_received' => ['title' => 'تقييم جديد', 'body' => 'قيّمت :name الحجز :reference.'],
        'review_replied' => ['title' => 'رد على تقييمكِ', 'body' => 'ردّت :name على تقييمكِ.'],
        'chat_message' => ['title' => 'رسالة جديدة', 'body' => 'رسالة جديدة من :name.'],
        'application_activated' => ['title' => 'تم تفعيل حسابكِ', 'body' => 'أصبح حسابكِ كمقدمة خدمة مفعّلًا، ويمكنكِ الآن استقبال الطلبات.'],
        'application_needs_completion' => ['title' => 'طلبكِ يحتاج إلى استكمال', 'body' => 'يحتاج طلب انضمامكِ إلى بعض التعديلات. افتحيه لمعرفة المطلوب.'],
        'application_suspended' => ['title' => 'تم إيقاف حسابكِ', 'body' => 'أُوقف حسابكِ كمقدمة خدمة. افتحيه لمعرفة التفاصيل.'],
        'application_reactivated' => ['title' => 'أُعيد تفعيل حسابكِ', 'body' => 'أصبح حسابكِ مفعّلًا من جديد.'],
        'application_document_rejected' => ['title' => 'لم يُقبل أحد المستندات', 'body' => 'لم يُقبل أحد مستندات طلبكِ. افتحيه لرفع مستند جديد.'],
        // Slice 9b — store orders (E26–E29). Placeholder :reference only.
        'store_order_confirmed' => ['title' => 'تم تأكيد طلبكِ', 'body' => 'تم تأكيد طلبكِ :reference وجارٍ تجهيزه.'],
        'store_order_shipped' => ['title' => 'طلبكِ في الطريق', 'body' => 'طلبكِ :reference في الطريق إليكِ.'],
        'store_order_delivered' => ['title' => 'تم توصيل طلبكِ', 'body' => 'تم توصيل طلبكِ :reference.'],
        'store_order_cancelled' => ['title' => 'تم إلغاء طلبكِ', 'body' => 'تم إلغاء طلبكِ :reference. افتحيه لمعرفة التفاصيل.'],
        // Slice 11 (D224): E18, E19.
        'payout_paid' => ['title' => 'تم التحويل', 'body' => 'تم تحويل طلبكِ :reference إلى حسابكِ البنكي.'],
        'payout_rejected' => ['title' => 'رُفض طلب التحويل', 'body' => 'رُفض طلب التحويل :reference. افتحيه لمعرفة السبب.'],
        // Slice 17 (E20–E25). :amount مبلغ للعرض (E25 فقط).
        'support_replied' => ['title' => 'ردّ فريق الدعم', 'body' => 'ردّ دعم Bliss aan على تذكرتكِ :reference.'],
        'support_ticket_resolved' => ['title' => 'تم حل التذكرة', 'body' => 'تم تحديد تذكرتكِ :reference كمحلولة. ردّي عليها إن كنتِ ما زلتِ بحاجة إلى مساعدة.'],
        'request_cancelled_by_staff' => ['title' => 'تم إلغاء الطلب', 'body' => 'ألغى دعم Bliss aan طلبكِ :reference.'],
        'booking_cancelled_by_staff' => ['title' => 'تم إلغاء الحجز', 'body' => 'ألغى دعم Bliss aan الحجز :reference.'],
        'booking_completed_by_staff' => ['title' => 'اكتمل الحجز', 'body' => 'حدّد دعم Bliss aan الحجز :reference كمكتمل.'],
        'refund_issued' => ['title' => 'تم إصدار استرداد', 'body' => 'تم إصدار استرداد بمبلغ :amount عن :reference.'],
        // Slice 19 (S19).
        'ledger_credit_posted' => ['title' => 'أُضيف مبلغ إلى رصيدكِ', 'body' => 'أُضيف :amount :currency إلى رصيدكِ (:reference).'],
        'ledger_debit_posted' => ['title' => 'تعديل على رصيدكِ', 'body' => 'خُصم :amount :currency من رصيدكِ (:reference).'],
        // S20 (E40): the campaign's own title and text, in the reader's language (D306).
        'broadcast' => ['title' => ':title', 'body' => ':body'],
    ],
];
