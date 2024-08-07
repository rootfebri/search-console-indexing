<?php

namespace App\Console\Commands;

use App\Models\Indexed;
use App\Models\OAuthModel;
use App\Models\ServiceAccount;
use App\Traits\GoogleOAuth;
use App\Traits\HasConstant;
use App\Traits\HasHelper;
use Google_Client;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;
use Laravel\Prompts\Progress;
use Throwable;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\select;

class Indexing extends Command
{
    use GoogleOAuth, HasConstant, HasHelper;

    protected const ENDPOINT_URL_UPDATED = 'https://indexing.googleapis.com/v3/urlNotifications:publish';
    public int $submitted = 0;
    public int $oauthLimits = 0;
    public int $startTime;
    protected $signature = 'indexing';
    protected $description = 'Start indexing process';
    protected array $urlLists = [];
    protected ?string $sitemap = '';
    protected ServiceAccount $account;
    protected array $content = ['type' => "URL_UPDATED"];
    protected bool $alwaysContinue = true;
    protected Google_Client $google_client;
    protected Progress $progress;
    protected string $additionalHint = "\n";

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

        $oriCount = count($this->urlLists);
        $idx = Indexed::all()->where('sitemap_url', $this->sitemap)->where('success', true);
        $all = $idx->pluck('url')->toArray();
        $daily = $idx->filter(fn($res) => -24 < $res->last_update)->pluck('url')->toArray();

        $question = [
            'Yes' => array_values(array_filter($this->urlLists, fn($url) => !in_array($url, $all))),
            'Include over 24 hours' => array_values(array_filter($this->urlLists, fn($url) => !in_array($url, $daily))),
            'No' => $this->urlLists,
        ];

        $answer = select("Filter urls?", array_keys($question), 0);

        $this->urlLists = $question[$answer];
        $this->line($oriCount - count($this->urlLists) . " URLs removed after cleanup");
        if ($this->confirm('Randomize URLs? [not Recommended]') === true) {
            shuffle($this->urlLists);
        } else {
            sort($this->urlLists, SORT_DESC);
        }
    }

    protected function selectServiceAccount(): void
    {
        $accounts = ServiceAccount::all();
        foreach ($accounts as $account) {
            $account->resetOAuths();
            $account->refresh();
        }

        $accounts = ServiceAccount::all()->load('oauths');
        $fmt = array_map(function ($email) {
            $limits = ServiceAccount::where('email', $email)?->first()->oauths()->pluck('limit')->toArray();
            $oauthLimit = array_sum($limits);
            return "$email => [limit: $oauthLimit]";
        }, $accounts->pluck('email')->toArray());

        $this->startTime = microtime(true);
        $email = $this->choice("Enter service account ID", $fmt, 0);
        $expl = explode(' ', $email);
        $cexpl = count($expl);
        $email = $expl[0];
        $this->oauthLimits = rtrim($expl[$cexpl - 1], ']');

        $this->progress = progress(
            label: 'Starting indexing...',
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
        $this->flushTerminal();
        $this->progress->start();
        $oauths = $this->account->oauths()->get();

        foreach ($oauths as $oauth) {
            $oauth->reset();
            $this->processApiKey($oauth);
        }
        $this->progress->finish();
    }

    protected function processApiKey(OAuthModel $oauth): void
    {
        if (!$oauth->usable()) {
            $this->additionalHint = "Request limit exceeded for $oauth->project_id\n";
            return;
        }

        $request = $this->tryAuth($oauth);
        if (!$request) {
            $this->progress
                ->hint("Failed to authorize $oauth->project_id")
                ->render();
            return;
        }

        foreach ($this->urlLists as $url) {
            $this->content['url'] = $url;
            $this->removeUrl($url);
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
                $this->additionalHint = "Request limit exceeded for $oauth->project_id\n";
//                $this->progress->hint("Request limit exceeded for $oauth->project_id")->render();
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

    private function removeUrl(string $url): void
    {
        unset($this->urlLists[$url]);
        $this->urlLists = array_values($this->urlLists);
    }

    protected function submit(Client|ClientInterface $request): ?bool
    {
        $this->submitted++;
        $this->progress
            ->hint($this->estimate())
            ->render();
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

        return $this->updateIndexedStatus($indexed, $code, $url);
    }

    private function estimate(): string
    {
        $this->progress->moveCursorUp(1);
        return $this->progress->magenta("Est. time remaining: " . $this->calculateTime($this->oauthLimits, $this->submitted));
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
            ->hint("$message")
            ->render();
    }

    protected function updateIndexedStatus(Indexed $indexed, int $code, string $url): ?bool
    {
        if ($code >= 200 && $code < 300) {
            $indexed->success = true;

            $this->progress
                ->label($this->progress->green("Request success! URL: " . basename($url)))
                ->render();
        } else {
            $indexed->success = false;

            $this->progress
                ->label($this->progress->red("Request failed! URL: " . basename($url)))
                ->render();
        }

        $indexed->save();

        return $code >= 200 && $code < 300 ? true : ($code >= 300 && $code < 600 ? false : null);
    }
}