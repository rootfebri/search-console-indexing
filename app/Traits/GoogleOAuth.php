<?php

namespace App\Traits;

use App\Models\OAuthModel;
use App\Types\CredentialType;
use Google_Client;
use GuzzleHttp\Client as Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

trait GoogleOAuth
{
    public function indexer(Google_Client $client): Google_Client
    {
        $client->setHttpClient(new Request());
        $client->setClientId("");
        $client->setClientSecret("");
        $client->refreshToken("");
        $client->addScope('https://www.googleapis.com/auth/indexing');

        return $client;
    }

    private function init(CredentialType $credential, Google_Client $google_client): Google_Client
    {
        $google_client->setHttpClient(new Request());
        $google_client->setClientId($credential->client_id);
        $google_client->setClientSecret($credential->client_secret);
        $google_client->addScope($credential->scope);
        $google_client->setRedirectUri(route('oauth.callback', $credential->project_id));
        $google_client->setAccessType('offline');
        $google_client->setPrompt('consent');
        $google_client->setIncludeGrantedScopes(true);

        return $google_client;
    }

    private function throwErr(string $pesan, int $http_code = 400): JsonResponse
    {
        Cache::forever($this->credential->project_id . self::DOT_FINISHED, $pesan);
        return response()->json(['success' => false, 'message' => $pesan], $http_code);
    }
}
