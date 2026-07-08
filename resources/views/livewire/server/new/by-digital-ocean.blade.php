<div class="w-full">
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @else
        @if ($current_step === 1)
            <div class="flex flex-col w-full gap-4">
                @if ($available_tokens->count() > 0)
                    <div class="flex gap-2">
                        <div class="flex-1">
                            <x-forms.select label="Select DigitalOcean Token" id="selected_token_id"
                                wire:change="selectToken($event.target.value)" required>
                                <option value="">Select a saved token...</option>
                                @foreach ($available_tokens as $token)
                                    <option value="{{ $token->id }}">
                                        {{ $token->name ?? 'DigitalOcean Token' }}
                                    </option>
                                @endforeach
                            </x-forms.select>
                        </div>
                        <div class="flex items-end">
                            <x-forms.button canGate="create" :canResource="App\Models\Server::class" wire:click="nextStep"
                                :disabled="!$selected_token_id">
                                Continue
                            </x-forms.button>
                        </div>
                    </div>

                    <div class="text-center text-sm dark:text-neutral-500">OR</div>
                @endif

                <x-modal-input isFullWidth
                    buttonTitle="{{ $available_tokens->count() > 0 ? '+ Add New Token' : 'Add DigitalOcean Token' }}"
                    title="Add DigitalOcean Token">
                    <livewire:security.cloud-provider-token-form :modal_mode="true" provider="digitalocean" />
                </x-modal-input>
            </div>
        @elseif ($current_step === 2)
            @if ($loading_data)
                <div class="flex items-center justify-center py-8">
                    <div class="text-center">
                        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary mx-auto"></div>
                        <p class="mt-4 text-sm dark:text-neutral-400">Loading DigitalOcean data...</p>
                    </div>
                </div>
            @else
                <form class="flex flex-col w-full gap-2" wire:submit='submit'>
                    <div>
                        <x-forms.input id="server_name" label="Server Name" helper="A friendly name for your server." />
                    </div>

                    <div>
                        <x-forms.select label="Region" id="selected_region" wire:model.live="selected_region" required>
                            <option value="">Select a region...</option>
                            @foreach ($regions as $region)
                                <option value="{{ $region['slug'] }}">
                                    {{ $region['name'] ?? $region['slug'] }}
                                </option>
                            @endforeach
                        </x-forms.select>
                    </div>

                    <div>
                        <x-forms.select label="Size" id="selected_size" wire:model.live="selected_size" required
                            :disabled="!$selected_region">
                            <option value="">
                                {{ $selected_region ? 'Select a size...' : 'Select a region first' }}
                            </option>
                            @foreach ($this->availableSizes as $size)
                                <option value="{{ $size['slug'] }}">
                                    {{ $size['slug'] }} - {{ $size['memory'] ?? '?' }}MB RAM,
                                    {{ $size['vcpus'] ?? '?' }} vCPU
                                    @if (isset($size['disk']))
                                        , {{ $size['disk'] }}GB
                                    @endif
                                    @if (isset($size['price_monthly']))
                                        - ${{ number_format((float) $size['price_monthly'], 2) }}/mo
                                    @endif
                                </option>
                            @endforeach
                        </x-forms.select>
                    </div>

                    <div>
                        <x-forms.select label="Image" id="selected_image" required :disabled="!$selected_size">
                            <option value="">
                                {{ $selected_size ? 'Select an image...' : 'Select a size first' }}
                            </option>
                            @foreach ($this->availableImages as $image)
                                <option value="{{ $image['slug'] ?? $image['id'] }}">
                                    {{ trim(($image['distribution'] ?? '') . ' ' . ($image['name'] ?? $image['slug'] ?? $image['id'])) }}
                                </option>
                            @endforeach
                        </x-forms.select>
                    </div>

                    <div>
                        @if ($private_keys->count() === 0)
                            <div class="flex flex-col gap-2">
                                <label class="flex gap-1 items-center mb-1 text-sm font-medium">
                                    Private Key
                                    <x-highlighted text="*" />
                                </label>
                                <div
                                    class="p-4 border border-warning-500 dark:border-warning-600 rounded bg-warning-50 dark:bg-warning-900/10">
                                    <p class="text-sm mb-3 text-neutral-700 dark:text-neutral-300">
                                        No private keys found. You need to create a private key to continue.
                                    </p>
                                    <x-modal-input buttonTitle="Create New Private Key" title="New Private Key" isHighlightedButton>
                                        <livewire:security.private-key.create :modal_mode="true" from="server" />
                                    </x-modal-input>
                                </div>
                            </div>
                        @else
                            <x-forms.select label="Private Key" id="private_key_id" required>
                                <option value="">Select a private key...</option>
                                @foreach ($private_keys as $key)
                                    <option value="{{ $key->id }}">
                                        {{ $key->name }}
                                    </option>
                                @endforeach
                            </x-forms.select>
                            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                This SSH key will be automatically added to your DigitalOcean account and used to access
                                the server.
                            </p>
                        @endif
                    </div>

                    <x-dropdown inline panelClass="max-h-[55vh] overflow-y-auto scrollbar">
                        <x-slot:title>
                            <span class="text-sm font-medium">Advanced DigitalOcean options</span>
                            <span class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">SSH keys, networking, monitoring, and cloud-init.</span>
                            @if (count($this->advancedDigitalOceanOptionsSummary) > 0)
                                <span class="mt-1 flex flex-wrap gap-1.5">
                                    @foreach ($this->advancedDigitalOceanOptionsSummary as $summaryItem)
                                        <span class="rounded bg-neutral-200 px-2 py-0.5 text-xs text-neutral-700 dark:bg-coolgray-100 dark:text-neutral-300">
                                            {{ $summaryItem }}
                                        </span>
                                    @endforeach
                                </span>
                            @endif
                        </x-slot>

                        <div class="flex w-full flex-col gap-4 p-3">
                            <div>
                                <x-forms.datalist label="Extra SSH Keys" id="selectedDigitalOceanSshKeyIds"
                                    helper="Select existing SSH keys from your DigitalOcean account to add to this Droplet. The Coolify SSH key will be automatically added."
                                    :multiple="true" :disabled="count($digitalOceanSshKeys) === 0" :placeholder="count($digitalOceanSshKeys) > 0
                                        ? 'Search and select SSH keys...'
                                        : 'No SSH keys found in DigitalOcean account'">
                                    @foreach ($digitalOceanSshKeys as $sshKey)
                                        <option value="{{ $sshKey['id'] }}">
                                            {{ $sshKey['name'] ?? $sshKey['fingerprint'] }}
                                            @if (isset($sshKey['fingerprint']))
                                                - {{ substr($sshKey['fingerprint'], 0, 20) }}...
                                            @endif
                                        </option>
                                    @endforeach
                                </x-forms.datalist>
                            </div>

                            <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                                <x-forms.checkbox id="enable_ipv6" label="Enable IPv6"
                                    helper="Enable public IPv6 address for this Droplet" fullWidth />
                                <x-forms.checkbox id="monitoring" label="Enable DigitalOcean Monitoring" fullWidth
                                    helper="Enable DigitalOcean metrics collection for this Droplet." />
                            </div>

                            <div class="flex flex-col gap-2">
                                @if (! $show_cloud_init_script && empty($cloud_init_script) && empty($selected_cloud_init_script_id))
                                    <div>
                                        <x-forms.button type="button" wire:click="showCloudInitScript">
                                            Add cloud-init script
                                        </x-forms.button>
                                    </div>
                                @else
                                    <div class="flex justify-between items-center gap-2">
                                        <label class="text-sm font-medium w-32">Cloud-Init Script</label>
                                        @if ($saved_cloud_init_scripts->count() > 0)
                                            <div class="flex items-center gap-2 flex-1">
                                                <x-forms.select wire:model.live="selected_cloud_init_script_id" label="" helper="">
                                                    <option value="">Load saved script...</option>
                                                    @foreach ($saved_cloud_init_scripts as $script)
                                                        <option value="{{ $script->id }}">{{ $script->name }}</option>
                                                    @endforeach
                                                </x-forms.select>
                                                <x-forms.button type="button" wire:click="clearCloudInitScript">
                                                    Clear
                                                </x-forms.button>
                                            </div>
                                        @else
                                            <x-forms.button type="button" wire:click="clearCloudInitScript">
                                                Remove
                                            </x-forms.button>
                                        @endif
                                    </div>
                                    <x-forms.textarea id="cloud_init_script" label=""
                                        helper="Add a cloud-init script to run when the Droplet is created. See DigitalOcean's cloud-init documentation for details."
                                        rows="8" />

                                    <div class="flex items-center gap-2">
                                        <x-forms.checkbox id="save_cloud_init_script" label="Save this script for later use" />
                                        @if ($save_cloud_init_script)
                                            <div class="flex-1">
                                                <x-forms.input id="cloud_init_script_name" label="" placeholder="Script name..." />
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>
                    </x-dropdown>

                    <div class="flex gap-2 justify-between">
                        <x-forms.button type="button" wire:click="previousStep">
                            Back
                        </x-forms.button>
                        <x-forms.button isHighlighted canGate="create" :canResource="App\Models\Server::class" type="submit"
                            :disabled="!$private_key_id">
                            Buy & Create Server{{ $this->selectedDropletPrice ? ' (' . $this->selectedDropletPrice . '/mo)' : '' }}
                        </x-forms.button>
                    </div>
                </form>
            @endif
        @endif
    @endif
</div>
