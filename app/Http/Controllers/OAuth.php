<?php

namespace App\Http\Controllers;

use App\Models\OAuthModel;
use App\Traits\GoogleOAuth;
use App\Traits\HasHelper;
use App\Types\CredentialType;
use Google_Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class OAuth extends Controller
{
    use GoogleOAuth, HasHelper;

    public function __invoke(Request $request, Google_Client $client, string $projectId)
    {
        /** @var OAuthModel $credential */
        if (!$credential = Cache::get($projectId)) {
            return response()->json(['error' => 'Credential not found'], 404);
        }

        $url = $this->init($credential, $client)->createAuthUrl();
        return redirect($url);
    }

    private function validateCredential(array $credentials): \Illuminate\Http\JsonResponse|CredentialType
    {
        $validator = Validator::make($credentials, [
            'account' => ['required', 'string'],
            'client_id' => ['required', 'string'],
            'project_id' => ['required', 'string'],
            'client_secret' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        return new CredentialType(...$validator->validated());
    }
}
