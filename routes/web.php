<?php

use App\Support\ApiPath;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('playground', ApiPath::pageBootstrap((string) config('products.channel')));
});
