<?php

namespace Statamic\FormsPro;

use Illuminate\Cookie\Middleware\EncryptCookies;
use Statamic\Facades\Form;
use Statamic\Fieldtypes\Link;
use Statamic\FormsPro\AutomagicForms\AutomagicForms;
use Statamic\FormsPro\AutomagicForms\FormLinkType;
use Statamic\FormsPro\FormConnections\GoogleSheets\GoogleSheetsClient;
use Statamic\FormsPro\FormConnections\HubSpot\HubSpotClient;
use Statamic\FormsPro\FormConnections\Kit\KitClient;
use Statamic\FormsPro\FormConnections\Mailchimp\MailchimpClient;
use Statamic\FormsPro\FormConnections\Mailcoach\MailcoachClient;
use Statamic\FormsPro\FormConnections\Slack\SlackClient;
use Statamic\FormsPro\FormConnections\Twilio\TwilioClient;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $config = false;

    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
        'hotFile' => __DIR__.'/../resources/dist/hot',
    ];

    protected $formJsDrivers = [
        JsDrivers\Alpine::class,
        JsDrivers\AlpinePrecognition::class,
    ];

    public function bootAddon()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/forms-pro.php', 'statamic.forms-pro');

        $this->publishes([
            __DIR__.'/../config/forms-pro.php' => config_path('statamic/forms-pro.php'),
        ], 'forms-pro-config');

        $this->publishes([
            __DIR__.'/../resources/dist-frontend' => public_path('vendor/forms-pro/frontend'),
        ], 'forms-pro-frontend');

        $stubsSource = config('statamic.templates.language', 'antlers') === 'blade'
            ? __DIR__.'/../resources/views/forms/blade'
            : __DIR__.'/../resources/views/forms/antlers';

        $this->publishes([
            $stubsSource => resource_path('views/vendor/forms-pro/forms'),
        ], 'forms-pro-fields');

        $this->bootConnections();

        if (AutomagicForms::enabled()) {
            $this->registerSettingsBlueprint(fn () => Settings::blueprint());

            $this->bootAutomagicForms();
        }
    }

    private function bootConnections(): void
    {
        $this->app->bind(GoogleSheetsClient::class, fn () => new GoogleSheetsClient(
            config('statamic.forms-pro.connections.google_sheets.credentials')
        ));

        EncryptCookies::except('hubspotutk');

        $this->app->bind(HubSpotClient::class, fn () => new HubSpotClient(
            config('statamic.forms-pro.connections.hubspot.access_token')
        ));

        $this->app->bind(KitClient::class, fn () => new KitClient(
            config('statamic.forms-pro.connections.kit.api_key')
        ));

        $this->app->bind(MailchimpClient::class, fn () => new MailchimpClient(
            config('statamic.forms-pro.connections.mailchimp.api_key')
        ));

        $this->app->bind(MailcoachClient::class, fn () => new MailcoachClient(
            apiUrl: config('statamic.forms-pro.connections.mailcoach.api_url'),
            token: config('statamic.forms-pro.connections.mailcoach.token'),
        ));

        $this->app->bind(SlackClient::class, fn () => new SlackClient(
            config('statamic.forms-pro.connections.slack.token')
        ));

        $this->app->bind(TwilioClient::class, fn () => new TwilioClient(
            accountSid: config('statamic.forms-pro.connections.twilio.account_sid'),
            apiKey: config('statamic.forms-pro.connections.twilio.api_key'),
            apiSecret: config('statamic.forms-pro.connections.twilio.api_secret'),
        ));
    }

    private function bootAutomagicForms(): void
    {
        Form::appendConfigFields(
            handles: '*',
            display: 'Automagic Forms',
            fields: [
                'automagic_form' => [
                    'type' => 'toggle',
                    'display' => __('forms-pro::messages.enable_automagic_form'),
                    'instructions' => __('forms-pro::messages.enable_automagic_form_instructions'),
                ],
                'automagic_form_url' => [
                    'type' => 'automagic_form_url',
                    'display' => __('forms-pro::messages.automagic_form_url'),
                    'instructions' => __('forms-pro::messages.automagic_form_url_instructions'),
                    'if' => ['automagic_form' => true],
                ],
                'automagic_form_success_heading' => [
                    'type' => 'text',
                    'display' => __('forms-pro::messages.automagic_form_success_heading'),
                    'instructions' => __('forms-pro::messages.automagic_form_success_heading_instructions'),
                    'placeholder' => __('forms-pro::messages.automagic_form_success_heading_default'),
                    'if' => ['automagic_form' => true],
                ],
                'automagic_form_success_message' => [
                    'type' => 'textarea',
                    'display' => __('forms-pro::messages.automagic_form_success_message'),
                    'instructions' => __('forms-pro::messages.automagic_form_success_message_instructions'),
                    'placeholder' => __('forms-pro::messages.automagic_form_success_message_default'),
                    'if' => ['automagic_form' => true],
                ],
                'automagic_form_show_reset' => [
                    'type' => 'toggle',
                    'display' => __('forms-pro::messages.automagic_form_show_reset'),
                    'instructions' => __('forms-pro::messages.automagic_form_show_reset_instructions'),
                    'if' => ['automagic_form' => true],
                ],
                'automagic_form_reset_label' => [
                    'type' => 'text',
                    'display' => __('forms-pro::messages.automagic_form_reset_label'),
                    'instructions' => __('forms-pro::messages.automagic_form_reset_label_instructions'),
                    'placeholder' => __('forms-pro::messages.automagic_form_reset_label_default'),
                    'if' => ['automagic_form_show_reset' => true],
                ],
            ]
        );

        Link::extend('form', FormLinkType::class);
    }
}
