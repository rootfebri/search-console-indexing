<?php

namespace App\Jobs;

use GuzzleHttp\Promise\Promise;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Prompts\Progress;

class ProcessUploadS3File implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(Promise &$promise, Progress &$progress, int &$counter, int $totalPromises, string $fileName)
    {
        $hint = $promise->wait();
        $progress->label("Uploading: $counter/$totalPromises")
            ->hint(is_string($hint) ? $hint : $fileName . " completed");
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
    }
}
