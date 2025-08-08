<div x-data="{
    dropdownOpen: false,
    search: '',
    allEntries: [],
    init() {
        this.mounted();
        // Load all entries when component initializes
        this.allEntries = @js($entries->toArray());
    },
    markEntryAsRead(version) {
        // Update the entry in our local Alpine data
        const entry = this.allEntries.find(e => e.version === version);
        if (entry) {
            entry.is_read = true;
        }
        // Call Livewire to update server-side
        $wire.markAsRead(version);
    },
    markAllEntriesAsRead() {
        // Update all entries in our local Alpine data
        this.allEntries.forEach(entry => {
            entry.is_read = true;
        });
        // Call Livewire to update server-side
        $wire.markAllAsRead();
    },
    switchWidth() {
        if (this.full === 'full') {
            localStorage.setItem('pageWidth', 'center');
        } else {
            localStorage.setItem('pageWidth', 'full');
        }
        window.location.reload();
    },
    setZoom(zoom) {
        localStorage.setItem('zoom', zoom);
        window.location.reload();
    },
    setTheme(type) {
        this.theme = type;
        localStorage.setItem('theme', type);
        this.queryTheme();
    },
    queryTheme() {
        const darkModePreference = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const userSettings = localStorage.getItem('theme') || 'dark';
        localStorage.setItem('theme', userSettings);
        if (userSettings === 'dark') {
            document.documentElement.classList.add('dark');
            this.theme = 'dark';
        } else if (userSettings === 'light') {
            document.documentElement.classList.remove('dark');
            this.theme = 'light';
        } else if (darkModePreference) {
            this.theme = 'system';
            document.documentElement.classList.add('dark');
        } else if (!darkModePreference) {
            this.theme = 'system';
            document.documentElement.classList.remove('dark');
        }
    },
    mounted() {
        this.full = localStorage.getItem('pageWidth');
        this.zoom = localStorage.getItem('zoom');
        this.queryTheme();
    },
    get filteredEntries() {
        let entries = this.allEntries;

        // Apply search filter if search term exists
        if (this.search && this.search.trim() !== '') {
            const searchLower = this.search.trim().toLowerCase();
            entries = entries.filter(entry => {
                return (entry.title?.toLowerCase().includes(searchLower) ||
                    entry.content?.toLowerCase().includes(searchLower) ||
                    entry.version?.toLowerCase().includes(searchLower));
            });
        }

        // Always sort: unread first, then by published date (newest first)
        return entries.sort((a, b) => {
            // First sort by read status (unread first)
            if (a.is_read !== b.is_read) {
                return a.is_read ? 1 : -1; // unread (false) comes before read (true)
            }
            // Then sort by published date (newest first)
            return new Date(b.published_at) - new Date(a.published_at);
        });
    }
}" @click.outside="dropdownOpen = false">
    <!-- Custom Dropdown without arrow -->
    <div class="relative">
        <button @click="dropdownOpen = !dropdownOpen"
            class="relative p-2 dark:text-neutral-400 hover:dark:text-white transition-colors cursor-pointer"
            title="Settings">
            <!-- Gear Icon -->
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" title="Settings">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>

            <!-- Unread Count Badge -->
            @if ($unreadCount > 0)
                <span
                    class="absolute -top-1 -right-1 bg-error text-white text-xs rounded-full w-4 h-4 flex items-center justify-center">
                    {{ $unreadCount > 9 ? '9+' : $unreadCount }}
                </span>
            @endif
        </button>

        <!-- Dropdown Menu -->
        <div x-show="dropdownOpen" x-transition:enter="ease-out duration-200"
            x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 -translate-y-2" class="absolute right-0 top-full mt-1 z-50 w-48" x-cloak>
            <div
                class="p-1 bg-white border rounded-sm shadow-lg dark:bg-coolgray-200 dark:border-black border-neutral-300">
                <div class="flex flex-col gap-1">
                    <!-- What's New Section -->
                    @if ($unreadCount > 0)
                        <button wire:click="openWhatsNewModal" @click="dropdownOpen = false"
                            class="px-1 dropdown-item-no-padding flex items-center justify-between">
                            <span>What's New</span>
                            <span
                                class="bg-error text-white text-xs rounded-full w-5 h-5 flex items-center justify-center">
                                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
                            </span>
                        </button>
                    @else
                        <button wire:click="openWhatsNewModal" @click="dropdownOpen = false"
                            class="px-1 dropdown-item-no-padding">
                            Changelog
                        </button>
                    @endif

                    <!-- Divider -->
                    <div class="border-b dark:border-coolgray-500 border-neutral-300"></div>

                    <!-- Theme Section -->
                    <div class="font-bold border-b dark:border-coolgray-500 border-neutral-300 dark:text-white pb-1">
                        Appearance</div>
                    <button @click="setTheme('dark'); dropdownOpen = false"
                        class="px-1 dropdown-item-no-padding">Dark</button>
                    <button @click="setTheme('light'); dropdownOpen = false"
                        class="px-1 dropdown-item-no-padding">Light</button>
                    <button @click="setTheme('system'); dropdownOpen = false"
                        class="px-1 dropdown-item-no-padding">System</button>

                    <!-- Width Section -->
                    <div
                        class="my-1 font-bold border-b dark:border-coolgray-500 border-neutral-300 dark:text-white text-md">
                        Width</div>
                    <button @click="switchWidth(); dropdownOpen = false" class="px-1 dropdown-item-no-padding"
                        x-show="full === 'full'">Center</button>
                    <button @click="switchWidth(); dropdownOpen = false" class="px-1 dropdown-item-no-padding"
                        x-show="full === 'center'">Full</button>

                    <!-- Zoom Section -->
                    <div
                        class="my-1 font-bold border-b dark:border-coolgray-500 border-neutral-300 dark:text-white text-md">
                        Zoom</div>
                    <button @click="setZoom(100); dropdownOpen = false"
                        class="px-1 dropdown-item-no-padding">100%</button>
                    <button @click="setZoom(90); dropdownOpen = false"
                        class="px-1 dropdown-item-no-padding">90%</button>
                </div>
            </div>
        </div>
    </div>

    <!-- What's New Modal -->
    @if ($showWhatsNewModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-6 sm:p-8">
            <!-- Background overlay -->
            <div class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs" wire:click="closeWhatsNewModal">
            </div>

            <!-- Modal panel -->
            <div
                class="relative w-full max-w-4xl py-6 border rounded-sm drop-shadow-sm bg-white border-neutral-200 dark:bg-base px-6 dark:border-coolgray-300">
                <!-- Header -->
                <div class="flex items-center justify-between  pb-3">
                    <div>
                        <h3 class="text-2xl font-bold dark:text-white">
                            Changelog
                        </h3>
                        <p class="mt-1 text-sm dark:text-neutral-400">
                            Stay up to date with the latest features and improvements.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        @if ($unreadCount > 0)
                            <x-forms.button @click="markAllEntriesAsRead">
                                Mark all as read
                            </x-forms.button>
                        @endif
                        <button wire:click="closeWhatsNewModal"
                            class="flex items-center justify-center w-8 h-8 rounded-full dark:text-white hover:bg-neutral-100 dark:hover:bg-coolgray-300 outline-0 cursor-pointer">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Search -->
                <div class="pb-4 border-b dark:border-coolgray-200">
                    <div class="relative">
                        <input x-model="search" placeholder="Search updates..." class="input pl-10" />
                        <svg class="absolute left-3 top-2 w-4 h-4 dark:text-neutral-400" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                </div>

                <!-- Content -->
                <div class="py-4 max-h-96 overflow-y-auto scrollbar">
                    <div x-show="filteredEntries.length > 0">
                        <div class="space-y-4">
                            <template x-for="entry in filteredEntries" :key="entry.version">
                                <div class="relative p-4 border dark:border-coolgray-300 rounded-sm"
                                    :class="!entry.is_read ? 'dark:bg-coolgray-200 border-warning' : 'dark:bg-coolgray-100'">
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-2 mb-2">
                                                <span x-show="entry.version"
                                                    class="px-2 py-1 text-xs font-semibold dark:bg-coolgray-300 dark:text-neutral-200 rounded-sm"
                                                    x-text="entry.version"></span>
                                                <span class="text-xs dark:text-neutral-400 font-bold"
                                                    x-text="entry.title"></span>
                                                <span class="text-xs dark:text-neutral-400"
                                                    x-text="new Date(entry.published_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })"></span>
                                            </div>
                                            <div class="dark:text-neutral-300 leading-relaxed  max-w-none"
                                                x-html="entry.content_html">
                                            </div>
                                        </div>

                                        <button x-show="!entry.is_read" @click="markEntryAsRead(entry.version)"
                                            class="ml-4 px-3 py-1 text-xs dark:text-neutral-400 hover:dark:text-white border dark:border-neutral-600 rounded hover:dark:bg-neutral-700 transition-colors cursor-pointer"
                                            title="Mark as read">
                                            mark as read
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div x-show="filteredEntries.length === 0" class="text-center py-8">
                        <svg class="mx-auto h-12 w-12 dark:text-neutral-400" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <h3 class="mt-2 text-sm font-medium dark:text-white">No updates found</h3>
                        <p class="mt-1 text-sm dark:text-neutral-400">
                            <span x-show="search.trim() !== ''">No updates match your search criteria.</span>
                            <span x-show="search.trim() === ''">There are no updates available at the moment.</span>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
