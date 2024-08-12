<?php

namespace App\Traits;

use App\Models\Site;

trait HasWrapper
{
    public function onSitesUpdate(string $url, bool $status): void
    {
        $site = $this->siteStacks[md5($url)];
        $site->url = $url;
        $site->request_on = time();
        $site->success = $status;
        $site->save();
    }
}
