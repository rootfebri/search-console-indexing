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

class indexing extends Command
{
    use GoogleOAuth, HasConstant;

    protected const ENDPOINT_URL_UPDATED = 'https://indexing.googleapis.com/v3/urlNotifications:publish';
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'indexing';
    protected $description = 'Start indexing process';
    protected array $urlLists = [];
    protected ?string $sitemap = '';
    protected ServiceAccount $account;
    protected array $content = ['type' => "URL_UPDATED"];
    protected bool $alwaysContinue = true;

    public function __construct(protected Google_Client $google_client)
    {
        parent::__construct();
    }

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
            if (!$areYouOwner) {
                if (!$this->confirm("$linkVerify tidak ditemukan, Tetap lanjutkan?")) {
                    return;
                }
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
                $this->line("Invalid url, eg: https://example.com/sitemap.xml");
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

        $lessThan24Hours = Indexed::where('success', true)
            ->where('created_at', '>', now()->subHours(24))
            ->where('sitemap_url', $this->sitemap)
            ->pluck('url')
            ->toArray();

        $this->urlLists = array_filter($this->urlLists, fn($url) => !in_array($url, $lessThan24Hours));
        $this->line(count($this->urlLists) . " URLs left after cleanup");
        $this->confirm('Randomize urls?', true) && shuffle($this->urlLists);
    }

    protected function selectServiceAccount(): void
    {
        $accounts = ServiceAccount::all()->load('oauths');
        $fmt = array_map(function ($email) {
            $limits = ServiceAccount::where('email', $email)?->first()->oauths()->pluck('limit')->toArray();

            $oauthLimit = 0;
            foreach ($limits as $limit) {
                $oauthLimit += $limit ?? 0;
            }

            return "$email => [limit: $oauthLimit]";
        }, $accounts->pluck('email')->toArray());

        $email = $this->choice("Enter service account ID", $fmt);
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
            $reset = now()->subHours(24)->timestamp - $oauth->refresh_time ?? 0;
            if ($reset > 0) {
                $oauth->limit = 200;
                $oauth->refresh_time = now()->timestamp;
                $oauth->save();
            }
            $this->processApiKey($oauth);
        }
    }

    protected function processApiKey(OAuthModel $oauth): void
    {
        if ($oauth->limit < 1) {
            $this->info("Request limit exceeded for $oauth->project_id");
            return;
        }

        $this->google_client = $this->indexer($this->google_client, $oauth);
        $request = $this->google_client->authorize();

        foreach ($this->urlLists as $url) {
            $this->content['url'] = $url;
            unset($this->urlLists[$url]);
            $status = $this->submit($request);

            if ($status === null) {
                break;
            } else {
                $oauth->limit--;
                $oauth->save();
            }

            $status === false && $this->alwaysContinue && $this->alwaysContinue = $this->confirm('Request gagal, selalu tanya untuk melanjutkan?', true);
            if ($oauth->limit < 1) {
                break;
            }
        }
    }

    protected function submit(Client|ClientInterface $request): ?bool
    {
        $code = 0;
        $url = $this->content['url'];
        $indexed = Indexed::firstOrCreate(['url' => $url, 'sitemap_url' => $this->sitemap]);

        try {
            $response = $request->post(self::ENDPOINT_URL_UPDATED, ['body' => json_encode($this->content)]);
            $code += $response->getStatusCode();

            if ($code >= 200 && $code < 300) {
                $indexed->success = true;
                $this->line("[$code] $url -> Request success!");
            } else {
                $indexed->success = false;
                $this->line("[$code] $url -> Request failed!");
                if ($hasMessage = @json_decode($response->getBody()->getContents(), true)) {
                    $this->line("Message/Code: " . $hasMessage['error']['message'] ?? $code);
                }
            }
        } catch (GuzzleException $e) {
            if ($hasMessage = @json_decode($e->getMessage())) {
                $this->line("Message/Code: " . $hasMessage['error']['message'] ?? $code);
            } else {
                $this->logError("Error occurred while indexing URL: $url", $e);
            }

            $indexed->success = false;
        }

        $indexed->save();
        return $code >= 200 && $code < 300 ? true : ($code >= 300 && $code < 600 ? false : null);
    }

    protected function logError(string $message, Throwable $e): void
    {
        $this->line($message);
        $this->line($e->getMessage());
    }
}