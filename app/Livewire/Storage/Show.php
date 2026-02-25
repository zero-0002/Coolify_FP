<?php

namespace App\Livewire\Storage;

use App\Models\S3Storage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public $storage = null;

    public function mount()
    {
        $this->storage = S3Storage::ownedByCurrentTeam()->whereUuid(request()->storage_uuid)->first();
        if (! $this->storage) {
            abort(404);
        }
        try {
            $this->authorize('view', $this->storage);
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            return $this->redirectRoute('storage.index', navigate: true);
        }
    }

    public function render()
    {
        return view('livewire.storage.show');
    }
}
