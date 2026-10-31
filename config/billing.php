<?php

return [
    'company_name' => env('BILLING_COMPANY_NAME', 'Great Tech Solutions'),
    'tagline' => env('BILLING_TAGLINE', ''),
    'contact_email' => env('BILLING_CONTACT_EMAIL', ''),
    'contact_phones' => array_values(array_filter(array_map('trim', explode(',', (string) env('BILLING_CONTACT_PHONES', ''))))),
];
