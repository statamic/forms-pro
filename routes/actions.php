<?php

use Illuminate\Support\Facades\Route;
use Statamic\FormsPro\Http\Controllers\AutomagicFormController;
use Statamic\FormsPro\Http\Middleware\HandleInertiaRequests;

Route::prefix('{form}')
    ->name('forms-pro.form.')
    ->controller(AutomagicFormController::class)
    ->middleware(HandleInertiaRequests::class)
    ->group(function () {
        Route::get('/{page?}', 'index')->name('index');
        Route::post('/{page}', 'store')->name('store');
    });