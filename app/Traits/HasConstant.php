<?php

namespace App\Traits;

trait HasConstant
{
    public const MAX_INDEXING = 200;
    public const DOT_FINISHED = '.finished';
    public const S3_REGIONS = [
        'ap-southeast-1',
        'ap-southeast-2',
        'ap-southeast-3',
        'ap-southeast-4',
        'af-south-1',
        'ap-east-1',
        'ap-northeast-1',
        'ap-northeast-2',
        'ap-northeast-3',
        'ap-south-1',
        'ap-south-2',
        'ca-central-1',
        'ca-west-1',
        'eu-central-1',
        'eu-central-2',
        'eu-north-1',
        'eu-south-1',
        'eu-south-2',
        'eu-west-1',
        'eu-west-2',
        'eu-west-3',
        'il-central-1',
        'me-central-1',
        'me-south-1',
        'sa-east-1',
        'us-east-1',
        'us-east-2',
        'us-west-1',
        'us-west-2',
    ];
    public const S3_LOBBY = [
        'Create Bucket' => 'createBucket',
        'Change Access Key' => 'changeAccess',
        'Delete Bucket' => 'deleteBucket',
        'Delete Object' => 'deleteObject',
        'List Buckets' => 'listBuckets',
        'List Objects' => 'listObjects',
        'Select Region' => 'selectRegion',
        'Select Bucket' => 'selectBucket',
        'Upload' => 'putObject',
        'Quit' => 'quit',
    ];
    public const ACL_POLICY = [
        'ACL' => 'private',
        'ObjectOwnership' => 'BucketOwnerPreferred',
    ];
    public const PUBLIC_ACCESS_BLOCK = [
        'BlockPublicAcls' => false,
        'BlockPublicPolicy' => false,
        'IgnorePublicAcls' => false,
        'RestrictPublicBuckets' => false,
    ];
    public const S3_UPLOAD_MODES = [
        'Select file to upload' => 'singleUpload',
//        'Select files to upload' => 'multipleUpload',
        'Select folder to upload' => 'directoryUpload',
    ];
}
