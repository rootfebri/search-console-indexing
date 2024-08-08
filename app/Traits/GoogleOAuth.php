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
    public function setup(OAuthModel $oauth): Google_Client
    {
        $client = new Google_Client();
        $client->setHttpClient(new Request());
        $client->setClientId($oauth->client_id);
        $client->setClientSecret($oauth->client_secret);
        $client->refreshToken($oauth->refresh_token);
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

    private function throwErr(string $pesan, int $http_code = 200): JsonResponse
    {
        Cache::forever($this->credential->project_id . self::DOT_FINISHED, $pesan);
        return response()->json(['success' => false, 'message' => $pesan], $http_code);
    }
}
