<?php

namespace Statamic\FormsPro;

use Statamic\Facades\Form;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
        'hotFile' => __DIR__.'/../resources/dist/hot',
    ];

    public function bootAddon()
    {
        $this->publishes([
            __DIR__.'/../resources/dist-frontend' => public_path('vendor/forms-pro/frontend'),
        ], 'forms-pro-frontend');

        Form::appendConfigFields(
            handles: '*',
            display: 'Automagic Forms',
            fields: [
                'automagic_form' => [
                    'type' => 'toggle',
                    'display' => __('forms-pro::messages.automagic_form'),
                    'instructions' => __('forms-pro::messages.automagic_form_instructions'),
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
    }
}
