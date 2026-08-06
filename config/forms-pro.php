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

    'connections' => [

        'mailcoach' => [
            'api_url' => env('FORMS_PRO_MAILCOACH_API_URL'),
            'token' => env('FORMS_PRO_MAILCOACH_TOKEN'),
        ],

        'slack' => [
            'token' => env('FORMS_PRO_SLACK_TOKEN'),
        ],

    ],

];
