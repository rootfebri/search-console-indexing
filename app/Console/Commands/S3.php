<?php

namespace App\Console\Commands;

use AllowDynamicProperties;
use App\Traits\HasArcS3;
use App\Traits\HasConstant;
use App\Traits\HasHelper;
use App\Traits\S3Helper;
use Aws\Credentials\Credentials;
use Aws\S3\S3Client;
use Exception;
use Illuminate\Console\Command;
use function Laravel\Prompts\select;

class S3 extends Command
{
    use S3Helper, HasConstant, HasHelper, HasArcS3;
    protected $signature = 's3';
    protected $description = 'Command description';

    /**
     * Execute the console command.
     * @throws \Exception
     */
    public function handle(): void
    {
        $this->WIN = isset($_SERVER['OS']) && str_starts_with(strtolower($_SERVER['OS']), 'win');
        $this->init();
    }
}
