<?php

namespace App\Console\Commands;

use App\Models\Indexed;
use App\Models\OAuthModel;
use App\Models\ServiceAccount;
use App\Traits\GoogleOAuth;
use App\Traits\HasConstant;
use App\Traits\HasHelper;
use Google\Service\Exception;
use Google\Service\Exception as GoogleServiceApiException;
use Google\Service\Indexing\PublishUrlNotificationResponse;
use Google_Client;
use Google_Service_Indexing;
use Google_Service_Indexing_UrlNotification;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise;
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
    public int $lastSliced = 0;
    public int $oauthLimits = 0;
    public int $startTime;
    protected $signature = 'indexing';
    protected $description = 'Start indexing process';
    protected array $urlLists = [];
    protected array $syncSlicedUrls = [];
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
        $question = [
            'Yes' => 'filtered',
            'Include over 24 hours' => 'over24h',
            'No' => [],
        ];
        $answer = select("Filter urls?", array_keys($question), 0);

        $this->urlLists = is_string($question[$answer]) ? $this->{$question[$answer]}() : $this->urlLists;
        $this->line("Before: $oriCount -> After: " . count($this->urlLists));

        sort($this->urlLists, SORT_ASC);
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
        $serviceIndexing = new Google_Service_Indexing($request);
        $batch = $serviceIndexing->createBatch();

        if (!$request) {
            $this->progress->hint("Failed to authorize $oauth->project_id")->render();
            return;
        }

        $this->syncSlicedUrls = array_slice($this->urlLists, $this->lastSliced, $oauth->limit);
        $this->lastSliced = count($this->syncSlicedUrls);

        array_walk($this->syncSlicedUrls, function ($url) use (&$serviceIndexing, &$batch, &$oauth) {
            $postBatch = new Google_Service_Indexing_UrlNotification();
            $postBatch->setType('URL_UPDATED');
            $postBatch->setUrl($url);
            try {
                /** @var \Psr\Http\Message\RequestInterface $publish Just to silence this stupid IDE or Google? */
                $publish = $serviceIndexing->urlNotifications->publish($postBatch);
                $batch->add($publish);
                $oauth->decrement('limit');
            } catch (Exception) {}
        });

        $results = $batch->execute();

        array_walk($results, function (PublishUrlNotificationResponse|GoogleServiceApiException $result) {
            $this->submitted++;
            $this->progress->hint($this->estimate())->advance();
            if ($result instanceof PublishUrlNotificationResponse) {
                $this->processResult($result);
            }
        });

        $this->processResultWithExceptions();
    }

    private function tryAuth(OAuthModel $oauth, int $retry = 3): Client|ClientInterface|Google_Client|null
    {
        $sleep = 5;
        try {
            $this->google_client = $this->setup($oauth);
            $this->google_client->setUseBatch(true);
            $this->google_client->authorize();
        } catch (Throwable) {
            if ($retry > 0) {
                sleep($sleep);
                return $this->tryAuth($oauth, $retry - 1);
            } else {
                return null;
            }
        }
        return $this->google_client;
    }

    private function processResult(PublishUrlNotificationResponse $result): void
    {
        $url = $result->getUrlNotificationMetadata()->getLatestUpdate()->getUrl();
        $this->remove($url, $this->syncSlicedUrls);

        $indexed = Indexed::firstOrCreate([
            'url' => $url,
            'sitemap_url' => $this->sitemap,
        ]);

        $this->updateIndexedStatus($indexed, 200, $url);
    }

    private function remove(mixed $value, array &$array): void
    {
        unset($array[array_search($value, $array)]);
        $array = array_values($array);
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

    private function processResultWithExceptions(): void
    {
        while (count($this->syncSlicedUrls) > 0) {
            $indexed = Indexed::firstOrCreate([
                'url' => $this->syncSlicedUrls[0],
                'sitemap_url' => $this->sitemap,
            ]);

            $this->updateIndexedStatus($indexed, 429, $this->syncSlicedUrls[0]);
            $this->remove($this->syncSlicedUrls[0], $this->syncSlicedUrls);
        }
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

    private function filtered(): array
    {
        $onDatabase = Indexed::all()->where('success', true)->pluck('url')->toArray();
        return $this->array_filter($this->urlLists, fn($url) =>!in_array($url, $onDatabase));
    }

    private function over24h(): array
    {
        $idx = Indexed::all()->where('sitemap_url', $this->sitemap)->where('success', true)->filter(fn($res) => -24 < $res->last_update)->pluck('url')->toArray();
        return $this->array_filter($this->urlLists, fn($url) => !in_array($url, $idx));
    }
}
