<?php

use Illuminate\Support\Facades\Route;
use NickWelsh\LaravelZero\Http\ZeroMutateEndpoint;
use NickWelsh\LaravelZero\Http\ZeroQueryEndpoint;

Route::prefix(config('laravel-zero.routes.prefix', 'zero'))
    ->middleware(config('laravel-zero.routes.middleware', []))
    ->group(function (): void {
        Route::post('query', ZeroQueryEndpoint::class)->name('zero.query');
        Route::post('mutate', ZeroMutateEndpoint::class)->name('zero.mutate');
    });
