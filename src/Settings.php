<?php

namespace Statamic\FormsPro;

use Statamic\Facades\AssetContainer;

class Settings
{
    public static function blueprint(): array
    {
        return [
            'tabs' => [
                'automagic_forms' => [
                    'display' => __('forms-pro::messages.automagic_forms'),
                    'sections' => [
                        [
                            'display' => __('forms-pro::messages.appearance'),
                            'instructions' => __('forms-pro::messages.appearance_instructions'),
                            'fields' => [
                                [
                                    'handle' => 'logo',
                                    'field' => [
                                        'type' => 'assets',
                                        'display' => __('forms-pro::messages.appearance_logo'),
                                        'instructions' => __('forms-pro::messages.appearance_logo_instructions'),
                                        'container' => config('forms-pro.automagic_forms.asset_container', AssetContainer::all()->first()?->handle()),
                                        'mode' => 'grid',
                                        'max_files' => 1,
                                        'actions' => false,
                                    ],
                                ],
                                [
                                    'handle' => 'brand_color',
                                    'field' => [
                                        'type' => 'color',
                                        'display' => __('forms-pro::messages.appearance_brand_color'),
                                        'instructions' => __('forms-pro::messages.appearance_brand_color_instructions'),
                                    ],
                                ],
                                [
                                    'handle' => 'background',
                                    'field' => [
                                        'type' => 'automagic_form_background',
                                        'display' => __('forms-pro::messages.appearance_background'),
                                        'instructions' => __('forms-pro::messages.appearance_background_instructions'),
                                        'default' => 'topography',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
