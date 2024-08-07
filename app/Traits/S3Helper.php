<?php
/** @noinspection PhpUnusedPrivateMethodInspection */

namespace App\Traits;

use App\Jobs\ProcessUploadS3File;
use Aws\Credentials\Credentials;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Cache;
use Laravel\Prompts\Progress;
use Throwable;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\select;

trait S3Helper
{
    public mixed $startTime;
    public string $access_key = '';
    public string $secret_key = '';
    public string $region = '';
    public string $bucket = '';
    public array $buckets = [];
    public bool $stop = false;

    public S3Client $Client;
    public Credentials $Credentials;
    public array $promises = [];
    public Progress $progress;

    public function init(): void
    {
        if (config('app.access_key') && config('app.secret_key')) {
            $this->access_key = config('app.access_key');
            $this->secret_key = config('app.secret_key');
        } else {
            $this->changeAccess();
        }

        $this->region = select('Select AWS region', self::S3_REGIONS, 0, 7);

        $this->Credentials = new Credentials($this->access_key, $this->secret_key);
        $this->Client = new S3Client([
            'region' => $this->region,
            'version' => 'latest',
            'credentials' => $this->Credentials
        ]);

        $this->lobby();
    }

    private function changeAccess(): void
    {
        while (empty($this->access_key)) {
            $this->access_key = $this->ask('Enter your AWS Access Key', '');
        }
        while (empty($this->secret_key)) {
            $this->secret_key = $this->ask('Enter your AWS Secret Key', '');
        }
    }

    private function lobby(): void
    {
        while (!$this->stop) {
            $action = select('Select actions', array_keys(self::S3_LOBBY), 0, 4);
            if ($action !== 'Quit') {
                $this->{self::S3_LOBBY[$action]}();
            } else {
                $this->stop = true;
            }
        }
        $this->info('Exiting...');
    }

    private function createBucket(): void
    {
        $this->flushTerminal();
        $this->bucket = '';
        while (empty($this->bucket)) {
            $this->bucket = $this->ask('Enter bucket name', '');
            if (empty($this->bucket)) {
                $this->flushTerminal();
            }
        }

        try {
            $acl = $this->confirmation('Public With ACL policy?');
            if ($acl) {
                $result = $this->Client->createBucket(['Bucket' => $this->bucket, ...self::ACL_POLICY]);
                $this->Client->putPublicAccessBlock(['Bucket' => $this->bucket, 'PublicAccessBlockConfiguration' => self::PUBLIC_ACCESS_BLOCK]);
            } else {
                $result = $this->Client->createBucket(['Bucket' => $this->bucket]);
            }
            $code = $result['@metadata']['statusCode'];

            if ($code >= 200 && $code <= 299) {
                $this->buckets[] = $this->bucket;
                $this->info('Bucket created successfully.');
            } else {
                $this->info('Failed to create the bucket. Please check your AWS credentials and try again.');
            }
        } catch (S3Exception $e) {
            $this->info('Error occurred: ' . $e->getAwsErrorCode());
        }
    }

