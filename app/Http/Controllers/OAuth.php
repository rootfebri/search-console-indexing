<?php

namespace App\Http\Controllers;

use App\Models\OAuthModel;
use App\Traits\GoogleOAuth;
use App\Traits\HasHelper;
use App\Types\CredentialType;
use Google_Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class OAuth extends Controller
{
    use GoogleOAuth, HasHelper;

    public function __invoke(Request $request, Google_Client $client, string $projectId)
    {
        /** @var object $credential */
        if (!$credential = Cache::get($projectId)) {
            return $this->throwErr("Cache ($projectId) tidak ditemukan");
        }

        $url = $this->init(new CredentialType($credential->account, $credential->client_id, $credential->project_id, $credential->client_secret), $client)->createAuthUrl();
        return redirect($url);
    }
}
