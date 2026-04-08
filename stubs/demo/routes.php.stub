<?php

use App\Livewire\Login;
use App\Livewire\Trail;
use App\Livewire\Lesson;
use App\Livewire\Rank;
use App\Livewire\Profile;
use Illuminate\Support\Facades\Route;

Route::get('/login', Login::class);

Route::middleware('nb.auth')->group(function () {
    Route::get('/', Trail::class);
    Route::get('/lesson/{id}', Lesson::class);
    Route::get('/rank', Rank::class);
    Route::get('/profile', Profile::class);
});
