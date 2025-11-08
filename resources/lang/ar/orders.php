<?php

return [
    // الأقسام
    'type_parties'    => 'النوع والأطراف',
    'items'           => 'العناصر',
    'items_desc'      => 'ابحث برقم القطعة (SKU) أو الاسم. يتم استخدام سعر التخفيض إن وُجد؛ المخزون حسب الفرع المحدد.',
    'currency_totals' => 'العملة والإجماليات',

    // الحقول (اليسار)
    'type'           => 'النوع',
    'proforma'       => 'عرض سعر',
    'order'          => 'طلب',
    'branch'         => 'الفرع',
    'seller'         => 'البائع',
    'customer'       => 'العميل',
    'customer_hint'  => 'مطلوب في حالة الطلب، واختياري في عرض السعر.',
    'customer_name'  => 'اسم العميل',

    // حالة الطلب (عند النوع = طلب)
    'status'            => 'الحالة',
    'status_draft'      => 'مسودة',
    'status_pending'    => 'قيد الانتظار',
    'status_processing' => 'جاري المعالجة',
    'status_completed'  => 'مكتمل',
    'status_cancelled'  => 'ملغى',
    'status_refunded'   => 'مسترجع',
    'status_failed'     => 'فشل',
    'status_on_hold'    => 'معلّق',

    'payment_status' => 'حالة الدفع',
    'unpaid'         => 'غير مدفوع',
    'paid'           => 'مدفوع',

    // مكرر العناصر
    'product'      => 'المنتج',
    'qty'          => 'الكمية',
    'unit'         => 'سعر الوحدة',
    'line_total'   => 'الإجمالي الفرعي',
    'stock'        => 'المخزون',
    'prev_sales'   => 'المبيعات السابقة',
    'on'           => 'في',
    'no_extra'     => 'لا توجد معلومات إضافية.',
    'item_exists'  => 'هذا المنتج موجود بالفعل في القائمة.',

    // لوحة الإجماليات (يمين)
    'currency'       => 'العملة',
    'usd_to_rate'    => 'دولار → سعر التحويل',
    'subtotal'       => 'المجموع الفرعي',
    'discount'       => 'الخصم',
    'shipping'       => 'الشحن',
    'extra_fees'     => 'رسوم إضافية ٪',
    'extra_fees_hint'=> 'نسبة تُطبّق على المجموع الفرعي',
    'total'          => 'الإجمالي',
    'partial' => 'جزئي',
    'paid_amount' => 'المبلغ المدفوع',
    'paid_amount_hint' => 'أدخل المبلغ الذي تم استلامه لهذا الطلب.',
    'add_item' => 'إضافة بند',
    'item_note' => 'ملاحظة البند',
    'item_note_placeholder' => 'ملاحظة داخلية/للعميل لهذا البند (تظهر في ملف PDF).',
    'invoice_note' => 'ملاحظة الفاتورة (تظهر في أسفل ملف PDF)',
    'invoice_note_placeholder' => 'أي رسالة أو شروط تُطبع في أسفل الفاتورة.',
];
