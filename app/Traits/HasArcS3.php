<?php
/** @noinspection PhpUnusedLocalVariableInspection */

namespace App\Traits;

use App\Types\S3ArcType;
use Illuminate\Support\Facades\Cache;
use Throwable;

trait HasArcS3
{
    private const CACHE_ARC_LOCK_TIMEOUT_SEC = 3;
    private function lock(): void
    {
        $key = self::CACHE_WORKER_KEY . ".lock";
        while (true) {
            $lock = Cache::get($key);
            if ($lock === null) {
                break;
            }
        }
        $_ = Cache::put($key, true, $this::CACHE_ARC_LOCK_TIMEOUT_SEC);
    }

    private function unlock(): void
    {
        $key = self::CACHE_WORKER_KEY . ".lock";
        $_ = Cache::pull($key);
    }

    public function create($data): void
    {
        Cache::forever(self::CACHE_WORKER_KEY, $data);
    }

    public function read(bool $count = false): mixed
    {
        $read = Cache::get(self::CACHE_WORKER_KEY) ?? [];
        return $count ? count($read) : $read;
    }

    public function add(array $data): void
    {
        $this->lock();

        $params = $this->read();
        $params[] = $data;
        Cache::forever(self::CACHE_WORKER_KEY, $params);

        $this->unlock();
    }

    public function pullFirst(): array
    {
        $this->lock();
        $params = $this->read();

        try {
            $param = $params[0];
            unset($params[0]);

            Cache::forever($this::CACHE_WORKER_KEY, array_values($params));
        } catch (Throwable) {
            $param = [];
        }

        $this->unlock();
        return $param;
    }
}
