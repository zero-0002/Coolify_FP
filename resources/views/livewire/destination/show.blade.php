<div>
    <form class="flex flex-col">
        <div class="flex items-center gap-2">
            <h1>Destination</h1>
            <x-forms.button canGate="update" :canResource="$destination" wire:click.prevent='submit'
                type="submit">Save</x-forms.button>
            @if ($network !== 'coolify')
                <x-modal-confirmation title="Confirm Destination Deletion?" buttonTitle="Delete Destination" isErrorButton
                    submitAction="delete" :actions="['This will delete the selected destination/network.']" confirmationText="{{ $destination->name }}"
                    confirmationLabel="Please confirm the execution of the actions by entering the Destination Name below"
                    shortConfirmationLabel="Destination Name" :confirmWithPassword="false" step2ButtonText="Permanently Delete" 
                    canGate="delete" :canResource="$destination" />
            @endif
        </div>

        @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
            <div class="subtitle ">A simple Docker network.</div>
        @else
            <div class="subtitle flex items-center gap-2">A swarm Docker network.
                <x-deprecated-badge />
            </div>
        @endif
        <div class="flex gap-2">
            <x-forms.input canGate="update" :canResource="$destination" id="name" label="Name" />
            <x-forms.input id="serverIp" label="Server IP" readonly />
            @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
                <x-forms.input id="network" label="Docker Network" readonly />
            @endif
        </div>
    </form>

    @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
        <div class="pt-6">
            <h3>Resources</h3>
            <div class="pb-2 text-xs opacity-70">Applications, services, and databases deployed to this network.</div>
            @if (count($resources) === 0)
                <div class="text-xs opacity-70 pt-2">No resources are using this destination.</div>
            @else
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2 pt-2">
                    @foreach ($resources as $row)
                        <a href="{{ $row['url'] }}"
                            class="relative flex flex-col dark:text-white coolbox group cursor-pointer">
                            <div class="box-title">{{ ucfirst($row['type']) }}: {{ $row['name'] }}</div>
                            <div class="box-description">{{ $row['project'] }} / {{ $row['environment'] }}</div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
