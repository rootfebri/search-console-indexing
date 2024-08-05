<?php

namespace App\Console\Commands;

use App\Models\Indexed;
use App\Models\OAuthModel;
use App\Models\ServiceAccount;
use App\Traits\GoogleOAuth;
use App\Traits\HasConstant;
use Google_Client;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;
use Throwable;

class Indexing extends Command
{
    use GoogleOAuth, HasConstant;

    protected const ENDPOINT_URL_UPDATED = 'https://indexing.googleapis.com/v3/urlNotifications:publish';
    public int $submitted = 0;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'indexing';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start indexing process';

    protected array $urlLists = [];
    protected ?string $sitemap = '';
    protected ServiceAccount $account;
    protected array $content = ['type' => "URL_UPDATED"];
    protected bool $alwaysContinue = true;
    protected Google_Client $google_client;

    public function handle(): void
    {
        if (!$this->extractUrls()) return;
        if (!$this->confirm('Continue indexing?', true)) return;

        $this->line("Indexing started...");
        $this->cleanup();
        $this->selectServiceAccount();

        if ($this->account->google_verifcation) {
            $baseFile = basename($this->sitemap);
            $gVerify = "{$this->account->google_verifcation}.html";
            $linkVerify = str_replace($baseFile, $gVerify, $this->sitemap);
            $areYouOwner = @file_get_contents($linkVerify);
            if (!$areYouOwner && !$this->confirm("$linkVerify not found, continue anyway?")) {
                return;
            }
        }

        $this->runIndexing();
        $this->line("Done!");
    }

    protected function extractUrls(): bool
    {
        while (!str_starts_with($this->sitemap, 'https://')) {
            $this->sitemap = $this->ask("Enter URLs sitemap to be indexed");
            if (strlen($this->sitemap) < 1) {
                $this->line("Invalid URL, e.g., https://example.com/sitemap.xml");
            }
        }

        $urls = @file_get_contents($this->sitemap);
        if (!$urls) {
            $this->line("No sitemap found at " . $this->sitemap);
            return false;
        }

        $this->line("Sitemap found");
        $this->line("Extracting URLs...");

        $xml = simplexml_load_string($urls);
        foreach ($xml->url as $url) {
            $this->urlLists[] = (string)$url->loc;
        }

        $this->line(count($this->urlLists) . " URLs extracted");
        return true;
    }

    protected function cleanup(): void
    {
        $this->line("Cleaning up indexed URLs...");

        $skipThis = Indexed::all()
            ->where('sitemap_url', $this->sitemap)
            ->where('success', true)
            ->filter(fn($res) => -24 < $res->last_update)
            ->pluck('url')
            ->toArray();

        $from = count($this->urlLists);
        $this->urlLists = array_diff($this->urlLists, $skipThis);
        $this->line($from - count($this->urlLists) . " URLs removed after cleanup");
        $this->confirm('Randomize URLs?', true) && shuffle($this->urlLists);
    }

    protected function selectServiceAccount(): void
    {
        $accounts = ServiceAccount::all()->load('oauths');
        $fmt = array_map(function ($email) {
            $limits = ServiceAccount::where('email', $email)?->first()->oauths()->pluck('limit')->toArray();
            $oauthLimit = array_sum($limits);
            return "$email => [limit: $oauthLimit]";
        }, $accounts->pluck('email')->toArray());

        $email = $this->choice("Enter service account ID", $fmt, 0);
        $email = explode(' ', $email)[0];

        if (!$this->account = ServiceAccount::where('email', $email)->first()) {
            $this->error('Something went wrong!');
            exit(1);
        }
    }

    protected function runIndexing(): void
    {
        $oauths = $this->account->oauths()->get();

        foreach ($oauths as $oauth) {
            $oauth->reset();
            $this->processApiKey($oauth);
        }
    }

    protected function processApiKey(OAuthModel $oauth): void
    {
        if (!$oauth->usable()) {
            $this->info("Request limit exceeded for $oauth->project_id");
            return;
        }

        $request = $this->tryAuth($oauth);
        if (!$request) {
            $this->info("[401] Failed to authorize $oauth->project_id");
            return;
        }

        foreach ($this->urlLists as $url) {
            $this->content['url'] = $url;
            unset($this->urlLists[$url]);
            try {
                $status = $this->submit($request);
            } catch (Throwable $e) {
                $this->logError("LINE: $this->submitted | Error occurred while indexing URL: $url", $e->getCode());
                $this->newLine();
                return;
            }
            if ($status === null) {
                return;
            } else {
                $oauth->decrement('limit');
            }

            $status === false && $this->alwaysContinue && $this->alwaysContinue = $this->confirm('Request failed, always ask to continue?', true);
            if (!$oauth->usable()) {
                return;
            }
        }
    }

    private function tryAuth(OAuthModel $oauth, int $retry = 3): Client|ClientInterface|null
    {
        $sleep = 5;
        try {
            $this->google_client = $this->setup($oauth);
            return $this->google_client->authorize();
        } catch (Throwable) {
            if ($retry > 0) {
                sleep($sleep);
                return $this->tryAuth($oauth, $retry - 1);
            } else {
                return null;
            }
        }
    }

    protected function submit(Client|ClientInterface $request): ?bool
    {
        $this->submitted++;
        $code = 0;
        $url = $this->content['url'];
        $indexed = Indexed::firstOrCreate(['url' => $url, 'sitemap_url' => $this->sitemap]);

        try {
            $response = @$request->post(self::ENDPOINT_URL_UPDATED, ['body' => json_encode($this->content)]);
            $code += $response->getStatusCode();
        } catch (GuzzleException|Throwable $e) {
            $this->handleException($e, $indexed, $url);
            return null;
        }

        $this->updateIndexedStatus($indexed, $code, $url);
        return $code >= 200 && $code < 300 ? true : ($code >= 300 && $code < 600 ? false : null);
    }

    protected function handleException(Throwable $e, Indexed $indexed, string $url): void
    {
        $this->logError("LINE: $this->submitted | Error occurred while indexing URL: $url", $e->getCode());
        $indexed->success = false;
        $indexed->save();
        $this->newLine();
    }

    protected function logError(string $message, int $code): void
    {
        $this->info($message);
        $this->info("Error code: $code");
    }

    protected function updateIndexedStatus(Indexed $indexed, int $code, string $url): void
    {
        if ($code >= 200 && $code < 300) {
            $indexed->success = true;
            $this->line("LINE: $this->submitted | [$code] $url -> Request success!");
        } else {
            $indexed->success = false;
            $this->line("LINE: $this->submitted | [$code] $url -> Request failed!");
        }
        $indexed->save();
        $this->newLine();
    }
}