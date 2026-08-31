<?php

use App\Support\ApiPath;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $channel = config('products.channel');

    return view('playground', [
        'channel' => $channel,
        'apiRoot' => ApiPath::publicPath(),
        'catalogUrl' => ApiPath::publicPath($channel),
    ]);
});
