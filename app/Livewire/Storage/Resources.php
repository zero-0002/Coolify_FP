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
            ->where('save_s3', true)
            ->with('database')
            ->get()
            ->groupBy(fn ($backup) => $backup->database_type.'-'.$backup->database_id);

        return view('livewire.storage.resources', [
            'groupedBackups' => $backups,
        ]);
    }
}
