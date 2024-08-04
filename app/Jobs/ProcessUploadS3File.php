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

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Credentials $credentials,
        public string      $region,
        array              $params
    )
    {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->Client = new S3Client([
            'region' => $this->region,
            'version' => 'latest',
            'credentials' => $this->credentials
        ]);
        $this->Client->putObject($this->params);
    }
}
