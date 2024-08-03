<?php

namespace App\Jobs;

use GuzzleHttp\Promise\Promise;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Laravel\Prompts\Progress;

class ProcessUploadS3File implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public Promise &$promise, readonly public string $mainJobId)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->promise->wait();
        Cache::increment($this->mainJobId);
    }
}
