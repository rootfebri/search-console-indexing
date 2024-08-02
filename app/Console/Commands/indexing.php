<?php

namespace App\Console\Commands;

use App\Models\Apikey;
use App\Models\Indexed;
use App\Models\ServiceAccount;
use Google\Exception;
use Google_Client;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;
use Throwable;

class indexing extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'indexing';
    protected $description = 'Start indexing process';
    protected array $urlLists = [];
    protected string $sitemap;
    protected ServiceAccount $account;
    protected const ENDPOINT_URL_UPDATED = 'https://indexing.googleapis.com/v3/urlNotifications:publish';
    protected array $content = ['body' => ['type' => "URL_UPDATED"]];

    public function __construct(protected Google_Client $google_client)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        if (!$this->extractUrls()) return;
        if (!$this->confirm('Continue indexing?', true)) return;
        $this->cleanup();
        $this->info("Indexing started...");
        $this->runIndexing();
        $this->info("Done!");
    }

    public function extractUrls(): bool
    {
        $this->sitemap = $this->ask("Enter URLs sitemap to be indexed:");
        $urls = @file_get_contents($this->sitemap);
        if (!$urls) {
            $this->error("No sitemap found at " . $this->sitemap);
            return false;
        }

        $this->info("Sitemap found");
        $this->info("Extracting URLs...");

        $xml = simplexml_load_string($urls);
        foreach ($xml->url as $url) {
            $this->urlLists[] = (string)$url->loc;
        }

        $this->info(count($this->urlLists) . " URLs extracted");
        return true;
    }

    public function cleanup(): void
    {
        $this->info("Cleaning up indexed URLs...");

        $unsuccessful = Indexed::where('success', false)
            ->where('sitemap_url', $this->sitemap)
            ->pluck('url')
            ->toArray();

        $olderThan24Hours = Indexed::where('success', true)
            ->where('created_at', '<', now()->subHours(24))
            ->where('sitemap_url', $this->sitemap)
            ->pluck('url')
            ->toArray();

        $urls = array_merge($unsuccessful, $olderThan24Hours);
        $this->urlLists = array_filter($this->urlLists, fn($url) => in_array($url, $urls));
        $this->info(count($this->urlLists) . " URLs cleaned up");
    }

    public function runIndexing(): void
    {
        $this->google_client->setScopes(['https://www.googleapis.com/auth/indexing']);
        $apis = $this->account->apikeys()->where('used', '<=', 200)->get();

        foreach ($apis as $apiKey) {
            $this->processApiKey($apiKey);
        }
    }

    protected function processApiKey(Apikey $apiKey): void
    {
        try {
            $this->google_client->setAuthConfig($apiKey);
            $request = $this->google_client->authorize();
        } catch (Exception $e) {
            $this->logError("Error occurred while authorizing API key: " . $apiKey->id, $e);
            return;
        }

        foreach ($this->urlLists as $url) {
            if ($apiKey->used >= 200 && $apiKey->updated_at < now()->subHours(24)) {
                $apiKey->used = 0;
                $apiKey->updated_at = now();
                $apiKey->save();
            }
            if ($apiKey->used >= 200) break;

            $this->content['body']['url'] = $url;
            unset($this->urlLists[$url]);

            $status = $this->requestIndex($request);
            if ($status === null) continue;

            $apiKey->used++;
            $apiKey->save();
        }
    }

    public function requestIndex(Client|ClientInterface $request): ?bool
    {
        $code = 0;
        $url = $this->content['body']['url'];
        $indexed = Indexed::firstOrCreate(['url' => $url, 'sitemap_url' => $this->sitemap]);

        try {
            $response = $request->post(self::ENDPOINT_URL_UPDATED, $this->content);
            $code += $response->getStatusCode();

            if ($code >= 200 && $code < 300) {
                $indexed->success = true;
                $this->info("[$code] $url -> Request success!");
            } else {
                $indexed->success = false;
                $this->error("[$code] $url -> Request failed!");
            }
        } catch (GuzzleException $e) {
            $this->logError("Error occurred while indexing URL: $url", $e);
            $indexed->success = false;
        }

        $indexed->save();
        return $code >= 200 && $code < 300 ? true : ($code >= 300 && $code < 600 ? false : null);
    }

    protected function logError(string $message, Throwable $e): void
    {
        $this->error($message);
        $this->error($e->getMessage());
        // TODO: more info should be logged for UX
    }

    public function selectServiceAccount(): void
    {
        $this->info("Select service account:");
        $accounts = ServiceAccount::all();
        array_walk($accounts, fn(ServiceAccount $account) => $this->line("($account->id) - $account->email"));
        $id = $this->choice("Enter service account ID:", $accounts->pluck('id')->toArray());

        $this->account = ServiceAccount::find($id);
    }
}