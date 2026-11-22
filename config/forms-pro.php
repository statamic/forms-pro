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

    'automagic_forms' => [

        /*
        |----------------------------------------------------------------------
        | Automagic Forms
        |----------------------------------------------------------------------
        |
        | The automatic forms feature generates a shareable link for each form,
        | allowing your visitors to fill out the form without you having to create
        | a page for it. Additional options are available in the addon's settings.
        |
        */

        'enabled' => true,

        /*
        |----------------------------------------------------------------------
        | Route
        |----------------------------------------------------------------------
        |
        | The route prefix to be used for Automagic Form URLs. Eg: "forms"
        | will give you URLs like "/forms/contact".
        |
        */

        'route' => 'forms',

        /*
        |----------------------------------------------------------------------
        | Asset Container
        |----------------------------------------------------------------------
        |
        | Specify which asset container should be used for the Logo field in
        | the addon's settings. Leave empty to use the first container.
        |
        */

        'asset_container' => null,

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
