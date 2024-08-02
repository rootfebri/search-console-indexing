<?php

namespace App\Console\Commands;

use App\Models\ServiceAccount;
use App\Traits\HasHelper;
use Illuminate\Console\Command;

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

    public function handle(): void
    {
        if (!in_array($this->argument('task'), $this->tasks)) {
            $this->error("Invalid task. Available tasks: " . implode(", ", $this->tasks));
            return;
        }

        $this->task = $this->argument('task');
        $this->{$this->task}();
    }

    public function ServiceAccount(): void
    {
        $emailService = $this->ask('Email');
        $serviceAccount = ServiceAccount::where('email', $emailService);
        if (!$serviceAccount->exists()) {
            $serviceAccount = ServiceAccount::create(['email' => $emailService]);
        }

        $totalApikeys = $serviceAccount->apikeys_count;
        if ($totalApikeys > 5) {
            $this->error("Maximum number of API keys reached. Please delete an API key first.");
            return;
        }

        $jsonFiles = $this->scanJsonDir($emailService);
        foreach ($jsonFiles as $jsonFile) {
            $data = @file_get_contents($jsonFile);
            if (!$data) continue;
            if ($totalApikeys >= 5) break;
            if (!$serviceAccount->apikeys()->create(['data' => $data])) {
                $this->error("Error adding API key $jsonFile for: $serviceAccount->email");
                continue;
            }

            $this->info("API key $jsonFile added for: $serviceAccount->email");
            unlink($jsonFile);
            $totalApikeys++;
        }

        $this->info("Done!");
    }

    public function addSitemap(): void
    {
        $this->info("TODO: Add sitemap into database");
    }
}
