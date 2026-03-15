<?php

namespace Tests\Unit;

use App\Helpers\SshMultiplexingHelper;
use App\Models\PrivateKey;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests for SSH key validation to prevent sporadic "Permission denied" errors.
 *
 * The root cause: validateSshKey() only checked file existence, not content.
 * When a key was rotated in the DB but the old file persisted on disk,
 * SSH would use the stale key and fail with "Permission denied (publickey)".
 *
 * @see https://github.com/coollabsio/coolify/issues/7724
 */
class SshKeyValidationTest extends TestCase
{
    public function test_validate_ssh_key_method_exists()
    {
        $reflection = new \ReflectionMethod(SshMultiplexingHelper::class, 'validateSshKey');
        $this->assertTrue($reflection->isStatic(), 'validateSshKey should be a static method');
    }

    public function test_validate_ssh_key_accepts_private_key_parameter()
    {
        $reflection = new \ReflectionMethod(SshMultiplexingHelper::class, 'validateSshKey');
        $parameters = $reflection->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertEquals('privateKey', $parameters[0]->getName());

        $type = $parameters[0]->getType();
        $this->assertNotNull($type);
        $this->assertEquals(PrivateKey::class, $type->getName());
    }

    public function test_store_in_file_system_sets_correct_permissions()
    {
        // Verify that storeInFileSystem enforces chmod 0600 via code inspection
        $reflection = new \ReflectionMethod(PrivateKey::class, 'storeInFileSystem');
        $this->assertTrue(
            $reflection->isPublic(),
            'storeInFileSystem should be public'
        );

        // Verify the method source contains chmod for 0600
        $filename = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();
        $source = implode('', array_slice(file($filename), $startLine - 1, $endLine - $startLine + 1));

        $this->assertStringContainsString('chmod', $source, 'storeInFileSystem should set file permissions');
        $this->assertStringContainsString('0600', $source, 'storeInFileSystem should enforce 0600 permissions');
    }

    public function test_store_in_file_system_uses_file_locking()
    {
        // Verify the method uses flock to prevent race conditions
        $reflection = new \ReflectionMethod(PrivateKey::class, 'storeInFileSystem');
        $filename = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();
        $source = implode('', array_slice(file($filename), $startLine - 1, $endLine - $startLine + 1));

        $this->assertStringContainsString('flock', $source, 'storeInFileSystem should use file locking');
        $this->assertStringContainsString('LOCK_EX', $source, 'storeInFileSystem should use exclusive locks');
    }

    public function test_validate_ssh_key_checks_content_not_just_existence()
    {
        // Verify validateSshKey compares file content with DB value
        $reflection = new \ReflectionMethod(SshMultiplexingHelper::class, 'validateSshKey');
        $filename = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();
        $source = implode('', array_slice(file($filename), $startLine - 1, $endLine - $startLine + 1));

        // Should read file content and compare, not just check existence with `ls`
        $this->assertStringNotContainsString('ls $keyLocation', $source, 'Should not use ls to check key existence');
        $this->assertStringContainsString('private_key', $source, 'Should compare against DB key content');
        $this->assertStringContainsString('refresh', $source, 'Should refresh key from database');
    }

    public function test_server_model_detects_private_key_id_changes()
    {
        // Verify the Server model's saved event checks for private_key_id changes
        $reflection = new \ReflectionMethod(\App\Models\Server::class, 'booted');
        $filename = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();
        $source = implode('', array_slice(file($filename), $startLine - 1, $endLine - $startLine + 1));

        $this->assertStringContainsString(
            'wasChanged',
            $source,
            'Server saved event should detect private_key_id changes via wasChanged()'
        );
        $this->assertStringContainsString(
            'private_key_id',
            $source,
            'Server saved event should specifically check private_key_id'
        );
    }

    public function test_private_key_saved_event_resyncs_on_key_change()
    {
        // Verify PrivateKey model resyncs file and mux on key content change
        $reflection = new \ReflectionMethod(PrivateKey::class, 'booted');
        $filename = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();
        $source = implode('', array_slice(file($filename), $startLine - 1, $endLine - $startLine + 1));

        $this->assertStringContainsString(
            "wasChanged('private_key')",
            $source,
            'PrivateKey saved event should detect key content changes'
        );
        $this->assertStringContainsString(
            'refresh_server_connection',
            $source,
            'PrivateKey saved event should invalidate mux connections'
        );
        $this->assertStringContainsString(
            'storeInFileSystem',
            $source,
            'PrivateKey saved event should resync key file'
        );
    }
}
