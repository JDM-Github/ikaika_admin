<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('playground', [
        'channel' => config('products.channel'),
        'catalogUrl' => url('/api/'.config('products.channel')),
    ]);
});
