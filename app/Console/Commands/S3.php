<?php

namespace App\Console\Commands;

use AllowDynamicProperties;
use App\Traits\HasConstant;
use App\Traits\HasHelper;
use App\Traits\S3Helper;
use Aws\Credentials\Credentials;
use Aws\S3\S3Client;
use Exception;
use Illuminate\Console\Command;
use function Laravel\Prompts\select;

#[AllowDynamicProperties] class S3 extends Command
{
    use S3Helper, HasConstant, HasHelper;
    protected $signature = 's3';
    protected $description = 'Command description';

    /**
     * Execute the console command.
     * @throws \Exception
     */
    public function handle(): void
    {
        $this->init();
    }
}