<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['app' => 'Niche Radar', 'status' => 'ok']))->name('index');
