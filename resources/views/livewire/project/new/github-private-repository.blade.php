<div>
    <div class="flex items-end gap-2">
        <h1>Create a new Application</h1>
        <x-modal-input buttonTitle="+ Add GitHub App" title="New GitHub App" closeOutside="false">
            <livewire:source.github.create />
        </x-modal-input>
    </div>
    <div class="pb-4">Deploy any public or private Git repositories through a GitHub App.</div>
    @if ($repositories->count() > 0)
        <div class="flex items-center gap-2 pb-4">
            <a target="_blank" class="flex hover:no-underline" href="{{ getInstallationPath($github_app) }}">
                <x-forms.button>
                    Change Repositories on GitHub
                    <x-external-link />
                </x-forms.button>
            </a>
            <x-forms.button :showLoadingIndicator="false" wire:click.prevent="loadRepositories({{ $github_app->id }})" title="Refresh Repository List">
                <svg class="w-4 h-4" wire:loading.remove wire:target="loadRepositories({{ $github_app->id }})" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                </svg>
                <svg class="w-4 h-4 animate-spin" wire:loading wire:target="loadRepositories({{ $github_app->id }})" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </x-forms.button>
        </div>
    @endif
    @if ($github_apps->count() !== 0)
        <div class="flex flex-col gap-2">
            @if ($current_step === 'github_apps')
                <h2 class="pt-4 pb-4">Select a Github App</h2>
                <div class="flex flex-col justify-center gap-2 text-left">
                    @foreach ($github_apps as $ghapp)
                        <div class="flex">
                            <div class="w-full gap-2 py-4 group coolbox"
                                wire:click.prevent="loadRepositories({{ $ghapp->id }})"
                                wire:key="{{ $ghapp->id }}">
                                <div class="flex mr-4">
                                    <div class="flex flex-col mx-6">
                                        <div class="box-title">
                                            {{ data_get($ghapp, 'name') }}
                                        </div>
                                        <div class="box-description">
                                            {{ data_get($ghapp, 'html_url') }}</div>
                                    </div>
                                </div>
                            </div>
                            <div class="flex flex-col items-center justify-center">
                                <x-loading wire:loading wire:target="loadRepositories({{ $ghapp->id }})" />
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
            @if ($current_step === 'repository')
                @if ($repositories->count() > 0)
                    <div class="flex flex-col gap-2 pb-6">
                        <div class="flex gap-2">
                            <x-forms.datalist class="w-full" label="Repository" placeholder="Search repositories..." wire:model.live="selected_repository_id">
                                @foreach ($repositories as $repo)
                                    <option value="{{ data_get($repo, 'id') }}">{{ data_get($repo, 'name') }}</option>
                                @endforeach
                            </x-forms.datalist>
                        </div>
                        <x-forms.button wire:click.prevent="loadBranches"> Load Repository </x-forms.button>
                    </div>
                @else
                    <div>No repositories found. Check your GitHub App configuration.</div>
                @endif
                @if ($branches->count() > 0)
                    <h2 class="text-lg font-bold">Configuration</h2>
                    <div class="flex flex-col gap-2 pb-6">
                        <form class="flex flex-col" wire:submit='submit'>
                            <div class="flex flex-col gap-2 pb-6">
                                <div class="flex gap-2">
                                    <x-forms.select id="selected_branch_name" label="Branch">
                                        <option value="default" disabled selected>Select a branch</option>
                                        @foreach ($branches as $branch)
                                            @if ($loop->first)
                                                <option selected value="{{ data_get($branch, 'name') }}">
                                                    {{ data_get($branch, 'name') }}
                                                </option>
                                            @else
                                                <option value="{{ data_get($branch, 'name') }}">
                                                    {{ data_get($branch, 'name') }}
                                                </option>
                                            @endif
                                        @endforeach
                                    </x-forms.select>
                                    <x-forms.select wire:model.live="build_pack" label="Build Pack" required>
                                        <option value="nixpacks">Nixpacks</option>
                                        <option value="static">Static</option>
                                        <option value="dockerfile">Dockerfile</option>
                                        <option value="dockercompose">Docker Compose</option>
                                    </x-forms.select>
                                    @if ($is_static)
                                        <x-forms.input id="publish_directory" label="Publish Directory"
                                            helper="If there is a build process involved (like Svelte, React, Next, etc..), please specify the output directory for the build assets." />
                                    @endif
                                </div>
                                @if ($build_pack === 'dockercompose')
                                    <div x-data="{
                                        baseDir: '{{ $base_directory }}',
                                        composeLocation: '{{ $docker_compose_location }}',
                                        normalizePath(path) {
                                            if (!path || path.trim() === '') return '/';
                                            path = path.trim();
                                            // Remove trailing slashes
                                            path = path.replace(/\/+$/, '');
                                            // Ensure leading slash
                                            if (!path.startsWith('/')) {
                                                path = '/' + path;
                                            }
                                            return path;
                                        },
                                        normalizeBaseDir() {
                                            this.baseDir = this.normalizePath(this.baseDir);
                                        },
                                        normalizeComposeLocation() {
                                            this.composeLocation = this.normalizePath(this.composeLocation);
                                        }
                                    }" class="gap-2 flex flex-col">
                                        <x-forms.input placeholder="/" wire:model.defer="base_directory"
                                            label="Base Directory"
                                            helper="Directory to use as root. Useful for monorepos." x-model="baseDir"
                                            @blur="normalizeBaseDir()" />
                                        <x-forms.input placeholder="/docker-compose.yaml"
                                            wire:model.defer="docker_compose_location" label="Docker Compose Location"
                                            helper="It is calculated together with the Base Directory."
                                            x-model="composeLocation" @blur="normalizeComposeLocation()" />
                                        <div class="pt-2">
                                            <span>
                                                Compose file location in your repository: </span><span
                                                class='dark:text-warning'
                                                x-text='(baseDir === "/" ? "" : baseDir) + (composeLocation.startsWith("/") ? composeLocation : "/" + composeLocation)'></span>
                                        </div>
                                    </div>
                                @else
                                    <x-forms.input wire:model="base_directory" label="Base Directory"
                                        helper="Directory to use as root. Useful for monorepos." />
                                @endif
                                @if ($show_is_static)
                                    <x-forms.input type="number" id="port" label="Port" :readonly="$is_static || $build_pack === 'static'"
                                        helper="The port your application listens on." />
                                    <div class="w-52">
                                        <x-forms.checkbox instantSave id="is_static" label="Is it a static site?"
                                            helper="If your application is a static site or the final build assets should be served as a static site, enable this." />
                                    </div>
                                @endif
                            </div>
                            <x-forms.button type="submit">
                                Continue
                            </x-forms.button>
                @endif
            @endif
        </div>
    @else
        <div class="hero">
            No GitHub Application found. Please create a new GitHub Application.
        </div>
    @endif
</div>
