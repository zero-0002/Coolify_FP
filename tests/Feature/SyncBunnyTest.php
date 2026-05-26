<?php

use Illuminate\Support\Facades\Http;

function createFakeSyncBunnyBinary(string $binDir, string $name, string $contents): void
{
    file_put_contents("{$binDir}/{$name}", $contents);
    chmod("{$binDir}/{$name}", 0755);
}

it('syncs nightly files to the nested nightly json path in the cdn repository', function (string $option, string $confirmation, bool $syncsReleases) {
    Http::fake([
        'api.github.com/repos/coollabsio/coolify/releases*' => Http::response([], 200),
    ]);

    $binDir = sys_get_temp_dir().'/sync-bunny-bin-'.uniqid();
    $logFile = sys_get_temp_dir().'/sync-bunny-'.uniqid().'.log';

    mkdir($binDir, 0755, true);

    createFakeSyncBunnyBinary($binDir, 'gh', <<<'SH'
#!/bin/sh
printf 'gh %s\n' "$*" >> "$SYNC_BUNNY_TEST_LOG"
if [ "$1" = "repo" ] && [ "$2" = "clone" ]; then
    mkdir -p "$4/json/nightly"
    printf '{}' > "$4/json/releases.json"
    printf '{}' > "$4/json/nightly/versions.json"
fi
exit 0
SH);

    createFakeSyncBunnyBinary($binDir, 'git', <<<'SH'
#!/bin/sh
printf 'git %s\n' "$*" >> "$SYNC_BUNNY_TEST_LOG"
if [ "$1" = "status" ]; then
    printf 'M %s\n' "$3"
fi
exit 0
SH);

    $originalPath = getenv('PATH') ?: '';
    putenv("PATH={$binDir}:{$originalPath}");
    putenv("SYNC_BUNNY_TEST_LOG={$logFile}");

    try {
        $this->artisan("sync:bunny {$option} --nightly")
            ->expectsConfirmation($confirmation, 'yes')
            ->assertExitCode(0);
    } finally {
        putenv("PATH={$originalPath}");
        putenv('SYNC_BUNNY_TEST_LOG');
    }

    $log = file_get_contents($logFile);

    expect($log)
        ->toContain('json/nightly/versions.json')
        ->not->toContain('json/versions-nightly.json');

    if ($syncsReleases) {
        expect($log)
            ->toContain('json/nightly/releases.json')
            ->not->toContain('git add json/releases.json');
    }
})->with([
    'release sync with releases' => ['--release', 'Are you sure you want to proceed?', true],
    'versions-only github sync' => ['--github-versions', 'Are you sure you want to sync versions.json via GitHub PR?', false],
]);
