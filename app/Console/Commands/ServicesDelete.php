<?php

namespace App\Console\Commands;

use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

class ServicesDelete extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'services:delete';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete a service from the database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $resource = select(
            'What service do you want to delete?',
            ['Application', 'Database', 'Service', 'Server'],
        );
        if ($resource === 'Application') {
            $this->deleteApplication();
        } elseif ($resource === 'Database') {
            $this->deleteDatabase();
        } elseif ($resource === 'Service') {
            $this->deleteService();
        } elseif ($resource === 'Server') {
            $this->deleteServer();
        }
    }

    private function deleteServer()
    {
        $servers = Server::all();
        if ($servers->count() === 0) {
            $this->error('There are no applications to delete.');

            return;
        }
        $serversToDelete = multiselect(
            label: 'What server do you want to delete?',
            options: $servers->pluck('name', 'id')->sortKeys(),
        );

        foreach ($serversToDelete as $server) {
            $toDelete = $servers->where('id', $server)->first();
            if ($toDelete) {
                $this->info($toDelete);
                $confirmed = confirm('Are you sure you want to delete all selected resources?');
                if (! $confirmed) {
                    break;
                }
                $toDelete->delete();
            }
        }
    }

    private function deleteApplication()
    {
        $applications = Application::all();
        if ($applications->count() === 0) {
            $this->error('There are no applications to delete.');

            return;
        }
        $applicationsToDelete = multiselect(
            'What application do you want to delete?',
            $applications->pluck('name', 'id')->sortKeys(),
        );

        foreach ($applicationsToDelete as $application) {
            $toDelete = $applications->where('id', $application)->first();
            if ($toDelete) {
                $this->info($toDelete);
                $confirmed = confirm('Are you sure you want to delete all selected resources? ');
                if (! $confirmed) {
                    break;
                }
                DeleteResourceJob::dispatch($toDelete);
            }
        }
    }

    private function deleteDatabase()
    {
        // Collect all databases from all types
        $allDatabases = collect()
            ->merge(StandalonePostgresql::all()->map(fn ($db) => (object) ['id' => $db->id, 'name' => $db->name, 'type' => 'PostgreSQL', 'model' => $db]))
            ->merge(StandaloneMysql::all()->map(fn ($db) => (object) ['id' => $db->id, 'name' => $db->name, 'type' => 'MySQL', 'model' => $db]))
            ->merge(StandaloneMariadb::all()->map(fn ($db) => (object) ['id' => $db->id, 'name' => $db->name, 'type' => 'MariaDB', 'model' => $db]))
            ->merge(StandaloneMongodb::all()->map(fn ($db) => (object) ['id' => $db->id, 'name' => $db->name, 'type' => 'MongoDB', 'model' => $db]))
            ->merge(StandaloneRedis::all()->map(fn ($db) => (object) ['id' => $db->id, 'name' => $db->name, 'type' => 'Redis', 'model' => $db]))
            ->merge(StandaloneKeydb::all()->map(fn ($db) => (object) ['id' => $db->id, 'name' => $db->name, 'type' => 'KeyDB', 'model' => $db]))
            ->merge(StandaloneDragonfly::all()->map(fn ($db) => (object) ['id' => $db->id, 'name' => $db->name, 'type' => 'Dragonfly', 'model' => $db]))
            ->merge(StandaloneClickhouse::all()->map(fn ($db) => (object) ['id' => $db->id, 'name' => $db->name, 'type' => 'ClickHouse', 'model' => $db]));

        if ($allDatabases->count() === 0) {
            $this->error('There are no databases to delete.');

            return;
        }

        // Create options with type information for better UX
        $databaseOptions = $allDatabases->mapWithKeys(function ($db) {
            return [$db->id => "{$db->name} ({$db->type})"];
        })->sortKeys();

        $databasesToDelete = multiselect(
            'What database do you want to delete?',
            $databaseOptions,
        );

        foreach ($databasesToDelete as $databaseId) {
            $toDelete = $allDatabases->where('id', $databaseId)->first();
            if ($toDelete) {
                $this->info("{$toDelete->name} ({$toDelete->type})");
                $confirmed = confirm('Are you sure you want to delete all selected resources?');
                if (! $confirmed) {
                    return;
                }
                DeleteResourceJob::dispatch($toDelete->model);
            }
        }
    }

    private function deleteService()
    {
        $services = Service::all();
        if ($services->count() === 0) {
            $this->error('There are no services to delete.');

            return;
        }
        $servicesToDelete = multiselect(
            'What service do you want to delete?',
            $services->pluck('name', 'id')->sortKeys(),
        );

        foreach ($servicesToDelete as $service) {
            $toDelete = $services->where('id', $service)->first();
            if ($toDelete) {
                $this->info($toDelete);
                $confirmed = confirm('Are you sure you want to delete all selected resources?');
                if (! $confirmed) {
                    return;
                }
                DeleteResourceJob::dispatch($toDelete);
            }
        }
    }
}
