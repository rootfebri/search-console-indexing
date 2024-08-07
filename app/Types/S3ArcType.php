<?php

namespace App\Types;

readonly class S3ArcType
{
    public function __construct(
        public string $Key,
        public string $Body,
        public string $Bucket,
        public string $ContentType,
        public ?string $ACL,
    ){}

    public function toArray(): array
    {
        if ($this->ACL) {
            return [
                'Key' => $this->Key,
                'Body' => $this->Body,
                'Bucket' => $this->Bucket,
                'ContentType' => $this->ContentType,
                'ACL' => $this->ACL,
            ];
        }

        return [
            'Key' => $this->Key,
            'Body' => $this->Body,
            'Bucket' => $this->Bucket,
            'ContentType' => $this->ContentType,
        ];
    }
}
