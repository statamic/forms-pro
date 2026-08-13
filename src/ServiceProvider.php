<?php

namespace Statamic\FormsPro;

use Illuminate\Cookie\Middleware\EncryptCookies;
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
    protected $vite = [
        'input' => [
            'resources/js/cp.js',
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
    }

    private function bootConnections(): void
    {
        $this->app->bind(GoogleSheetsClient::class, fn () => new GoogleSheetsClient(
            config('forms-pro.connections.google_sheets.credentials')
        ));

        EncryptCookies::except('hubspotutk');

        $this->app->bind(HubSpotClient::class, fn () => new HubSpotClient(
            config('forms-pro.connections.hubspot.access_token')
        ));

        $this->app->bind(KitClient::class, fn () => new KitClient(
            config('forms-pro.connections.kit.api_key')
        ));

        $this->app->bind(MailchimpClient::class, fn () => new MailchimpClient(
            config('forms-pro.connections.mailchimp.api_key')
        ));

        $this->app->bind(MailcoachClient::class, fn () => new MailcoachClient(
            apiUrl: config('forms-pro.connections.mailcoach.api_url'),
            token: config('forms-pro.connections.mailcoach.token'),
        ));

        $this->app->bind(SlackClient::class, fn () => new SlackClient(
            config('forms-pro.connections.slack.token')
        ));

        $this->app->bind(TwilioClient::class, fn () => new TwilioClient(
            accountSid: config('forms-pro.connections.twilio.account_sid'),
            apiKey: config('forms-pro.connections.twilio.api_key'),
            apiSecret: config('forms-pro.connections.twilio.api_secret'),
        ));
    }
}
