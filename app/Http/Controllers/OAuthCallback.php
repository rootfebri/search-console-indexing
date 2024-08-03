<?php

namespace App\Http\Controllers;

use App\Models\OAuthModel;
use App\Models\ServiceAccount;
use App\Traits\GoogleOAuth;
use App\Traits\HasHelper;
use App\Types\CredentialType;
use Google_Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class OAuthCallback extends Controller
{
    use GoogleOAuth;
    protected ?CredentialType $credential;

    public function __construct(protected Google_Client $client){}

    public function __reconstruct(string $projectId)
    {
        if (!$this->credential = Cache::pull($projectId)) {
            Cache::forever($this->credential->project_id, 'failed');
        }
        $this->credential = Cache::pull($projectId);
        $this->client = $this->init($this->credential, $this->client);
    }

    public function __invoke(Request $request, string $projectId)
    {
        if (empty($request->code)) {
            return response()->json('No code provided', 400);
        } else {
            $this->__reconstruct($projectId);
            Cache::forever('authentication', $this->client->fetchAccessTokenWithAuthCode($request->code));
        }

        $token = $this->client->getAccessToken();
        if (!isset($token['refresh_token'])) {
            Cache::forever($this->credential->project_id, 'failed');
            return response()->json(['success' => false, 'message' => 'Refresh token not found'], 400);
        }

        $serviceAccount = ServiceAccount::firstOrCreate(['email' => $this->credential->account]);
        $serviceAccount->oauths()->create([
            'client_id' => $this->credential->client_id,
            'client_secret' => $this->credential->client_secret,
            'project_id' => $this->credential->project_id,
            'refresh_token' => $token['refresh_token'],
        ]);

        $status = ['success' => true];
        Cache::put($this->credential->project_id, $status);
        return response()->json($status, 201);
    }
}
