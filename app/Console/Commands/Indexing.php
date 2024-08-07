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
use Laravel\Prompts\Progress;
use Throwable;
use function Laravel\Prompts\progress;

class Indexing extends Command
{
    use GoogleOAuth, HasConstant;

    protected const ENDPOINT_URL_UPDATED = 'https://indexing.googleapis.com/v3/urlNotifications:publish';
    public int $submitted = 0;
    protected $signature = 'indexing';
    protected $description = 'Start indexing process';
    protected array $urlLists = [];
    protected ?string $sitemap = '';
    protected ServiceAccount $account;
    protected array $content = ['type' => "URL_UPDATED"];
    protected bool $alwaysContinue = true;
    protected Google_Client $google_client;
    protected Progress $progress;

    public function handle(): void
    {
        if (!$this->extractUrls()) return;
        if (!$this->confirm('Continue indexing?', true)) return;

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

        foreach ($accounts as $account) {
            /** @var OAuthModel $oauth */
            foreach ($account->oauths() as $oauth) {
                $oauth->reset();
                $oauth->refresh();
            }
        }

        $accounts = ServiceAccount::all()->load('oauths');
        $fmt = array_map(function ($email) {
            $limits = ServiceAccount::where('email', $email)?->first()->oauths()->pluck('limit')->toArray();
            $oauthLimit = array_sum($limits);
            return "$email => [limit: $oauthLimit]";
        }, $accounts->pluck('email')->toArray());

        $email = $this->choice("Enter service account ID", $fmt, 0);
        $email = explode(' ', $email)[0];

        $this->progress = progress(
            label: 'Starting indexing...");',
            steps: $this->urlLists,
            hint: 'This might take a while.'
        );

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
            $this->progress->hint("Request limit exceeded for $oauth->project_id");
            return;
        }

        $request = $this->tryAuth($oauth);
        if (!$request) {
            $this->progress->hint("Failed to authorize $oauth->project_id");
            return;
        }

        foreach ($this->urlLists as $url) {
            $this->content['url'] = $url;
            unset($this->urlLists[$url]);
            $this->progress->advance();
            try {
                $status = $this->submit($request);
            } catch (Throwable $e) {
                $this->logError("LINE: $this->submitted | Error occurred while indexing URL: $url", $e->getCode());
                return;
            }
            if ($status === null) {
                return;
            } else {
                $oauth->decrement('limit');
                $oauth->refresh();
            }

            $status === false && $this->alwaysContinue && $this->alwaysContinue = $this->confirm('Request failed, always ask to continue?', true);
            if (!$oauth->usable()) {
                $this->progress->hint("Request limit exceeded for $oauth->project_id");
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
        $indexed = Indexed::create(['url' => $url, 'sitemap_url' => $this->sitemap]);

        try {
            $response = @$request->post(self::ENDPOINT_URL_UPDATED, ['body' => json_encode($this->content)]);
            $code += $response->getStatusCode();
        } catch (GuzzleException|Throwable $e) {
            $this->handleException($e, $indexed, $url);
            return null;
        }

        return $this->updateIndexedStatus($indexed, $code, $url);
    }

    protected function handleException(Throwable $e, Indexed $indexed, string $url): void
    {
        $this->logError("LINE: $this->submitted | Error occurred while indexing URL: $url", $e->getCode());
        $indexed->success = false;
        $indexed->save();
        $indexed->refresh();
    }

    protected function logError(string $message, int $code): void
    {
        $this->progress
            ->label("Error code: $code")
            ->hint("$message");
    }

    protected function updateIndexedStatus(Indexed $indexed, int $code, string $url): ?bool
    {
        if ($code >= 200 && $code < 300) {
            $indexed->success = true;
            $this->progress->label("Request success! URL: " . basename($url));
        } else {
            $indexed->success = false;
            $this->progress->label("Request failed! URL: " . basename($url));
        }

        $indexed->save();

        return $code >= 200 && $code < 300 ? true : ($code >= 300 && $code < 600 ? false : null);
    }
}