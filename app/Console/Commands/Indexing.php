<?php

namespace App\Console\Commands;

use App\Models\OAuthModel;
use App\Models\ServiceAccount;
use App\Models\Site;
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
use Illuminate\Console\Command;
use Laravel\Prompts\Concerns\Colors;
use Laravel\Prompts\Progress;
use Psr\Http\Message\RequestInterface;
use Throwable;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\select;

class Indexing extends Command
{
    use GoogleOAuth, HasConstant, HasHelper, Colors;

    public int $submitted = 0;
    public int $lastOffset = 0;
    public int $tol = 0;
    public int $startTime;
    public array $urlLists = [];
    public array $syncSlicedUrls = [];
    public ?string $sitemap = '';
    public ServiceAccount $account;
    public Google_Client $google_client;
    public Progress $progress;
    protected $signature = 'indexing';
    protected $description = 'Start indexing process';

    public function __construct(protected ServiceAccount $svcAccount)
    {
        parent::__construct();
        $this->startTime = microtime(true);
    }

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
        sort($this->urlLists);
        $this->urlLists = array_unique($this->urlLists);
        $this->line("Cleaning up indexed URLs...");

        $oriCount = count($this->urlLists);
        $question = ['Yes' => 'filtered', 'Include over 24 hours' => 'over24h', 'No' => [],];
        $answer = select("Filter urls?", array_keys($question), 0);

        $this->urlLists = is_string($question[$answer]) ? $this->{$question[$answer]}() : $this->urlLists;
        $this->line("Before: {$this->red($oriCount)} -> After: " . $this->green(count($this->urlLists)));
    }

    protected function selectServiceAccount(): void
    {
        $accounts = array_merge(...array_map(function ($account) {
            $account->resetOAuths();
            $oauthLimit = array_sum($account->oauths()->get()->pluck('limit')->toArray());

            return ["$account->email [Limit < $oauthLimit]" => $account];
        }, $this->svcAccount::all()->all()));

        $this->account = $accounts[select('Select Account', array_keys($accounts))];
        $this->tol = array_sum($this->account->oauths()->get()->pluck('limit')->toArray());
        $this->progress = progress(label: 'Starting indexing...', steps: $this->urlLists, hint: 'This might take a while.');
    }

    protected function runIndexing(): void
    {
        $this->flushTerminal();
        $this->progress->start();

        $oauths = $this->account->oauths->all();
        array_walk($oauths, function ($oauth) {
            try {
                $this->processApiKey($oauth);
            } catch (Throwable $e) {
                throw_if(str_contains(strtolower($e->getMessage()), 'done'));
                $this->progress->label("Failed to authenticate with Google API: $oauth->project_id")->render();
            }
        });
        $this->progress->finish();
    }

    /**
     * @throws Throwable
     */
    protected function processApiKey(OAuthModel $oauth): void
    {
        if (!$oauth->usable() || $this->lastOffset >= count($this->urlLists)) return;

        $request = $this->tryAuth($oauth);
        throw_if($request === null, "Failed to authenticate with Google API: $oauth->project_id");

        $this->syncSlicedUrls = array_slice(array: $this->urlLists, offset: $this->lastOffset, length: $oauth->limit);
        $this->lastOffset += count($this->syncSlicedUrls);

        $serviceIndexing = new Google_Service_Indexing($request);
        $batch = $serviceIndexing->createBatch();
        array_walk($this->syncSlicedUrls, function ($url) use (&$serviceIndexing, &$batch, &$oauth) {
            $postBatch = new Google_Service_Indexing_UrlNotification();
            $postBatch->setType('URL_UPDATED');
            $postBatch->setUrl($url);
            try {
                /** @var RequestInterface $publish Just to silence this stupid IDE or Google? */
                $publish = $serviceIndexing->urlNotifications->publish($postBatch);
                $batch->add($publish);
                $oauth->decrement('limit');
                $this->progress->label($this->magenta("Queing " . basename($url)))->hint($this->estimate())->render();
            } catch (Exception) {
            }
        });

        $results = $batch->execute();
        array_walk($results, function (PublishUrlNotificationResponse|GoogleServiceApiException $result) {
            $this->submitted++;
            if ($result instanceof PublishUrlNotificationResponse) {
                $this->processResult($result);
            }
        });

        $this->processResultWithExceptions();
        throw_if($this->lastOffset + $oauth->limit >= count($this->urlLists) - 1, "Done!");
    }

    private function tryAuth(OAuthModel $oauth, int $retry = 3): Client|ClientInterface|Google_Client|null
    {
        try {
            $this->google_client = $this->setup($oauth);
            $this->google_client->setUseBatch(true);
            $this->google_client->authorize();
        } catch (Throwable) {
            if ($retry > 0) {
                usleep(config('app.loop_safety'));
                return $this->tryAuth($oauth, $retry - 1);
            } else {
                $countdown = 3;
                while ($countdown-- > 0) {
                    $this->progress->label($this->red("Failed to authorize $oauth->project_id"))->hint('HIT CTRL+C to stop OR CONTINUING IN ' . $countdown)->render();
                    sleep(1);
                }
                return null;
            }
        }
        return $this->google_client;
    }

    private function estimate(): string
    {
        return $this->magenta("Est. time remaining: " . $this->calculateTime($this->tol, $this->submitted));
    }

    private function processResult(PublishUrlNotificationResponse $result): void
    {
        $url = $result->getUrlNotificationMetadata()->getLatestUpdate()->getUrl();

        $site = Site::findOrNew($url);
        $site->url = $url;
        $site->request_on = time();
        $site->success = true;
        $site->save();

        $this->array_remove_one($url, $this->syncSlicedUrls);
        $this->progress->label($this->green("Request success! URL: " . basename($url)))->render();
    }

    private function array_remove_one(mixed $value, array &$array): void
    {
        unset($array[array_search($value, $array)]);
        $array = array_values($array);
    }

    private function processResultWithExceptions(): void
    {
        array_walk($this->syncSlicedUrls, function ($url) {
            $site = Site::findOrNew($url);
            $site->request_on = time();
            $site->success = true;
            $site->save();
            $this->progress->label($this->red("Request failed! URL: " . basename($this->syncSlicedUrls[0])))->render();
        });
        $this->syncSlicedUrls = [];
    }

    private function filtered(): array
    {
        $sites = Site::where('success', true)->pluck('url')->toArray();
        return $this->array_filter(array: $this->urlLists, lambda: fn($url) => !in_array($url, $sites));
    }

    private function over24h(): array
    {
        $sites = Site::all()
            ->filter(fn($site) => !$site->overHours(23))
            ->pluck('url')
            ->toArray();

        return $this->array_filter(
            array: $this->urlLists,
            lambda: fn($url) => !in_array($url, $sites)
        );
    }
}
