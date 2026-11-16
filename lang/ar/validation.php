<?php

/*
 * رسائل التحقق بالعربية للقواعد المستخدمة في النظام. أي قاعدة غير مذكورة
 * هنا تظهر رسالتها الإنجليزية الافتراضية، فتُضاف عند استخدامها.
 */
return [
    'accepted' => 'يجب قبول :attribute.',
    'array' => 'يجب أن يكون :attribute قائمة.',
    'boolean' => 'قيمة :attribute يجب أن تكون نعم أو لا.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'date' => ':attribute ليس تاريخًا صحيحًا.',
    'email' => ':attribute يجب أن يكون بريدًا إلكترونيًا صحيحًا.',
    'exists' => ':attribute المختار غير موجود.',
    'file' => ':attribute يجب أن يكون ملفًا.',
    'in' => ':attribute المختار غير صالح.',
    'integer' => ':attribute يجب أن يكون رقمًا صحيحًا.',
    'lowercase' => ':attribute يجب أن يكون بحروف صغيرة.',
    'max' => [
        'array' => ':attribute يجب ألا يزيد عن :max عنصر.',
        'file' => 'حجم :attribute يجب ألا يزيد عن :max كيلوبايت.',
        'numeric' => ':attribute يجب ألا يزيد عن :max.',
        'string' => ':attribute يجب ألا يزيد عن :max حرف.',
    ],
    'mimes' => ':attribute يجب أن يكون من نوع: :values.',
    'min' => [
        'array' => ':attribute يجب أن يحتوي :min عنصر على الأقل.',
        'file' => 'حجم :attribute يجب أن يكون :min كيلوبايت على الأقل.',
        'numeric' => ':attribute يجب أن يكون :min على الأقل.',
        'string' => ':attribute يجب أن يكون :min حرف على الأقل.',
    ],
    'numeric' => ':attribute يجب أن يكون رقمًا.',
    'password' => [
        'letters' => ':attribute يجب أن يحتوي حرفًا واحدًا على الأقل.',
        'mixed' => ':attribute يجب أن يحتوي حرفًا كبيرًا وحرفًا صغيرًا على الأقل.',
        'numbers' => ':attribute يجب أن يحتوي رقمًا واحدًا على الأقل.',
        'symbols' => ':attribute يجب أن يحتوي رمزًا واحدًا على الأقل.',
        'uncompromised' => ':attribute ظهر في تسريب بيانات، اختر غيره.',
    ],
    'required' => 'حقل :attribute مطلوب.',
    'string' => ':attribute يجب أن يكون نصًا.',
    'unique' => ':attribute مستخدم من قبل.',
    'url' => ':attribute ليس رابطًا صحيحًا.',

    'attributes' => [
        'email' => 'البريد الإلكتروني',
        'password' => 'كلمة المرور',
        'current_password' => 'كلمة المرور الحالية',
        'password_confirmation' => 'تأكيد كلمة المرور',
        'name' => 'الاسم',
        'locale' => 'اللغة',
    ],
];
