<?php

namespace Statamic\FormsPro;

use Statamic\Facades\Form;
use Statamic\Fieldtypes\Link;
use Statamic\FormsPro\AutomagicForms\AutomagicForms;
use Statamic\FormsPro\AutomagicForms\FormLinkType;
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

        if (AutomagicForms::enabled()) {
            $this->registerSettingsBlueprint(fn () => Settings::blueprint());

            $this->bootAutomagicForms();
        }
    }

    private function bootAutomagicForms(): void
    {
        Form::appendConfigFields(
            handles: '*',
            display: 'Automagic Forms',
            afterSection: 'name',
            fields: [
                'automagic_form' => [
                    'type' => 'toggle',
                    'display' => __('forms-pro::messages.enable_automagic_form'),
                    'instructions' => __('forms-pro::messages.enable_automagic_form_instructions'),
                    'validate' => ['new \\Statamic\\FormsPro\\Rules\\NotWithUniqueInstances'],
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
