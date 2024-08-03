<?php

namespace App\Console\Commands;

use App\Models\Apikey;
use App\Models\ServiceAccount;
use App\Traits\HasHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class Add extends Command
{
    use HasHelper;

    protected $signature = 'add {task}';
    protected $description = 'Run a task available in the task list';
    protected string $task = '';
    protected array $tasks = [
        'ServiceAccount',
        'Sitemap',
    ];
    protected string $email = '';

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
            $apikeys ??= ServiceAccount::firstOrCreate(['email' => $this->email])->apikeys()->get();
        }
        array_walk($apikeys, function (Apikey $apikey) {
            try {
                $credential = (object)json_decode($apikey->data)->installed;
            } catch (\Exception $e) {
                $this->error("Error: " . $e->getMessage());
                return;
            }

            if (Cache::has($credential->project_id)) {
                Cache::forget($credential->project_id);
            } else {
                Cache::forever($credential->project_id, $credential);
            }

            $this->line("Go to: " . route('oauth.index', $credential->project_id));

            while (Cache::get($credential->project_id . '.finished') === null | false) sleep(3);

            $this->info("Done!");
        });
    }

    public function ServiceAccount(): void
    {
        if (ServiceAccount::count() > 0) {
            $serviceAccounts = ['*NEW*', ...ServiceAccount::all()->pluck('email')->toArray()];

            $this->email = $this->choice('Pilih akun service', $serviceAccounts);
            if ($this->email !== '*NEW*') {
                $serviceAccount = ServiceAccount::where('email', $this->email)->first();
            }
        }

        while (!$this->validateEmail($this->email)) {
            $this->email = $this->ask('Email');
        }

        $serviceAccount ??= ServiceAccount::firstOrCreate(['email' => $this->email]);

        foreach ($this->scanJsonDir($this->email) as $jsonFile) {
            $truncFilename = substr(basename($jsonFile), 0, 15) . "...";
            $data = @file_get_contents($jsonFile);
            if (!$data) continue;
            if (!$serviceAccount->apikeys()->create(['data' => $data])) {
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
