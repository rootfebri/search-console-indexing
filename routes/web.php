<?php

use App\Http\Controllers\OAuth;
use App\Http\Controllers\OAuthCallback;
use Illuminate\Support\Facades\Route;

Route::get('/oauth/{projectId}', OAuth::class)->name('oauth.index');
Route::any('/oauth/callback/{projectId}', OAuthCallback::class)->name('oauth.callback');
