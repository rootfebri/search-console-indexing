<?php

namespace App\Jobs;

use App\Traits\HasArcS3;
use App\Traits\HasConstant;
use App\Types\S3ArcType;
use Aws\Credentials\Credentials;
use Aws\S3\S3Client;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessUploadS3File implements ShouldQueue
{
    use Queueable, HasArcS3, HasConstant;

    public S3Client $Client;
    public string $region;
    public Credentials $Credentials;
    public array $params;

    public function __construct(
        Credentials $credentials,
        string      $region,
    )
    {
        $this->Credentials = $credentials;
        $this->region = $region;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $params = $this->pullFirst();
        if (count($params) < 1) {
            return;
        }

        $this->Client = new S3Client([
            'region' => $this->region,
            'version' => 'latest',
            'credentials' => $this->Credentials
        ]);

        try {
            $this->Client->putObject($params);
        } catch (Throwable $e) {
            @file_put_contents(storage_path('logs/aws_upload_job.log'), "{$params['Key']} => {$e->getMessage()}" . "\n", FILE_APPEND);
        }
    }
}
