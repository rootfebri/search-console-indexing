<?php

namespace App\Http\Controllers;

use App\Models\OAuthModel;
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
            Cache::put($this->credential->project_id . self::DOT_FINISHED, "Autentikasi kode tidak ditemukan", 3600);
            return $this->throwErr('Autentikasi kode tidak ditemukan');
        } elseif (OAuthModel::where('project_id', $this->credential->project_id)->first()) {
            Cache::put($this->credential->project_id . self::DOT_FINISHED, "Autentikasi {$this->credential->project_id} sudah pernah dilakukan", 3600);
            return $this->throwErr("Autentikasi {$this->credential->project_id} sudah pernah dilakukan");
        }

        try {
            $this->__reconstruct($projectId);
            $token = $this->client->getAccessToken();
            $serviceAccount = ServiceAccount::firstOrCreate(['email' => $this->credential->account]);
            $data = [
                'client_id' => $this->credential->client_id,
                'client_secret' => $this->credential->client_secret,
                'project_id' => $this->credential->project_id,
                'refresh_token' => $token['refresh_token'],
                'refresh_time' => time(),
            ];
            $serviceAccount->oauths()->create($data);
        } catch (Exception $exception) {
            Cache::put($this->credential->project_id . self::DOT_FINISHED, $exception->getMessage(), 3600);
            return $this->throwErr($exception->getMessage());
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
