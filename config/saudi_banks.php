<?php

/*
 * Saudi IBAN bank codes (characters 5–6 of the IBAN) → display names (Slice 11 §7). Display only: an unknown code is
 * still accepted with a null bank name. Merged banks keep their old codes. Verify against SAMA's current list before
 * release (release checklist).
 */
return [
    '05' => ['key' => 'alinma', 'ar' => 'مصرف الإنماء', 'en' => 'Alinma Bank'],
    '10' => ['key' => 'snb', 'ar' => 'البنك الأهلي السعودي', 'en' => 'Saudi National Bank'],
    '15' => ['key' => 'albilad', 'ar' => 'بنك البلاد', 'en' => 'Bank Albilad'],
    '20' => ['key' => 'riyad', 'ar' => 'بنك الرياض', 'en' => 'Riyad Bank'],
    '30' => ['key' => 'anb', 'ar' => 'البنك العربي الوطني', 'en' => 'Arab National Bank'],
    '40' => ['key' => 'samba', 'ar' => 'البنك الأهلي السعودي (سامبا سابقًا)', 'en' => 'Saudi National Bank (formerly Samba)'],
    '45' => ['key' => 'sab', 'ar' => 'البنك السعودي الأول', 'en' => 'Saudi Awwal Bank'],
    '50' => ['key' => 'alawwal', 'ar' => 'البنك السعودي الأول (الأول سابقًا)', 'en' => 'Saudi Awwal Bank (formerly Alawwal)'],
    '55' => ['key' => 'bsf', 'ar' => 'البنك السعودي الفرنسي', 'en' => 'Banque Saudi Fransi'],
    '60' => ['key' => 'aljazira', 'ar' => 'بنك الجزيرة', 'en' => 'Bank AlJazira'],
    '65' => ['key' => 'saib', 'ar' => 'البنك السعودي للاستثمار', 'en' => 'Saudi Investment Bank'],
    '80' => ['key' => 'alrajhi', 'ar' => 'مصرف الراجحي', 'en' => 'Al Rajhi Bank'],
];
