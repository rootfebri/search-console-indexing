<?php

namespace App\Types;

readonly class CredentialType
{
    public function __construct(
        public string $account,
        public string $client_id,
        public string $project_id,
        public string $client_secret,
        public string $scope = 'https://www.googleapis.com/auth/indexing'
    ){}

    public function toArray(): array
    {
        return [
            'account' => $this->account,
            'client_id' => $this->client_id,
            'project_id' => $this->project_id,
            'client_secret' => $this->client_secret,
        ];
    }
}
