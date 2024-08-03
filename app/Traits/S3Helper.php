<?php

namespace App\Traits;

use Aws\Credentials\Credentials;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Promise;
use Laravel\Prompts\Progress;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\select;

trait S3Helper
{
    public string $cwd = '';
    public string $access_key = '';
    public string $secret_key = '';
    public string $region = '';
    public string $bucket = '';
    public array $buckets = [];
    public bool $stop = false;

    public S3Client $Client;
    public Credentials $Credentials;
    public array $promises = [];

    public function init(): void
    {
        if (config('app.access_key') && config('app.secret_key')) {
            $this->access_key = config('app.access_key');
            $this->secret_key = config('app.secret_key');
        } else {
            $this->changeAccess();
        }

        $this->region = select('Select AWS region', self::S3_REGIONS, 7, 7);

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

    private function putObject(): void
    {
        if (!$this->bucket) {
            $this->selectBucket();
        }
        $isBulkUpload = select('Bulk upload?', ['yes', 'no'], true, 2);
        $isAcl = select('with public ACL?', ['yes', 'no'], 0, 2);
        $this->flushTerminal();
        if ($isBulkUpload === 'yes') {
            $this->bulkUpload($isAcl === 'yes');
        } else {
            $this->upload($isAcl === 'yes');
        }
    }

    private function bulkUpload($isAcl): void
    {
        $dir = $this->selectDir(base_path());
        $choses = ['Upload this folder' => true, 'Files only' => false];
        $selected = $this->choices('Select upload type', array_keys($choses));
        $uploadAsDir = $choses[$selected];

        if ($uploadAsDir) {
            $this->Client->putObject(
                $this->setObjectParams(
                    fullpath: $dir,
                    body: $dir,
                    ACL: $isAcl
                )
            );
            $this->info('Bulk upload completed');
            $this->pause();
            return;
        }
        $files = array_values(array_filter(scandir($dir), fn($name) => !is_dir($dir . DIRECTORY_SEPARATOR . $name) && file_exists($dir . DIRECTORY_SEPARATOR . $name)));
        $files = array_map(fn($file) => $dir . DIRECTORY_SEPARATOR . $file, $files);
        ini_set('memory_limit', "-1");
        /** @var Promise[] $promises */
        $promises = [];
        progress(
            label: 'Uploading files...',
            steps: $files,
            callback: function ($file, Progress $progress) use (&$promises, &$isAcl) {
                if (count($promises) > 1000) {
                    $limit = count($promises);
                    $counter = 1;

                    foreach ($promises as $promise) {
                        $progress
                            ->label("Processing task ($counter/$limit)")
                            ->hint("Max limit reached, uploading... might take a while...");

                        $promise?->wait();
                        $counter++;
                    }
                    $promises = [];
                }
                $promises[] = $this->Client->putObjectAsync(
                    $this->setObjectParams(
                        fullpath: $file,
                        body: @file_get_contents($file),
                        ACL: $isAcl
                    )
                )->then(
                    onFulfilled: fn() => $this->info("Uploaded: " . basename($file)),
                    onRejected: fn($e) => $this->info("Failed: [$file] {$e->getAwsErrorCode()}")
                );
                $progress->label("Queueing: " . basename($file))->hint("This may take a while...");
            },
            hint: 'This may take a while'
        );

        $this->pause();
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
        $this->region = select('Select AWS region', self::S3_REGIONS, 7, 7);
    }
}
