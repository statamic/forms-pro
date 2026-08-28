<?php

use Illuminate\Support\Facades\Route;
use Statamic\Facades\Site;
use Statamic\Facades\URL;
use Statamic\FormsPro\AutomagicForms\AutomagicForms;
use Statamic\FormsPro\Http\Controllers\AutomagicFormController;
use Statamic\FormsPro\Http\Middleware\HandleInertiaRequests;
use Statamic\Http\Middleware\HandleFormPrecognitiveRequests;

if (! AutomagicForms::enabled()) {
    return;
}

$hosts = Site::all()->map(fn ($site) => parse_url($site->url(), PHP_URL_HOST))->filter()->unique();

Site::all()->each(function (Statamic\Sites\Site $site) use ($hosts): void {
    $domain = $hosts->count() > 1 ? parse_url($site->url(), PHP_URL_HOST) : null;

    $prefix = collect([
        trim(URL::makeRelative($site->url()), '/'),
        config('forms-pro.automagic_forms.route'),
        '{form}',
    ])->filter()->implode('/');

    Route::domain($domain)
        ->prefix($prefix)
        ->name("statamic.forms-pro.form.{$site->handle()}.")
        ->controller(AutomagicFormController::class)
        ->middleware(HandleInertiaRequests::class)
        ->group(function () {
            Route::get('/success', 'submitted')->name('success');
            Route::get('/restricted', 'restricted')->name('restricted');
            Route::get('/{page?}', 'index')->name('index');
            Route::post('/{page}', 'store')->middleware([HandleFormPrecognitiveRequests::class, 'throttle:statamic.forms'])->name('store');
        });
});
