<?php

use Illuminate\Support\Facades\Route;
use Statamic\FormsPro\Http\Controllers\AutomagicFormController;
use Statamic\Http\Middleware\CP\HandleInertiaRequests;

Route::prefix('{form}')
    ->name('forms-pro.form.')
    ->controller(AutomagicFormController::class)
    ->middleware(HandleInertiaRequests::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
    });