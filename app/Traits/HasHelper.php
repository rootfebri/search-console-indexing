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
    public function scanJsonDir(): array
    {
        $jsonFiles = array_filter(scandir($this->path), fn ($file) => str_ends_with($file, '.json'));

        return array_map(fn($fileName) => $this->path . DIRECTORY_SEPARATOR . basename($fileName), array_values($jsonFiles));
        //                                ^^^^^^^^^^^^^^^^^^^^^^^^^^^ Expected to be: json/{at}|{email}|{username}/*.json
    }

    protected function scandir(string $path): array
    {
        return array_values(array_filter(scandir($path), fn($file) => is_dir($path . DIRECTORY_SEPARATOR . $file)));
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

    protected function flushTerminal(): void
    {
        if (str_starts_with(strtolower(PHP_OS), 'win')) {
            echo chr(27) . chr(91) . 'H' . chr(27) . chr(91) . 'J';
        } else {
            system('clear');
        }
    }
}
