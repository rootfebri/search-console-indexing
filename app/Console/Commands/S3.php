<?php

namespace App\Console\Commands;

use App\Traits\HasArcS3;
use App\Traits\HasConstant;
use App\Traits\HasHelper;
use App\Traits\S3Helper;
use Illuminate\Console\Command;

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
        $this->init();
    }
}
