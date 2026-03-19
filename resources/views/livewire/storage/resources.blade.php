<div>
    <div class="grid gap-4 lg:grid-cols-2">
        @forelse ($backups as $backup)
            @php
                $database = $backup->database;
                $databaseName = $database?->name ?? 'Deleted database';
                $link = null;
                if ($database && $database instanceof \App\Models\ServiceDatabase) {
                    $service = $database->service;
                    if ($service) {
                        $environment = $service->environment;
                        $project = $environment?->project;
                        if ($project && $environment) {
                            $link = route('project.service.configuration', [
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
                        $link = route('project.database.backup.index', [
                            'project_uuid' => $project->uuid,
                            'environment_uuid' => $environment->uuid,
                            'database_uuid' => $database->uuid,
                        ]);
                    }
                }
            @endphp
            @if ($link)
                <a {{ wireNavigate() }} href="{{ $link }}" @class(['gap-2 border cursor-pointer coolbox group'])>
            @else
                <div @class(['gap-2 border coolbox'])>
            @endif
                <div class="flex flex-col justify-center mx-6">
                    <div class="box-title">
                        {{ $databaseName }}
                    </div>
                    <div class="box-description">
                        Frequency: {{ $backup->frequency }}
                    </div>
                    @if (!$backup->enabled)
                        <span class="px-2 py-1 text-xs font-semibold text-yellow-800 bg-yellow-100 rounded dark:text-yellow-100 dark:bg-yellow-800 w-fit">
                            Disabled
                        </span>
                    @endif
                </div>
            @if ($link)
                </a>
            @else
                </div>
            @endif
        @empty
            <div>
                <div>No backup schedules are using this storage.</div>
            </div>
        @endforelse
    </div>
</div>
