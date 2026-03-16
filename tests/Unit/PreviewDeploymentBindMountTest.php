<?php

/**
 * Tests for GitHub issue #7802: volume mappings from repo content in Preview Deployments.
 *
 * Bind mount volumes (e.g., ./scripts:/scripts:ro) should NOT get a -pr-N suffix
 * during preview deployments, because the repo files exist at the original path.
 * Only named Docker volumes need the suffix for isolation between PRs.
 */
it('does not apply preview deployment suffix to bind mount source paths', function () {
    // Read the applicationParser volume handling in parsers.php
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Find the bind mount handling block (type === 'bind')
    $bindBlockStart = strpos($parsersFile, "if (\$type->value() === 'bind')");
    $volumeBlockStart = strpos($parsersFile, "} elseif (\$type->value() === 'volume')");
    $bindBlock = substr($parsersFile, $bindBlockStart, $volumeBlockStart - $bindBlockStart);

    // Bind mount paths should NOT be suffixed with -pr-N
    expect($bindBlock)->not->toContain('addPreviewDeploymentSuffix');
});

it('still applies preview deployment suffix to named volume paths', function () {
    // Read the applicationParser volume handling in parsers.php
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
