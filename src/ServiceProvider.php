<?php

namespace Statamic\FormsPro;

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
    }
}
