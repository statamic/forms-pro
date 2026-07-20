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
        | Asset Container
        |----------------------------------------------------------------------
        |
        | Specify which asset container should be used for the Logo field in
        | the addon's settings. Leave empty to use the first container.
        |
        */

        'asset_container' => null,

    ],

];
