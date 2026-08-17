<?php

return [

    'address' => [

        /*
        |----------------------------------------------------------------------
        | Address Autocomplete
        |----------------------------------------------------------------------
        |
        | The Address fieldtype can suggest and pre-fill addresses as users type.
        | To enable it, specify a provider ("google" or "geoapify") and a
        | publishable API key.
        |
        */

        'autocomplete' => [
            'provider' => env('FORMS_PRO_ADDRESS_AUTOCOMPLETE_PROVIDER'),
            'key' => env('FORMS_PRO_ADDRESS_AUTOCOMPLETE_KEY'),
        ],

    ],

    'turnstile' => [

        /*
        |----------------------------------------------------------------------
        | Cloudflare Turnstile
        |----------------------------------------------------------------------
        |
        | Forms Pro can protect your forms from spam using Cloudflare
        | Turnstile. Once configured, every submission will need to pass
        | a CAPTCHA challenge.
        |
        */

        'site_key' => env('FORMS_PRO_TURNSTILE_SITE_KEY'),
        'secret_key' => env('FORMS_PRO_TURNSTILE_SECRET_KEY'),

    ],

];
