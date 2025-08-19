<?php

namespace App\Livewire\Project;

use App\Models\Environment;
use App\Models\Project;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Show extends Component
{
    public Project $project;

    #[Validate(['required', 'string', 'min:3', 'max:255', 'regex:/^[a-zA-Z0-9\s\-_.]+$/'])]
    public string $name;

    #[Validate(['nullable', 'string'])]
    public ?string $description = null;

    protected $messages = [
        'name.regex' => 'The environment name may only contain letters, numbers, spaces, dashes, underscores, and dots.',
        'name.min' => 'The environment name must be at least 3 characters.',
        'name.max' => 'The environment name may not be greater than 255 characters.',
    ];

    public function mount(string $project_uuid)
    {
        try {
            $this->project = Project::where('team_id', currentTeam()->id)->where('uuid', $project_uuid)->firstOrFail();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->validate();
            $environment = Environment::create([
                'name' => $this->name,
                'project_id' => $this->project->id,
                'uuid' => (string) new Cuid2,
            ]);

            return redirect()->route('project.resource.index', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $environment->uuid,
            ]);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function navigateToEnvironment($projectUuid, $environmentUuid)
    {
        return redirect()->route('project.resource.index', [
            'project_uuid' => $projectUuid,
            'environment_uuid' => $environmentUuid,
        ]);
    }

    public function render()
    {
        return view('livewire.project.show');
    }
}
