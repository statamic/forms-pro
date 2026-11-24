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

    'connections' => [

        'google_sheets' => [
            'credentials' => env('FORMS_PRO_GOOGLE_SHEETS_CREDENTIALS'),
        ],

        'hubspot' => [
            'access_token' => env('FORMS_PRO_HUBSPOT_ACCESS_TOKEN'),
        ],

        'kit' => [
            'api_key' => env('FORMS_PRO_KIT_API_KEY'),
        ],

        'mailchimp' => [
            'api_key' => env('FORMS_PRO_MAILCHIMP_API_KEY'),
        ],

        'mailcoach' => [
            'api_url' => env('FORMS_PRO_MAILCOACH_API_URL'),
            'token' => env('FORMS_PRO_MAILCOACH_TOKEN'),
        ],

        'slack' => [
            'token' => env('FORMS_PRO_SLACK_TOKEN'),
        ],

        'twilio' => [
            'account_sid' => env('FORMS_PRO_TWILIO_ACCOUNT_SID'),
            'api_key' => env('FORMS_PRO_TWILIO_API_KEY'),
            'api_secret' => env('FORMS_PRO_TWILIO_API_SECRET'),
        ],

    ],

];
