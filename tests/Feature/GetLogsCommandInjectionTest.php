<?php

use App\Livewire\Project\Shared\GetLogs;
use App\Support\ValidationPatterns;
use Livewire\Attributes\Locked;

describe('GetLogs locked properties', function () {
    test('container property has Locked attribute', function () {
        $property = new ReflectionProperty(GetLogs::class, 'container');
        $attributes = $property->getAttributes(Locked::class);

        expect($attributes)->not->toBeEmpty();
    });

    test('server property has Locked attribute', function () {
        $property = new ReflectionProperty(GetLogs::class, 'server');
        $attributes = $property->getAttributes(Locked::class);

        expect($attributes)->not->toBeEmpty();
    });

    test('resource property has Locked attribute', function () {
        $property = new ReflectionProperty(GetLogs::class, 'resource');
        $attributes = $property->getAttributes(Locked::class);

        expect($attributes)->not->toBeEmpty();
    });

    test('servicesubtype property has Locked attribute', function () {
        $property = new ReflectionProperty(GetLogs::class, 'servicesubtype');
        $attributes = $property->getAttributes(Locked::class);

        expect($attributes)->not->toBeEmpty();
    });
});

describe('GetLogs container name validation in getLogs', function () {
    test('getLogs method validates container name with ValidationPatterns', function () {
        $method = new ReflectionMethod(GetLogs::class, 'getLogs');
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $lines = array_slice(file($method->getFileName()), $startLine - 1, $endLine - $startLine + 1);
        $methodBody = implode('', $lines);

        expect($methodBody)->toContain('ValidationPatterns::isValidContainerName');
    });

    test('downloadAllLogs method validates container name with ValidationPatterns', function () {
        $method = new ReflectionMethod(GetLogs::class, 'downloadAllLogs');
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $lines = array_slice(file($method->getFileName()), $startLine - 1, $endLine - $startLine + 1);
        $methodBody = implode('', $lines);

        expect($methodBody)->toContain('ValidationPatterns::isValidContainerName');
    });
});

describe('GetLogs authorization checks', function () {
    test('getLogs method checks server ownership via ownedByCurrentTeam', function () {
        $method = new ReflectionMethod(GetLogs::class, 'getLogs');
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $lines = array_slice(file($method->getFileName()), $startLine - 1, $endLine - $startLine + 1);
        $methodBody = implode('', $lines);

        expect($methodBody)->toContain('Server::ownedByCurrentTeam()');
    });

    test('downloadAllLogs method checks server ownership via ownedByCurrentTeam', function () {
        $method = new ReflectionMethod(GetLogs::class, 'downloadAllLogs');
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $lines = array_slice(file($method->getFileName()), $startLine - 1, $endLine - $startLine + 1);
        $methodBody = implode('', $lines);

        expect($methodBody)->toContain('Server::ownedByCurrentTeam()');
    });
});

describe('GetLogs container name injection payloads are blocked by validation', function () {
    test('newline injection payload is rejected', function () {
        // The exact PoC payload from the advisory
        $payload = "postgresql 2>/dev/null\necho '===RCE-START==='\nid\nwhoami\nhostname\ncat /etc/hostname\necho '===RCE-END==='\n#";
        expect(ValidationPatterns::isValidContainerName($payload))->toBeFalse();
    });

    test('semicolon injection payload is rejected', function () {
        expect(ValidationPatterns::isValidContainerName('postgresql;id'))->toBeFalse();
    });

    test('backtick injection payload is rejected', function () {
        expect(ValidationPatterns::isValidContainerName('postgresql`id`'))->toBeFalse();
    });

    test('command substitution injection payload is rejected', function () {
        expect(ValidationPatterns::isValidContainerName('postgresql$(whoami)'))->toBeFalse();
    });

    test('pipe injection payload is rejected', function () {
        expect(ValidationPatterns::isValidContainerName('postgresql|cat /etc/passwd'))->toBeFalse();
    });

    test('valid container names are accepted', function () {
        expect(ValidationPatterns::isValidContainerName('postgresql'))->toBeTrue();
        expect(ValidationPatterns::isValidContainerName('my-app-container'))->toBeTrue();
        expect(ValidationPatterns::isValidContainerName('service_db.v2'))->toBeTrue();
        expect(ValidationPatterns::isValidContainerName('coolify-proxy'))->toBeTrue();
    });
});
