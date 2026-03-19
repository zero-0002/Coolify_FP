<?php

namespace App\Livewire\Storage;

use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use Livewire\Component;

class Resources extends Component
{
    public S3Storage $storage;

    public function render()
    {
        $backups = ScheduledDatabaseBackup::where('s3_storage_id', $this->storage->id)
            ->with('database')
            ->get();

        return view('livewire.storage.resources', [
            'backups' => $backups,
        ]);
    }
}
