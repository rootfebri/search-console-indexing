<?php

namespace App\Jobs;

use Aws\S3\S3Client;
use GuzzleHttp\Promise\Promise;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Laravel\Prompts\Progress;

class ProcessUploadS3File implements ShouldQueue
{
    use Queueable;
    public S3Client $client;

    /**
     * Create a new job instance.
     */
    public function __construct(public mixed $cacheId, array $params)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->client = Cache::pull($this->cacheId);
        $this->client->putObject($this->params);
    }
}
