<?php

namespace App\Traits;

use App\Models\OAuthModel;
use Google_Client;
use GuzzleHttp\Client as Request;

trait GoogleOAuth
{
    public function indexer(Google_Client $client): Google_Client
    {
        $client->setHttpClient(new Request());
        $client->setClientId("109266973873-4m6p4o6pt8r5clibkf8u58b1esrd1o33.apps.googleusercontent.com");
        $client->setClientSecret("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");
        $client->refreshToken("1//0gUwk8qAoQGweCgYIARAAGBASNwF-L9IrIefzfFcNKNNtHAlH4ZpZHXb5TYrQTN-Z0KNJwqXjemyr3b4rIJyhsAYYGP1fD8JKzaE");
        $client->addScope('https://www.googleapis.com/auth/indexing');
        return $client;
    }

    private function init(OAuthModel $credential, Google_Client $google_client): Google_Client
    {
        $google_client->setHttpClient(new Request());
        $google_client->setClientId($credential->client_id);
        $google_client->setClientSecret($credential->client_secret);
        $google_client->addScope('https://www.googleapis.com/auth/indexing');
        $google_client->setRedirectUri(route('oauth.callback', $credential->project_id));
        $google_client->setAccessType('offline');
        $google_client->setPrompt('consent');
        $google_client->setIncludeGrantedScopes(true);

        return $google_client;
    }
}
