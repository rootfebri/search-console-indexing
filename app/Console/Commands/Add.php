<?php

namespace App\Console\Commands;

use App\Models\Apikey;
use App\Models\ServiceAccount;
use App\Traits\HasConstant;
use App\Traits\HasHelper;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class Add extends Command
{
    use HasHelper, HasConstant;

    protected $signature = 'add {task}';
    protected $description = 'Run a task available in the task list';
    protected string $task = '';
    protected array $tasks = [
        'OAuth',
        'ServiceAccount',
        'Sitemap',
    ];
    protected string $email = '';
    protected string $path;

    public function __construct()
    {
        parent::__construct();
        $this->path = storage_path('json');
    }

    public function handle(): void
    {
        if (!in_array($this->argument('task'), $this->tasks)) {
            $this->error("Invalid task. Available tasks: " . implode(", ", $this->tasks));
            return;
        }

        $this->task = $this->argument('task');
        $this->{$this->task}();
    }

    public function OAuth(): void
    {
        if (ServiceAccount::count() > 0) {
            $serviceAccounts = ['*NEW*', ...ServiceAccount::all()->pluck('email')->toArray()];

            $this->email = $this->choice('Pilih akun service', $serviceAccounts);
            if ($this->email !== '*NEW*') {
                $apikeys = ServiceAccount::where('email', $this->email)->first()->apikeys()->get();
            }
        }

        while (!$this->validateEmail($this->email)) {
            $this->email = $this->ask('Email');
        }

        $apikeys ??= ServiceAccount::firstOrCreate(['email' => $this->email])->apikeys;
        foreach ($apikeys as $apikey) {
            try {
                $credential = (object)json_decode($apikey->data)->installed;
            } catch (Exception $e) {
                $this->error("Error: " . $e->getMessage());
                return;
            }

            if (Cache::has($credential->project_id)) {
                Cache::forget($credential->project_id);
            } else {
                $credential->account = $apikey->serviceAccount->email;
                Cache::forever($credential->project_id, $credential);
            }

            $this->line("Go to: " . route('oauth.index', $credential->project_id));

            while (!Cache::get($credential->project_id . self::DOT_FINISHED)) sleep(3);

            $this->line(Cache::pull($credential->project_id . self::DOT_FINISHED));
        }
    }

    public function ServiceAccount(): void
    {
        $this->flushTerminal();

        if (ServiceAccount::count() > 0) {
            $serviceAccounts = ['*NEW*', ...ServiceAccount::all()->pluck('email')->toArray()];

            $this->email = $this->choice('Pilih akun service', $serviceAccounts);
            if ($this->email !== '*NEW*') {
                $serviceAccount = ServiceAccount::where('email', $this->email)->first();
            }
        }

        while (!$this->validateEmail($this->email)) $this->email = $this->ask('Email');

        $serviceAccount ??= ServiceAccount::firstOrCreate(['email' => $this->email]);

        if (!$serviceAccount->google_verifcation) {
            if ($this->confirm('Add google verification code? just to make sure')) {
                $serviceAccount->google_verifcation = $this->ask('Type the google verificatoin code');
                $serviceAccount->save();
            }
        }

        if (!$this->confirm('Continue to add apikey?', true)) {
            $this->info('Done!');
            return;
        }

        while (true) {
            $this->flushTerminal();
            $path = $this->choice("Select directory [Current: " . rtrim($this->path, '.') . "]", $this->scandir($this->path), 0, 3);

            if ($path === '.') {
                $jsonFiles = $this->scanJsonDir();
                $this->info("Found " . count($jsonFiles) . " json");
                break;
            } else if ($path === '..') {
                $this->path = substr($this->path, 0, strrpos($this->path, DIRECTORY_SEPARATOR));
            } else {
                $this->path .= DIRECTORY_SEPARATOR . $path;
            }
        }

        foreach ($jsonFiles as $jsonFile) {
            $data = @file_get_contents($jsonFile);
            $truncFilename = substr(basename($jsonFile), 0, 15) . "...";

            if (!$data) continue;
            if (!$serviceAccount->apikeys()->create(['data' => str_replace("\n", '', $data)])) {
                $this->line("Error adding API key $truncFilename for: $serviceAccount->email");
                continue;
            }

            $this->info("API key $truncFilename added for: $serviceAccount->email");
            unlink($jsonFile);
        }

        $this->info("Done!");
    }

    public function addSitemap(): void
    {
        $this->info("TODO: Add sitemap into database");
    }
}
