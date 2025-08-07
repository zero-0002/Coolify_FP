<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserChangelogRead;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ChangelogService
{
    public function getEntries(int $recentMonths = 3): Collection
    {
        // For backward compatibility, check if old changelog.json exists
        if (file_exists(base_path('changelog.json'))) {
            $data = $this->fetchChangelogData();

            if (! $data || ! isset($data['entries'])) {
                return collect();
            }

            return collect($data['entries'])
                ->filter(fn ($entry) => $this->validateEntryData($entry))
                ->map(function ($entry) {
                    $entry['published_at'] = Carbon::parse($entry['published_at']);
                    $entry['content_html'] = $this->parseMarkdown($entry['content']);

                    return (object) $entry;
                })
                ->filter(fn ($entry) => $entry->published_at <= now())
                ->sortBy('published_at')
                ->reverse()
                ->values();
        }

        // Load entries from recent months for performance
        $availableMonths = $this->getAvailableMonths();
        $monthsToLoad = $availableMonths->take($recentMonths);

        return $monthsToLoad
            ->flatMap(fn ($month) => $this->getEntriesForMonth($month))
            ->sortBy('published_at')
            ->reverse()
            ->values();
    }

    public function getAllEntries(): Collection
    {
        $availableMonths = $this->getAvailableMonths();

        return $availableMonths
            ->flatMap(fn ($month) => $this->getEntriesForMonth($month))
            ->sortBy('published_at')
            ->reverse()
            ->values();
    }

    public function getEntriesForUser(User $user): Collection
    {
        $entries = $this->getEntries();
        $readIdentifiers = UserChangelogRead::getReadIdentifiersForUser($user->id);

        return $entries->map(function ($entry) use ($readIdentifiers) {
            $entry->is_read = in_array($entry->version, $readIdentifiers);

            return $entry;
        })->sortBy([
            ['is_read', 'asc'],  // unread first
            ['published_at', 'desc'],  // then by date
        ])->values();
    }

    public function getUnreadCountForUser(User $user): int
    {
        if (isDev()) {
            $entries = $this->getEntries();
            $readIdentifiers = UserChangelogRead::getReadIdentifiersForUser($user->id);

            return $entries->reject(fn ($entry) => in_array($entry->version, $readIdentifiers))->count();
        } else {
            return Cache::remember(
                'user_unread_changelog_count_'.$user->id,
                now()->addHour(),
                function () use ($user) {
                    $entries = $this->getEntries();
                    $readIdentifiers = UserChangelogRead::getReadIdentifiersForUser($user->id);

                    return $entries->reject(fn ($entry) => in_array($entry->version, $readIdentifiers))->count();
                }
            );
        }
    }

    public function getAvailableMonths(): Collection
    {
        $pattern = base_path('changelogs/*.json');
        $files = glob($pattern);

        if ($files === false) {
            return collect();
        }

        return collect($files)
            ->map(fn ($file) => basename($file, '.json'))
            ->filter(fn ($name) => preg_match('/^\d{4}-\d{2}$/', $name))
            ->sort()
            ->reverse()
            ->values();
    }

    public function getEntriesForMonth(string $month): Collection
    {
        $path = base_path("changelogs/{$month}.json");

        if (! file_exists($path)) {
            return collect();
        }

        $content = file_get_contents($path);

        if ($content === false) {
            Log::error("Failed to read changelog file: {$month}.json");

            return collect();
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("Invalid JSON in {$month}.json: ".json_last_error_msg());

            return collect();
        }

        if (! isset($data['entries']) || ! is_array($data['entries'])) {
            return collect();
        }

        return collect($data['entries'])
            ->filter(fn ($entry) => $this->validateEntryData($entry))
            ->map(function ($entry) {
                $entry['published_at'] = Carbon::parse($entry['published_at']);
                $entry['content_html'] = $this->parseMarkdown($entry['content']);

                return (object) $entry;
            })
            ->filter(fn ($entry) => $entry->published_at <= now())
            ->sortBy('published_at')
            ->reverse()
            ->values();
    }

    private function fetchChangelogData(): ?array
    {
        // Legacy support for old changelog.json
        $path = base_path('changelog.json');

        if (file_exists($path)) {
            $content = file_get_contents($path);

            if ($content === false) {
                Log::error('Failed to read changelog.json file');

                return null;
            }

            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Invalid JSON in changelog.json: '.json_last_error_msg());

                return null;
            }

            return $data;
        }

        // New monthly structure - combine all months
        $allEntries = [];
        foreach ($this->getAvailableMonths() as $month) {
            $monthEntries = $this->getEntriesForMonth($month);
            foreach ($monthEntries as $entry) {
                $allEntries[] = (array) $entry;
            }
        }

        return ['entries' => $allEntries];
    }

    public function markAsReadForUser(string $version, User $user): void
    {
        UserChangelogRead::markAsRead($user->id, $version);
        Cache::forget('user_unread_changelog_count_'.$user->id);
    }

    public function markAllAsReadForUser(User $user): void
    {
        $entries = $this->getEntries();

        foreach ($entries as $entry) {
            UserChangelogRead::markAsRead($user->id, $entry->version);
        }

        Cache::forget('user_unread_changelog_count_'.$user->id);
    }

    private function validateEntryData(array $data): bool
    {
        $required = ['version', 'title', 'content', 'published_at'];

        foreach ($required as $field) {
            if (! isset($data[$field]) || empty($data[$field])) {
                return false;
            }
        }

        return true;
    }

    public function clearAllReadStatus(): array
    {
        try {
            $count = UserChangelogRead::count();
            UserChangelogRead::truncate();

            // Clear all user caches
            $this->clearAllUserCaches();

            return [
                'success' => true,
                'message' => "Successfully cleared {$count} read status records",
            ];
        } catch (\Exception $e) {
            Log::error('Failed to clear read status: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to clear read status: '.$e->getMessage(),
            ];
        }
    }

    private function clearAllUserCaches(): void
    {
        $users = User::select('id')->get();

        foreach ($users as $user) {
            Cache::forget('user_unread_changelog_count_'.$user->id);
        }
    }

    private function parseMarkdown(string $content): string
    {
        // Convert markdown to HTML using simple regex patterns
        $html = $content;

        // Headers
        $html = preg_replace('/^### (.*?)$/m', '<h3 class="text-md font-semibold dark:text-white mb-1">$1</h3>', $html);
        $html = preg_replace('/^## (.*?)$/m', '<h2 class="text-lg font-semibold dark:text-white mb-2">$1</h2>', $html);
        $html = preg_replace('/^# (.*?)$/m', '<h1 class="text-xl font-bold dark:text-white mb-2">$1</h1>', $html);

        // Bold text
        $html = preg_replace('/\*\*(.*?)\*\*/', '<strong class="font-semibold">$1</strong>', $html);
        $html = preg_replace('/__(.*?)__/', '<strong class="font-semibold">$1</strong>', $html);

        // Italic text
        $html = preg_replace('/\*(.*?)\*/', '<em class="italic">$1</em>', $html);
        $html = preg_replace('/_(.*?)_/', '<em class="italic">$1</em>', $html);

        // Code blocks
        $html = preg_replace('/```(.*?)```/s', '<pre class="bg-gray-100 dark:bg-coolgray-300 p-2 rounded text-sm overflow-x-auto my-2"><code>$1</code></pre>', $html);

        // Inline code
        $html = preg_replace('/`([^`]+)`/', '<code class="bg-gray-100 dark:bg-coolgray-300 px-1 py-0.5 rounded text-sm">$1</code>', $html);

        // Links
        $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" class="text-blue-500 hover:text-blue-600 underline" target="_blank" rel="noopener">$1</a>', $html);

        // Line breaks (convert double newlines to paragraphs)
        $paragraphs = preg_split('/\n\s*\n/', trim($html));
        $html = '<p class="mb-2">'.implode('</p><p class="mb-2">', $paragraphs).'</p>';

        // Single line breaks
        $html = preg_replace('/\n/', '<br>', $html);

        // Unordered lists
        $html = preg_replace('/^\- (.*)$/m', '<li class="ml-4">• $1</li>', $html);
        $html = preg_replace('/(<li class="ml-4">.*<\/li>)/s', '<ul class="mb-2">$1</ul>', $html);

        return $html;
    }
}
