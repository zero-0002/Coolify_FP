<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;

function makePreviewImageNameJob(string $commit, int $pullRequestId = 42, ?string $registryImageName = null): object
{
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = $reflection->newInstanceWithoutConstructor();

    $application = new Application;
    $application->uuid = 'preview-app';
    $application->build_pack = 'dockerfile';
    $application->dockerfile = null;
    $application->docker_registry_image_name = $registryImageName;

    foreach ([
        'application' => $application,
        'pull_request_id' => $pullRequestId,
        'commit' => $commit,
    ] as $property => $value) {
        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($job, $value);
    }

    return $job;
}

function generatePreviewImageNames(object $job): array
{
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $method = $reflection->getMethod('generate_image_names');
    $method->setAccessible(true);
    $method->invoke($job);

    $buildImageName = $reflection->getProperty('build_image_name');
    $buildImageName->setAccessible(true);

    $productionImageName = $reflection->getProperty('production_image_name');
    $productionImageName->setAccessible(true);

    return [
        'build' => $buildImageName->getValue($job),
        'production' => $productionImageName->getValue($job),
    ];
}

it('includes the pull request id and commit in preview image names', function () {
    $names = generatePreviewImageNames(makePreviewImageNameJob(
        commit: '111222333444555666777888999000aaabbbccc1',
        pullRequestId: 123,
    ));

    expect($names['production'])->toBe('preview-app:pr-123-111222333444555666777888999000aaabbbccc1')
        ->and($names['build'])->toBe('preview-app:pr-123-111222333444555666777888999000aaabbbccc1-build');
});

it('generates different preview image names for different commits on the same pull request', function () {
    $firstCommitNames = generatePreviewImageNames(makePreviewImageNameJob(
        commit: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        pullRequestId: 123,
    ));
    $secondCommitNames = generatePreviewImageNames(makePreviewImageNameJob(
        commit: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        pullRequestId: 123,
    ));

    expect($firstCommitNames['production'])->not->toBe($secondCommitNames['production'])
        ->and($firstCommitNames['build'])->not->toBe($secondCommitNames['build']);
});

it('uses the configured registry image name for commit-specific preview tags', function () {
    $names = generatePreviewImageNames(makePreviewImageNameJob(
        commit: '111222333444555666777888999000aaabbbccc1',
        pullRequestId: 123,
        registryImageName: 'registry.example.com/team/app',
    ));

    expect($names['production'])->toBe('registry.example.com/team/app:pr-123-111222333444555666777888999000aaabbbccc1')
        ->and($names['build'])->toBe('registry.example.com/team/app:pr-123-111222333444555666777888999000aaabbbccc1-build');
});

it('sanitizes and truncates preview image tags to docker tag limits', function () {
    $names = generatePreviewImageNames(makePreviewImageNameJob(
        commit: str_repeat('feature/add dockerfile changes/', 10),
        pullRequestId: 123,
    ));

    $productionTag = str($names['production'])->after(':')->toString();
    $buildTag = str($names['build'])->after(':')->toString();

    expect(strlen($productionTag))->toBeLessThanOrEqual(128)
        ->and(strlen($buildTag))->toBeLessThanOrEqual(128)
        ->and($productionTag)->toMatch('/^pr-123-[A-Za-z0-9_.-]+$/')
        ->and($buildTag)->toMatch('/^pr-123-[A-Za-z0-9_.-]+-build$/');
});

it('keeps non-preview dockerfile image names commit based', function () {
    $names = generatePreviewImageNames(makePreviewImageNameJob(
        commit: '111222333444555666777888999000aaabbbccc1',
        pullRequestId: 0,
    ));

    expect($names['production'])->toBe('preview-app:111222333444555666777888999000aaabbbccc1')
        ->and($names['build'])->toBe('preview-app:111222333444555666777888999000aaabbbccc1-build');
});
