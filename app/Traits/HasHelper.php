<?php

namespace App\Traits;

use Illuminate\Console\Command;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

trait HasHelper
{
    /**
     * Scans the directory specified by $this->path for JSON files.
     *
     * This function searches the directory for files with a .json extension
     * and returns an array of their full paths.
     *
     * @return array An array of full paths to the JSON files found in the directory.
     */
    public function scanJsonDir(): array
    {
        $jsonFiles = array_filter(scandir($this->path), fn($file) => str_ends_with($file, '.json'));

        return array_map(fn($fileName) => $this->path . DIRECTORY_SEPARATOR . basename($fileName), array_values($jsonFiles));
        //                                ^^^^^^^^^^^^^^^^^^^^^^^^^^^ Expected to be: json/{at}|{email}|{username}/*.json
    }

    /**
     * Validates the given email address.
     *
     * This function checks if the provided email address is valid according to the FILTER_VALIDATE_EMAIL filter.
     * If the email is invalid and not equal to '*NEW*', it outputs an error message.
     *
     * @param string $email The email address to be validated.
     * @return bool Returns true if the email is valid, otherwise false.
     */
    protected function validateEmail(string $email): bool
    {
        switch (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            case true:
                return true;
            case false:
                ($email !== '*NEW*' && $this->line('Email tidak valid..'));
                return false;
            default:
                $this->line('ERROR');
                return false;
        }
    }

    /**
     * Allows the user to navigate and select a directory from the terminal.
     *
     * This function provides a terminal-based interface for directory selection.
     * It repeatedly prompts the user to choose a directory from the current path
     * until a valid directory is selected or the user decides to stop.
     *
     * @param string $initialDir The initial directory path from which to start the selection.
     * @return string The final selected directory path.
     */
    private function selectDir(string $initialDir): string
    {
        if (!$this instanceof Command) {
            echo "Error: Not a command instance";
            return $initialDir;
        }

        while (true) {
            $this->flushTerminal();
            $prompt = 'Select directory [' . rtrim($initialDir, '.') . ']';
            if (isset($_SERVER['OS']) && str_starts_with(strtolower($_SERVER['OS']), 'win')) {
                $path = $this->choice(question: $prompt, choices: $this->scandir($initialDir), default: 0, attempts: 3);
            } else {
                $path = select(label: $prompt, options: $this->scandir($initialDir), default: 0, scroll: 10);
            }

            if ($path === '.') {
                break;
            } else if ($path === '..') {
                $initialDir = substr($initialDir, 0, strrpos($initialDir, DIRECTORY_SEPARATOR));
            } else {
                $initialDir .= DIRECTORY_SEPARATOR . $path;
            }
        }

        return $initialDir;
    }

    /**
     * Clears the terminal screen.
     *
     * This function clears the terminal screen by sending the appropriate
     * command based on the operating system. For Windows, it sends escape
     * sequences to clear the screen. For Unix-like systems, it uses the
     * 'clear' command.
     *
     * @return void
     */
    protected function flushTerminal(): void
    {
        if (str_starts_with(strtolower(PHP_OS), 'win')) {
            echo chr(27) . chr(91) . 'H' . chr(27) . chr(91) . 'J';
        } else {
            system('clear');
        }
    }

    /**
     * Scans the specified directory for subdirectories.
     *
     * This function scans the given directory path and returns an array of names
     * of all subdirectories within that directory.
     *
     * @param string $path The directory path to scan for subdirectories.
     * @return array An array of subdirectory names found in the specified directory.
     */
    protected function scandir(string $path): array
    {
        return array_values(array_filter(scandir($path), fn($file) => is_dir($path . DIRECTORY_SEPARATOR . $file)));
    }

    private function selectFile(string $initialDir): string
    {
        while (true) {
            $this->flushTerminal();
            if (!is_dir($initialDir) && file_exists($initialDir)) {
                break;
            }

            $prompt = 'Select directory [' . rtrim($initialDir, '.') . ']';
            if (isset($_SERVER['OS']) && str_starts_with(strtolower($_SERVER['OS']), 'win')) {
                $selected = $this->choice(question: $prompt, choices: $this->scandir($initialDir), default: 0, attempts: 3);
            } else {
                $selected = select(label: $prompt, options: scandir($initialDir), default: 0, scroll: 10);
            }

            if ($selected === '..') {
                $initialDir = substr($initialDir, 0, strrpos($initialDir, DIRECTORY_SEPARATOR));
            } else {
                $initialDir .= DIRECTORY_SEPARATOR . $selected;
            }
        }

        return $initialDir;
    }

    private function confirmation(string $prompt): bool
    {
        $answer = select($prompt, ['yes', 'no']);
        return $answer === 'yes';
    }

    private function choices(string $prompt, array $choices, int $maxScrollItems = 5): string|int
    {
        return select($prompt, $choices, null, $maxScrollItems);
    }

    private function pause(): void
    {
        $this->confirm('Press any key to continue...');
    }

    private function setObjectParams(string $fullpath, mixed $body, bool $ACL = true): array
    {
        $default = [
            'Key' => basename($fullpath),
            'Body' => $body,
            'Bucket' => $this->bucket,
            'Contet-Type' => mime_content_type($fullpath),
        ];

        return match ($ACL) {
            true => [
                ...$default,
                'ACL' => 'public-read',
            ],
            false => [
                ...$default,
            ]
        };
    }
}