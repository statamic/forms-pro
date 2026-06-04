<?php

use Illuminate\Support\Facades\Route;
use Statamic\FormsPro\Http\Controllers\FormController;
use Statamic\Http\Middleware\CP\HandleInertiaRequests;

Route::get('/forms/{form}', [FormController::class, 'index'])
    ->middleware(HandleInertiaRequests::class)
    ->name('forms-pro.form');