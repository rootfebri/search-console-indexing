<?php

use App\Http\Controllers\OAuth;
use App\Http\Controllers\OAuthCallback;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::fallback(function (Request $request) {
    file_put_contents(storage_path('app/fallback.txt'), json_encode($request) . "\n", FILE_APPEND);
});

Route::get('test', function () {
    $query = file_get_contents(storage_path('json/danielsoosai14/client_secret_109266973873-4m6p4o6pt8r5clibkf8u58b1esrd1o33.apps.googleusercontent.com.json'));
    $query = json_decode($query, true);

    return redirect()->route('oauth.index', [
        'account' => 'julianfebririswanto',
        ...$query['installed']
    ]);
});

Route::get('/oauth/{projectId}', OAuth::class)->name('oauth.index');
Route::any('/oauth/callback/{projectId}', OAuthCallback::class)->name('oauth.callback');
