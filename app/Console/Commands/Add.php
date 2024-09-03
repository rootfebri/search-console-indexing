<?php

namespace App\Console\Commands;

use App\Models\Apikey;
use App\Models\OAuthModel;
use App\Models\ServiceAccount;
use App\Traits\GoogleOAuth;
use App\Traits\HasConstant;
use App\Traits\HasHelper;
use App\Types\CredentialType;
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
    protected string $email = '';
    protected string $path;

    public function __construct()
    {
        parent::__construct();
        $this->path = storage_path('json');
    }

    public function handle(): void
    {
        while (true) {
            $this->task = select('Menu:', $this::ADD_MENUS, 0);
            $this->task = str_replace(' ', '', $this->task);

            if ($this->task === 'ConsumeApikey') {
                $serviceAccount = $this->ServiceAccount();
                $this->{$this->task}($serviceAccount);
            } else {
                $this->{$this->task}();
            }
        }
    }

    public function ServiceAccount(bool $delete = false): ServiceAccount
    {
        $all = array_map(fn(ServiceAccount $serviceAccount) => [$serviceAccount->email => $serviceAccount], ServiceAccount::all()->all());
        /** @var array<string, ServiceAccount> $serviceAccounts */
        $serviceAccounts = $delete ? array_merge(...$all) : array_merge(['*NEW*' => ServiceAccount::findOrNew('')], ...$all);

        $this->email = select(
            label: 'Select a service account (Search Console Domain Owner)',
            options: array_keys($serviceAccounts),
            default: count($serviceAccounts) > 0 ? 1 : 0
        );

        while ($this->email === '*NEW*' || !$this->validateEmail($this->email)) {
            $this->email = $this->ask('Email');
            $serviceAccounts[$this->email] = ServiceAccount::create(['email' => $this->email]);
        }

        $serviceAccount = $serviceAccounts[$this->email];
        $serviceAccount->email = $this->email;
        $serviceAccount->save();

        if (!$serviceAccount->google_verifcation) {
            if (confirm('Add google verification code? just to make sure')) {
                $google_verifcation = $this->ask('Enter Google Verification Site E.g: google7ccb62e6c18...');
                $serviceAccount->google_verifcation = str_replace(['.html', 'html', '.htm', 'htm'], '', $google_verifcation);
                $serviceAccount->save();
            }
        }

        return $serviceAccount;
    }

    public function DeleteServiceAccount(): void
    {
        $sa = $this->ServiceAccount(true);
        $sa->delete();
    }

    public function OAuth(): void
    {
        $serviceAccount = $this->ServiceAccount();
        foreach ($serviceAccount->apikeys as $apikey) {
            try {
                $credential = (object)json_decode($apikey->data)->installed;
                $credential->account = $serviceAccount->email;
                if (OAuthModel::where('project_id', $credential->project_id)->first()) {
                    $apikey->delete();
                    throw new Exception($this->blue("[$apikey->id]") . $this->red("Autentikasi $credential->project_id sudah pernah dilakukan"));
                }
            } catch (Exception|Throwable $e) {
                $this->info("Error: " . $e->getMessage());
                continue;
            }

            $oauthUrl = $this->init(new CredentialType($credential->account, $credential->client_id, $credential->project_id, $credential->client_secret))->createAuthUrl();
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
            $this->line("[{$this->blue("$apikey->id")}/{$serviceAccount->apikeys()->count()}] Go to: " . route('oauth.index', $credential->project_id));
            while (!Cache::get($credential->project_id . self::DOT_FINISHED)) usleep(config('app.loop_safety'));
        }
    }

    public function ConsumeApikey(ServiceAccount $serviceAccount): void
    {
        $this->path = $this->selectDir($this->path);

        $jsonFiles = $this->scanJsonDir();
        $this->info("Found " . count($jsonFiles) . " json");

        foreach ($jsonFiles as $jsonFile) {
            $data = @file_get_contents($jsonFile);
            $trFilename = substr(basename($jsonFile), 0, 15) . "...";

            if (!$data) {
                continue;
            } elseif (Apikey::where('data', str_replace("\n", '', $data))->first() !== null) {
                $this->line($this->red("API key $trFilename already exists!"));
                unlink($jsonFile);
                continue;
            }

            $serviceAccount->apikeys()->create(['data' => str_replace("\n", '', $data)]);
            $this->info("API key $trFilename added for: $serviceAccount->email");
            unlink($jsonFile);
        }

        if (confirm('Delete this directory?', true)) {
            $this->deleteDirectory($this->path);
            $this->path = storage_path('json');
        }
        $this->info("Done!");
    }
}
