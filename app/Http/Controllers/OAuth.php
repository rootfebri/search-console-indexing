<?php

namespace App\Http\Controllers;

use App\Traits\GoogleOAuth;
use App\Traits\HasConstant;
use App\Traits\HasHelper;
use Google_Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class OAuth extends Controller
{
    use GoogleOAuth, HasHelper, HasConstant;

    public function __invoke(Request $request, Google_Client $client, string $projectId)
    {
        if (!$redirect = Cache::get($projectId . '.url')) {
            return $this->throwErr("Cache ($projectId.url) tidak ditemukan");
        }

        if (!Cache::has($projectId)) {
            return $this->throwErr("Cache ($projectId) tidak ditemukan");
        }

        return redirect($redirect);
    }
}
