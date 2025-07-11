<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ViewScheduledLogs extends Command
{
    protected $signature = 'logs:scheduled 
                            {--lines=50 : Number of lines to display}
                            {--follow : Follow the log file (tail -f)}
                            {--date= : Specific date (Y-m-d format, defaults to today)}
                            {--task-name= : Filter by task name (partial match)}
                            {--task-id= : Filter by task ID}
                            {--backup-name= : Filter by backup name (partial match)}
                            {--backup-id= : Filter by backup ID}
                            {--errors : View error logs only}
                            {--all : View both normal and error logs}';

    protected $description = 'View scheduled backups and tasks logs with optional filtering';

    public function handle()
    {
        $date = $this->option('date') ?: now()->format('Y-m-d');
        $logPaths = $this->getLogPaths($date);

        if (empty($logPaths)) {
            $this->showAvailableLogFiles($date);

            return;
        }

        $lines = $this->option('lines');
        $follow = $this->option('follow');

        // Build grep filters
        $filters = $this->buildFilters();
        $filterDescription = $this->getFilterDescription();
        $logTypeDescription = $this->getLogTypeDescription();

        if ($follow) {
            $this->info("Following {$logTypeDescription} logs for {$date}{$filterDescription} (Press Ctrl+C to stop)...");
            $this->line('');

            if (count($logPaths) === 1) {
                $logPath = $logPaths[0];
                if ($filters) {
                    passthru("tail -f {$logPath} | grep -E '{$filters}'");
                } else {
                    passthru("tail -f {$logPath}");
                }
            } else {
                // Multiple files - use multitail or tail with process substitution
                $logPathsStr = implode(' ', $logPaths);
                if ($filters) {
                    passthru("tail -f {$logPathsStr} | grep -E '{$filters}'");
                } else {
                    passthru("tail -f {$logPathsStr}");
                }
            }
        } else {
            $this->info("Showing last {$lines} lines of {$logTypeDescription} logs for {$date}{$filterDescription}:");
            $this->line('');

            if (count($logPaths) === 1) {
                $logPath = $logPaths[0];
                if ($filters) {
                    passthru("tail -n {$lines} {$logPath} | grep -E '{$filters}'");
                } else {
                    passthru("tail -n {$lines} {$logPath}");
                }
            } else {
                // Multiple files - concatenate and sort by timestamp
                $logPathsStr = implode(' ', $logPaths);
                if ($filters) {
                    passthru("tail -n {$lines} {$logPathsStr} | sort | grep -E '{$filters}'");
                } else {
                    passthru("tail -n {$lines} {$logPathsStr} | sort");
                }
            }
        }
    }

    private function getLogPaths(string $date): array
    {
        $paths = [];

        if ($this->option('errors')) {
            // Error logs only
            $errorPath = storage_path("logs/scheduled-errors-{$date}.log");
            if (File::exists($errorPath)) {
                $paths[] = $errorPath;
            }
        } elseif ($this->option('all')) {
            // Both normal and error logs
            $normalPath = storage_path("logs/scheduled-{$date}.log");
            $errorPath = storage_path("logs/scheduled-errors-{$date}.log");

            if (File::exists($normalPath)) {
                $paths[] = $normalPath;
            }
            if (File::exists($errorPath)) {
                $paths[] = $errorPath;
            }
        } else {
            // Normal logs only (default)
            $normalPath = storage_path("logs/scheduled-{$date}.log");
            if (File::exists($normalPath)) {
                $paths[] = $normalPath;
            }
        }

        return $paths;
    }

    private function showAvailableLogFiles(string $date): void
    {
        $logType = $this->getLogTypeDescription();
        $this->warn("No {$logType} logs found for date {$date}");

        // Show available log files
        $normalFiles = File::glob(storage_path('logs/scheduled-*.log'));
        $errorFiles = File::glob(storage_path('logs/scheduled-errors-*.log'));

        if (! empty($normalFiles) || ! empty($errorFiles)) {
            $this->info('Available scheduled log files:');

            if (! empty($normalFiles)) {
                $this->line('  Normal logs:');
                foreach ($normalFiles as $file) {
                    $basename = basename($file);
                    $this->line("    - {$basename}");
                }
            }

            if (! empty($errorFiles)) {
                $this->line('  Error logs:');
                foreach ($errorFiles as $file) {
                    $basename = basename($file);
                    $this->line("    - {$basename}");
                }
            }
        }
    }

    private function getLogTypeDescription(): string
    {
        if ($this->option('errors')) {
            return 'error';
        } elseif ($this->option('all')) {
            return 'all';
        } else {
            return 'normal';
        }
    }

    private function buildFilters(): ?string
    {
        $filters = [];

        if ($taskName = $this->option('task-name')) {
            $filters[] = '"task_name":"[^"]*'.preg_quote($taskName, '/').'[^"]*"';
        }

        if ($taskId = $this->option('task-id')) {
            $filters[] = '"task_id":'.preg_quote($taskId, '/');
        }

        if ($backupName = $this->option('backup-name')) {
            $filters[] = '"backup_name":"[^"]*'.preg_quote($backupName, '/').'[^"]*"';
        }

        if ($backupId = $this->option('backup-id')) {
            $filters[] = '"backup_id":'.preg_quote($backupId, '/');
        }

        return empty($filters) ? null : implode('|', $filters);
    }

    private function getFilterDescription(): string
    {
        $descriptions = [];

        if ($taskName = $this->option('task-name')) {
            $descriptions[] = "task name: {$taskName}";
        }

        if ($taskId = $this->option('task-id')) {
            $descriptions[] = "task ID: {$taskId}";
        }

        if ($backupName = $this->option('backup-name')) {
            $descriptions[] = "backup name: {$backupName}";
        }

        if ($backupId = $this->option('backup-id')) {
            $descriptions[] = "backup ID: {$backupId}";
        }

        return empty($descriptions) ? '' : ' (filtered by '.implode(', ', $descriptions).')';
    }
}