    private function deleteBucket(): void
    {
        $this->flushTerminal();
        $this->selectBucket();
        try {
            $this->Client->deleteBucket(['Bucket' => $this->bucket]);
            unset($this->buckets[$this->bucket]);
            $this->info('Bucket deleted successfully');
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() === 'BucketNotEmpty' && confirm('Empty this bucket?')) {
                $objects = $this->Client->listObjects(['Bucket' => $this->bucket, 'Delimiter' => '']);
                $objects = array_map(fn($object) => ['Key' => $object['Key']], $objects['Contents']);
                print_r($objects);
                $this->Client->deleteObjects([
                    'Bucket' => $this->bucket,
                    'Delete' => [
                        'Objects' => [
                            $objects
                        ],
                        'Quiet' => false,
                    ],
                ]);
            }
        }
    }

    private function selectBucket(): void
    {
        $this->flushTerminal();
        if (empty($this->buckets)) {
            $this->listBuckets(true);
        }
        $this->bucket = select('Select S3 Bucket', $this->buckets, 0, 5);
    }

    private function listBuckets(bool $asLoader = false): void
    {
        $this->flushTerminal();
        $buckets = $this->Client->listBuckets()['Buckets'] ?? [];
        if (count($this->buckets) < 1) {
            $this->buckets = array_map(fn($bucket) => $bucket['Name'], $buckets);
        } else {
            array_walk($buckets, fn($bucket) => $this->buckets[] = $bucket['Name']);
        }

        if (!$asLoader) {
            if (empty($this->buckets)) {
                $this->info('No S3 buckets found. Please create a bucket first.');
            } else {
                $this->info('S3 Buckets:');
                $indexes = array_keys($this->buckets);
                array_walk($indexes, fn($index) => $this->line("[$index] {$this->buckets[$index]}"));
            }
        }
    }

    private function listObjects(): void
    {
        $this->flushTerminal();
        try {
            $result = $this->Client->listObjects([
                'Bucket' => $this->bucket,
            ]);
            dd($result);
        } catch (S3Exception $e) {
            $this->info('Failed to delete the bucket. ' . $e->getAwsErrorCode());
        }
    }

    private function directoryUpload($isAcl): void
    {
        ini_set('memory_limit', "-1");
        $pathToDir = $this->selectDir(base_path());

        $files = array_values(array_filter(scandir($pathToDir), fn($name) => !is_dir($pathToDir . DIRECTORY_SEPARATOR . $name) && file_exists($pathToDir . DIRECTORY_SEPARATOR . $name)));
        $files = array_map(fn($file) => $pathToDir . DIRECTORY_SEPARATOR . $file, $files);
        $totalFiles = count($files);

        $this->progress = progress(
            label: 'Uploading files...',
            steps: $files,
        );

        $useConcurrent = null;
        while (!is_int($useConcurrent) || $useConcurrent > 512 || $useConcurrent < 0) {
            $useConcurrent = (int)$this->ask('Use batch worker uploads? (type 0-512) [0|1 = sync upload]');
        }

        $this->progress->start();
        $this->create([]);

        array_walk($files, function ($file) use ($pathToDir, &$totalFiles, &$isAcl, &$useConcurrent) {
            if ($useConcurrent < 1) {
                $this->syncUpload($file, $totalFiles, $isAcl);
            } else {
                $this->asyncUpload($file, $totalFiles, $isAcl, $useConcurrent, $pathToDir);
            }
        });

        Cache::pull($this::CACHE_WORKER_KEY);
        $this->progress->finish();
        $this->pause();
    }

    private function syncUpload($file, $totalFiles, $isAcl): void
    {
        $this->progress->label("Uploading " . basename($file))->hint("Estimated time: " . $this->calculateTime($totalFiles, $this->progress->progress));

        try {
            $this->Client->putObject($this->setObjectParams(fullpath: $file, body: @file_get_contents($file), ACL: $isAcl));
            $this->progress->label("Uploading " . basename($file) . " => success")->hint("Estimated time: " . $this->calculateTime($totalFiles, $this->progress->progress));
        } catch (Throwable $e) {
            $this->progress->label("Uploading " . basename($file) . " => failed")->hint("Estimated time: " . $this->calculateTime($totalFiles, $this->progress->progress) . "\n {$e->getMessage()}");
        }

        $this->progress->advance();
    }

    private function putObject(): void
    {
        if (!$this->bucket) {
            $this->selectBucket();
        }

        $isAcl = select('with public ACL?', ['yes', 'no'], 0, 2);
        $uploadType = select('Upload Type', array_keys(self::S3_UPLOAD_MODES), 0);

        $this->startTime = microtime(true);
        $this->{self::S3_UPLOAD_MODES[$uploadType]}($isAcl);
    }

    private function asyncUpload($file, $totalFiles, $isAcl, int $useConcurrent, $pathToDir): void
    {
        $param = $this->setObjectParams(
            fullpath: $file,
            body: @file_get_contents($file) ?? '',
            ACL: $isAcl,
            initialPath: $pathToDir
        );

        $this->add($param);
        $this->progress
            ->advance();
        $this->progress
            ->label("Queuing " . basename($file))
            ->hint($this->est($totalFiles))
            ->render();

        // Wait for the queue to match the limit
        if ((int)$this->read(true) === $useConcurrent) {
            while (true) {
                if ($this->read(true) > 0) {
                    // limit the loop to prevent high CPU usage for 1ms
                    usleep(microseconds: 1000);
                    ProcessUploadS3File::dispatch($this->Credentials, $this->region);
                    $this->progress
                        ->label("Upload in progress...  | " . (int)$this->read(true))
                        ->hint($this->est($totalFiles))
                        ->render();
                    continue;
                }
                break;
            }
        }
    }

    private function est(int $total): string
    {
        return "Est to complete: " . $this->calculateTime($total, $this->progress->progress);
    }

    private function singleUpload($isAcl): void
    {
        $this->upload($isAcl);
    }

    private function upload($isAcl): void
    {
        $this->flushTerminal();
        $file = $this->selectFile(base_path());
        $this->Client->putObject(
            $this->setObjectParams(
                fullpath: $file,
                body: @file_get_contents($file),
                ACL: $isAcl
            )
        );
    }

    private function deleteObject(): void
    {
        $this->flushTerminal();
        $this->info('TODO: Delete an object from the bucket');
    }

    private function selectRegion(): void
    {
        $this->flushTerminal();
        $this->region = select('Select AWS region', self::S3_REGIONS, 0, 7);
    }
}
