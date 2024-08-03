<?php

namespace App\Traits;

use App\Types\CredentialType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

trait HasHelper
{
    /**
     * @param string|null $at
     * @return string[]
     */
    public function scanJsonDir(?string $at = null): array
    {
        $base = storage_path('json' . $at ? trim(DIRECTORY_SEPARATOR . $at . DIRECTORY_SEPARATOR) : DIRECTORY_SEPARATOR);
        if ($at && !is_dir($base)) {
            try {
                $base = storage_path('json' . DIRECTORY_SEPARATOR . explode('@', $at)[0] . DIRECTORY_SEPARATOR);
                if (!is_dir($base)) {
                    $base = storage_path('json' . DIRECTORY_SEPARATOR . $at . DIRECTORY_SEPARATOR);
                }
            } catch (\Exception) {
                $base = storage_path('json' . DIRECTORY_SEPARATOR . $at . DIRECTORY_SEPARATOR);
            }
        }

        $jsonFiles = array_filter(scandir($base), fn ($file) => str_ends_with($file, '.json'));

        return array_map(fn($fileName) => $base . basename($fileName), array_values($jsonFiles));
        //                                ^^^^^^^^^^^^^^^^^^^^^^^^^^^ Expected to be: json/{at}|{email}|{username}/*.json
    }

    protected function validateEmail(string $email): bool
    {
        switch (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            case true:
                return true;
            case false:
                ($email !== '*NEW*' && $this->line('Email tidak valid..'));
                return false;
            default:
                $this->line('ERROR');
                return false;
        }
    }

    private function validate(Request $request, array $rules = []): JsonResponse|array
    {
        $status = Validator::make($request->all(), $rules);
        if ($status->fails()) {
            return response()->json($status->messages()->toArray(), 422);
        }
        return $status->validated();
    }
}
