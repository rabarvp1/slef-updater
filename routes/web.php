<?php

use Illuminate\Support\Facades\Route;

Route::get('rabar', function () {
    return view('self-updater::welcome');
});
