<?php

namespace App\Traits;

trait HasHelper
{
    public function scanJsonDir(?string $at = DIRECTORY_SEPARATOR): array
    {
        if (!str_starts_with($at, '/')) $at = "/$at";
        $base = storage_path('json' . $at);
        $files = scandir($base);
        $jsonFiles = array_filter($files, function ($file) {
            return str_ends_with($file, '.json');
        });

        $anonFn = fn($file) => $base . DIRECTORY_SEPARATOR . basename($file);
        //                     ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^ Expected to be: json/{at}/*.json
        return array_map($anonFn, array_values($jsonFiles));
    }
}
