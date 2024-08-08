<?php

namespace App\Console\Commands;

use App\Models\Apikey;
use App\Models\OAuthModel;
use App\Models\ServiceAccount;
use App\Traits\GoogleOAuth;
use App\Traits\HasConstant;
use App\Traits\HasHelper;
use App\Types\CredentialType;
use DOMDocument;
use DOMXPath;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Laravel\Prompts\Concerns\Colors;
use Throwable;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

class Add extends Command
{
    use HasHelper, HasConstant, Colors, GoogleOAuth;

    protected $signature = 'add';
    protected $description = 'Run a task available in the task list';
    protected string $task = '';
    protected array $tasks = [
        'ServiceAccount',
        'OAuth',
    ];
    protected string $email = '';
    protected string $path;

    public function __construct(public \Google_Client $client)
    {
        parent::__construct();
        $this->path = storage_path('json');
    }

    public function handle(): void
    {
        while (true) {
            $this->task = select('What todo?', $this->tasks, 0);
            $this->{$this->task}();
        }
    }

    public function OAuth(): void
    {
        if ($svc = ServiceAccount::all()) {
            $svcArray = $svc->pluck('email')->toArray();
            $this->email = select('Select email', $svcArray, $svcArray[0]);
        } else {
            $this->info($this->red("No service account found"));
            return;
        }

        try {
            $apikeys = ServiceAccount::where(['email' => $this->email])->first()->apikeys;
        } catch (Exception $e) {
            $this->info("Error: ". $e->getMessage());
            return;
        }

        foreach ($apikeys as $apikey) {
            try {
                $credential = (object)json_decode($apikey->data)->installed;
                $credential->account = $apikey->serviceAccount->email;
                if (OAuthModel::where('project_id', $credential->project_id)->first()) {
                    $apikey->delete();
                    throw new Exception($this->blue("[$apikey->id]") . $this->red("Autentikasi $credential->project_id sudah pernah dilakukan"));
                }
            } catch (Exception|Throwable $e) {
                $this->info("Error: ". $e->getMessage());
                continue;
            }

            $oauthUrl = $this->init(new CredentialType($credential->account, $credential->client_id, $credential->project_id, $credential->client_secret), $this->client)->createAuthUrl();

            try {
                $req = new Client(['timeout' => 0, 'allow_redirects' => false]);
                $response = $req->get($oauthUrl);
                $loc = $response->getHeader('Location');

                foreach ($loc as $value) {
                    $res = $req->get($value);
                    throw_if(str_contains($res->getBody(), 'The OAuth client was disabled'), new Exception("Error: 'The OAuth client was disabled'"));
                }

                Cache::forever($credential->project_id, $credential);
                Cache::forever($credential->project_id . '.url', $oauthUrl);
            } catch (GuzzleException|Throwable $exception) {
                $apikey->delete();
                $this->info($credential->project_id . ' -> ' . $this->red($exception->getMessage()));
                continue;
            }

            $this->flushTerminal();
            $this->line("[{$this->blue("$apikey->id")}/{$apikeys->count()}] Go to: " . route('oauth.index', $credential->project_id));
            while (!Cache::get($credential->project_id . self::DOT_FINISHED)) usleep(config('app.loop_safety'));
        }
    }

    public function ServiceAccount(): void
    {
        $this->flushTerminal();

        if (ServiceAccount::count() > 0) {
            $serviceAccounts = ['*NEW*', ...ServiceAccount::all()->pluck('email')->toArray()];

            $this->email = select('Pilih akun service', $serviceAccounts, $serviceAccounts[1] ?? 0);
            if ($this->email !== '*NEW*') {
                $serviceAccount = ServiceAccount::where('email', $this->email)->first();
            }
        }

        while (!$this->validateEmail($this->email)) $this->email = $this->ask('Email');

        $serviceAccount ??= ServiceAccount::firstOrCreate(['email' => $this->email]);

        if (!$serviceAccount->google_verifcation) {
            if (confirm('Add google verification code? just to make sure')) {
                $serviceAccount->google_verifcation = $this->ask('Type the google verificatoin code');
                $serviceAccount->save();
            }
        }

        if (!confirm('Continue to add apikey?', true)) {
            $this->info('Done!');
            return;
        }

        $this->path = $this->selectDir($this->path);

        $jsonFiles = $this->scanJsonDir();
        $this->info("Found " . count($jsonFiles) . " json");

        foreach ($jsonFiles as $jsonFile) {
            $data = @file_get_contents($jsonFile);
            $truncFilename = substr(basename($jsonFile), 0, 15) . "...";

            if (!$data) {
                continue;
            } elseif (Apikey::where('data', str_replace("\n", '', $data))->first() !== null) {
                $this->line($this->red("API key $truncFilename already exists!"));
                unlink($jsonFile);
                continue;
            } elseif (!$serviceAccount->apikeys()->create(['data' => str_replace("\n", '', $data)])) {
                $this->line("Error adding API key $truncFilename for: $serviceAccount->email");
                continue;
            }

            $this->info("API key $truncFilename added for: $serviceAccount->email");
            unlink($jsonFile);
        }

        if (confirm('Delete this directory?', true)) {
            $this->deleteDirectory($this->path);
            $this->path = storage_path('json');
        }
        $this->info("Done!");
    }
}
