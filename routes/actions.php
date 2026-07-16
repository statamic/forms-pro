<?php

use Illuminate\Support\Facades\Route;
use Statamic\FormsPro\Http\Controllers\AutomagicFormController;
use Statamic\FormsPro\Http\Middleware\HandleInertiaRequests;
use Statamic\Http\Middleware\HandleFormPrecognitiveRequests;

Route::prefix('{form}')
    ->name('forms-pro.form.')
    ->controller(AutomagicFormController::class)
    ->middleware(HandleInertiaRequests::class)
    ->group(function () {
        Route::get('/success', 'submitted')->name('success');
        Route::get('/{page?}', 'index')->name('index');
        Route::post('/{page}', 'store')->middleware(HandleFormPrecognitiveRequests::class)->name('store');
    });
