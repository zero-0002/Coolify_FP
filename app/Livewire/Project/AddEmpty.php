<?php

namespace App\Livewire\Project;

use App\Models\Project;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class AddEmpty extends Component
{
    #[Validate(['required', 'string', 'min:3', 'max:255', 'regex:/^[a-zA-Z0-9\s\-_.]+$/'])]
    public string $name;

    #[Validate(['nullable', 'string'])]
    public string $description = '';

    protected $messages = [
        'name.regex' => 'The name may only contain letters, numbers, spaces, dashes, underscores, and dots.',
        'name.min' => 'The name must be at least 3 characters.',
        'name.max' => 'The name may not be greater than 255 characters.',
    ];

    public function submit()
    {
        try {
            $this->validate();
            $project = Project::create([
                'name' => $this->name,
                'description' => $this->description,
                'team_id' => currentTeam()->id,
                'uuid' => (string) new Cuid2,
            ]);

            return redirect()->route('project.show', $project->uuid);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
