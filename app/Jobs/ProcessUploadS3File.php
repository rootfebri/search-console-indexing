<?php

namespace App\Jobs;

use Aws\Credentials\Credentials;
use Aws\S3\S3Client;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessUploadS3File implements ShouldQueue
{
    use Queueable;

    public S3Client $Client;
    public string $region;
    public Credentials $Credentials;
    public array $params;

    /**
     * Create a new job instance.
     */
    public function __construct(
        Credentials $credentials,
        string      $region,
        array       $params
    )
    {
        $this->Credentials = $credentials;
        $this->region = $region;
        $this->params = $params;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->Client = new S3Client([
            'region' => $this->region,
            'version' => 'latest',
            'credentials' => $this->Credentials
        ]);
        $this->Client->putObject($this->params);
    }
}
