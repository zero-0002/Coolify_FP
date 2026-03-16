<?php

/**
 * Tests for GitHub issue #7802: volume mappings from repo content in Preview Deployments.
 *
 * Bind mount volumes use a per-volume `is_preview_suffix_enabled` setting to control
 * whether the -pr-N suffix is applied during preview deployments.
 * When enabled (default), the suffix is applied for data isolation.
 * When disabled, the volume path is shared with the main deployment.
 * Named Docker volumes also respect this setting.
 */
it('uses is_preview_suffix_enabled setting for bind mount suffix in preview deployments', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Find the bind mount handling block (type === 'bind')
    $bindBlockStart = strpos($parsersFile, "if (\$type->value() === 'bind')");
    $volumeBlockStart = strpos($parsersFile, "} elseif (\$type->value() === 'volume')");
    $bindBlock = substr($parsersFile, $bindBlockStart, $volumeBlockStart - $bindBlockStart);

    // Bind mount block should check is_preview_suffix_enabled before applying suffix
    expect($bindBlock)
        ->toContain('$isPreviewSuffixEnabled')
        ->toContain('is_preview_suffix_enabled')
        ->toContain('addPreviewDeploymentSuffix');
});

it('still applies preview deployment suffix to named volume paths', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Find the named volume handling block (type === 'volume')
    $volumeBlockStart = strpos($parsersFile, "} elseif (\$type->value() === 'volume')");
    $volumeBlock = substr($parsersFile, $volumeBlockStart, 1000);

    // Named volumes SHOULD still get the -pr-N suffix for isolation
    expect($volumeBlock)->toContain('addPreviewDeploymentSuffix');
});

it('confirms addPreviewDeploymentSuffix works correctly', function () {
    $result = addPreviewDeploymentSuffix('myvolume', 3);
    expect($result)->toBe('myvolume-pr-3');

    $result = addPreviewDeploymentSuffix('myvolume', 0);
    expect($result)->toBe('myvolume');
});

/**
 * Tests for GitHub issue #7343: $uuid mutation in label generation leaks into
 * subsequent services' volume paths during preview deployments.
 *
 * The label generation block must use a local variable ($labelUuid) instead of
 * mutating the shared $uuid variable, which is used for volume base paths.
 */
it('does not mutate shared uuid variable during label generation', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Find the FQDN label generation block
    $labelBlockStart = strpos($parsersFile, '$shouldGenerateLabelsExactly = $resource->destination->server->settings->generate_exact_labels;');
    $labelBlock = substr($parsersFile, $labelBlockStart, 300);

    // Should use $labelUuid, not mutate $uuid
    expect($labelBlock)
        ->toContain('$labelUuid = $resource->uuid')
        ->not->toContain('$uuid = $resource->uuid')
        ->not->toContain("\$uuid = \"{$resource->uuid}");
});

it('uses labelUuid in all proxy label generation calls', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Find the FQDN label generation block (from shouldGenerateLabelsExactly to the closing brace)
    $labelBlockStart = strpos($parsersFile, '$shouldGenerateLabelsExactly');
    $labelBlockEnd = strpos($parsersFile, "data_forget(\$service, 'volumes.*.content')");
    $labelBlock = substr($parsersFile, $labelBlockStart, $labelBlockEnd - $labelBlockStart);

    // All uuid references in label functions should use $labelUuid
    expect($labelBlock)
        ->toContain('uuid: $labelUuid')
        ->not->toContain('uuid: $uuid');
});

it('checks is_preview_suffix_enabled in deployment job for persistent volumes', function () {
    $deploymentJobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    // Find the generate_local_persistent_volumes method
    $methodStart = strpos($deploymentJobFile, 'function generate_local_persistent_volumes()');
    $methodEnd = strpos($deploymentJobFile, 'function generate_local_persistent_volumes_only_volume_names()');
    $methodBlock = substr($deploymentJobFile, $methodStart, $methodEnd - $methodStart);

    // Should check is_preview_suffix_enabled before applying suffix
    expect($methodBlock)
        ->toContain('is_preview_suffix_enabled')
        ->toContain('$isPreviewSuffixEnabled')
        ->toContain('addPreviewDeploymentSuffix');
});

it('checks is_preview_suffix_enabled in deployment job for volume names', function () {
    $deploymentJobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    // Find the generate_local_persistent_volumes_only_volume_names method
    $methodStart = strpos($deploymentJobFile, 'function generate_local_persistent_volumes_only_volume_names()');
    $methodEnd = strpos($deploymentJobFile, 'function generate_healthcheck_commands()');
    $methodBlock = substr($deploymentJobFile, $methodStart, $methodEnd - $methodStart);

    // Should check is_preview_suffix_enabled before applying suffix
    expect($methodBlock)
        ->toContain('is_preview_suffix_enabled')
        ->toContain('$isPreviewSuffixEnabled')
        ->toContain('addPreviewDeploymentSuffix');
});
