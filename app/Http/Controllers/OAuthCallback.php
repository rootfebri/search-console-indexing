<?php

namespace App\Http\Controllers;

use App\Models\ServiceAccount;
use App\Traits\GoogleOAuth;
use App\Traits\HasConstant;
use App\Types\CredentialType;
use Exception;
use Google_Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class OAuthCallback extends Controller
{
    use GoogleOAuth, HasConstant;

    protected ?CredentialType $credential;

    public function __construct(protected Google_Client $client)
    {
    }

    public function __invoke(Request $request, string $projectId)
    {
        if (empty($request->code)) {
            return $this->throwErr('Autentikasi kode tidak ditemukan');
        } else {
            try {
                $this->__reconstruct($projectId);
            } catch (Exception $exception) {
                return $this->throwErr($exception->getMessage());
            }
            if (config('app.env') !== 'production') Cache::forever('authentication', $this->client->fetchAccessTokenWithAuthCode($request->code));
        }

        $token = $this->client->getAccessToken();

        $serviceAccount = ServiceAccount::firstOrCreate(['email' => $this->credential->account]);
        try {
            $data = [
                'client_id' => $this->credential->client_id,
                'client_secret' => $this->credential->client_secret,
                'project_id' => $this->credential->project_id,
            ];

            $serviceAccount->oauths()->updateOrCreate($data, [
                'refresh_token' => $token['refresh_token'],
            ]);
        } catch (Exception $e) {
            return $this->throwErr($e->getMessage());
        }

        Cache::put($this->credential->project_id . self::DOT_FINISHED, "Autentikasi {$this->credential->project_id} berhasil, yey!");
        return response()->json(['success' => true], 201);
    }

    /**
     * @throws Exception
     */
    public function __reconstruct(string $projectId)
    {
        if (!$credential = Cache::pull($projectId)) {
            throw new Exception("Cache ($projectId) tidak ditemukan");
        } else {
            $this->credential = new CredentialType(
                account: $credential->account,
                client_id: $credential->client_id,
                project_id: $credential->project_id,
                client_secret: $credential->client_secret,
            );
        }
        $this->client = $this->init($this->credential, $this->client);
    }
}
