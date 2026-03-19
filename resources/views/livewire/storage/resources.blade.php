<div>
    @forelse ($groupedBackups as $backups)
        @php
            $firstBackup = $backups->first();
            $database = $firstBackup->database;
            $databaseName = $database?->name ?? 'Deleted database';
            $resourceLink = null;
            $backupParams = null;
            if ($database && $database instanceof \App\Models\ServiceDatabase) {
                $service = $database->service;
                if ($service) {
                    $environment = $service->environment;
                    $project = $environment?->project;
                    if ($project && $environment) {
                        $resourceLink = route('project.service.configuration', [
                            'project_uuid' => $project->uuid,
                            'environment_uuid' => $environment->uuid,
                            'service_uuid' => $service->uuid,
                        ]);
                    }
                }
            } elseif ($database) {
                $environment = $database->environment;
                $project = $environment?->project;
                if ($project && $environment) {
                    $resourceLink = route('project.database.backup.index', [
                        'project_uuid' => $project->uuid,
                        'environment_uuid' => $environment->uuid,
                        'database_uuid' => $database->uuid,
                    ]);
                    $backupParams = [
                        'project_uuid' => $project->uuid,
                        'environment_uuid' => $environment->uuid,
                        'database_uuid' => $database->uuid,
                    ];
                }
            }
        @endphp
        <div class="pb-6">
            <div class="flex items-center gap-2 pb-2">
                @if ($resourceLink)
                    <a {{ wireNavigate() }} href="{{ $resourceLink }}" class="text-lg font-bold dark:text-white hover:underline">{{ $databaseName }}</a>
                @else
                    <span class="text-lg font-bold dark:text-white">{{ $databaseName }}</span>
                @endif
            </div>
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($backups as $backup)
                    @php
                        $backupLink = null;
                        if ($backupParams) {
                            $backupLink = route('project.database.backup.execution', array_merge($backupParams, [
                                'backup_uuid' => $backup->uuid,
                            ]));
                        }
                    @endphp
                    @if ($backupLink)
                        <a {{ wireNavigate() }} href="{{ $backupLink }}" @class(['gap-2 border cursor-pointer coolbox group'])>
                    @else
                        <div @class(['gap-2 border coolbox'])>
                    @endif
                        <div class="flex flex-col justify-center mx-6">
                            <div class="box-title">{{ $backup->frequency }}</div>
                            @if (!$backup->enabled)
                                <span class="px-2 py-1 text-xs font-semibold text-yellow-800 bg-yellow-100 rounded dark:text-yellow-100 dark:bg-yellow-800 w-fit">
                                    Disabled
                                </span>
                            @endif
                        </div>
                    @if ($backupLink)
                        </a>
                    @else
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @empty
        <div>No backup schedules are using this storage.</div>
    @endforelse
</div>
