<?php

namespace App\Actions\Service;

use App\Models\ServiceApplication;
use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Activitylog\Contracts\Activity;

class DeployServiceApplication
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(ServiceApplication $serviceApplication, bool $pullLatestImages = false, bool $forceRebuild = false): Activity
    {
        $service = $serviceApplication->service;
        $composeServiceName = $serviceApplication->name;

        $service->parse();
        $service->saveComposeConfigs();
        $service->isConfigurationChanged(save: true);

        $workdir = $service->workdir();
        $commands = collect([
            "echo 'Saved configuration files to {$workdir}.'",
            "touch {$workdir}/.env",
        ]);

        if ($pullLatestImages) {
            $commands->push('echo Pulling image for service.');
            $commands->push("docker compose --project-directory {$workdir} -f {$workdir}/docker-compose.yml --project-name {$service->uuid} pull {$composeServiceName}");
        }

        if ($service->networks()->count() > 0) {
            $commands->push('echo Creating Docker network.');
            $commands->push("docker network inspect {$service->uuid} >/dev/null 2>&1 || docker network create --attachable {$service->uuid}");
        }

        $upCommand = "docker compose --project-directory {$workdir} -f {$workdir}/docker-compose.yml --project-name {$service->uuid} up -d --no-deps";
        if ($forceRebuild) {
            $upCommand .= ' --build';
        }
        $upCommand .= " {$composeServiceName}";
        $commands->push('echo Starting service container.');
        $commands->push($upCommand);

        $commands->push("docker network connect {$service->uuid} coolify-proxy >/dev/null 2>&1 || true");

        if (data_get($service, 'connect_to_docker_network')) {
            $compose = data_get($service, 'docker_compose', []);
            $network = $service->destination->network;
            $commands->push("docker network connect --alias {$composeServiceName}-{$service->uuid} {$network} {$composeServiceName}-{$service->uuid} >/dev/null 2>&1 || true");
        }

        return remote_process($commands->toArray(), $service->server, type_uuid: $service->uuid, callEventOnFinish: 'ServiceStatusChanged');
    }
}
