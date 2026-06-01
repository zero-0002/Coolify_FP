<form wire:submit='submit' class="flex flex-col">
    <div class="flex items-center gap-2">
        <h2>Healthcheck</h2>
        <x-forms.button canGate="update" :canResource="$database" type="submit">Save</x-forms.button>
    </div>
    <div class="mt-1 pb-4">Configure how Docker checks this database's health. A higher interval lowers
        <code>dockerd</code>/<code>containerd</code> CPU and load on servers running many databases. Restart the
        database to apply changes.</div>
    <div class="flex flex-col gap-4">
        <x-forms.checkbox canGate="update" :canResource="$database" instantSave id="healthCheckEnabled"
            label="Enabled"
            helper="When disabled, Docker runs no healthcheck probe for this database and Coolify can no longer report a healthy/unhealthy state." />
        @if ($healthCheckEnabled)
            <div class="flex gap-2">
                <x-forms.input canGate="update" :canResource="$database" min="1" type="number" id="healthCheckInterval"
                    placeholder="15" label="Interval (s)" required />
                <x-forms.input canGate="update" :canResource="$database" min="1" type="number" id="healthCheckTimeout"
                    placeholder="5" label="Timeout (s)" required />
                <x-forms.input canGate="update" :canResource="$database" min="1" type="number" id="healthCheckRetries"
                    placeholder="5" label="Retries" required />
                <x-forms.input canGate="update" :canResource="$database" min="0" type="number"
                    id="healthCheckStartPeriod" placeholder="5" label="Start Period (s)" required />
            </div>
        @endif
    </div>
</form>
