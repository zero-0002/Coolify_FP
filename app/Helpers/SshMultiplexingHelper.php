<?php

namespace App\Helpers;

use App\Models\PrivateKey;
use App\Models\Server;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class SshMultiplexingHelper
{
    public static function serverSshConfiguration(Server $server)
    {
        $privateKey = PrivateKey::findOrFail($server->private_key_id);
        $sshKeyLocation = $privateKey->getKeyLocation();
        $muxFilename = '/var/www/html/storage/app/ssh/mux/mux_'.$server->uuid;

        return [
            'sshKeyLocation' => $sshKeyLocation,
            'muxFilename' => $muxFilename,
        ];
    }

    public static function ensureMultiplexedConnection(Server $server): bool
    {
        if (! self::isMultiplexingEnabled()) {
            return false;
        }

        // Fast path: a usable master already exists, no need to lock.
        if (self::connectionIsReusable($server)) {
            return true;
        }

        // Slow path: establishing or refreshing the master. Serialize per server
        // so concurrent workers do not each spawn their own master process,
        // leaving orphaned non-master ssh connections that ControlPersist never reaps.
        try {
            return Cache::lock(
                self::connectionLockKey($server),
                config('constants.ssh.mux_lock_ttl')
            )->block(config('constants.ssh.mux_lock_timeout'), function () use ($server) {
                // Double-checked: another worker may have established the master
                // while we were waiting for the lock.
                if (self::connectionIsReusable($server)) {
                    return true;
                }

                // A master exists but is stale or expired: close and re-establish.
                if (self::masterConnectionExists($server)) {
                    return self::refreshMultiplexedConnection($server);
                }

                return self::establishNewMultiplexedConnection($server);
            });
        } catch (LockTimeoutException) {
            Log::warning('SSH multiplexing lock timeout, falling back to non-multiplexed connection', [
                'server' => $server->name ?? $server->ip,
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::warning('SSH multiplexing lock unavailable, falling back to non-multiplexed connection', [
                'server' => $server->name ?? $server->ip,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Per-server, per-host lock key for serializing master establishment.
     *
     * The mux socket is a host-local unix socket, so the lock is scoped to the
     * current Coolify host: workers on the same host share a master and must
     * serialize, while workers on other hosts manage their own masters and must
     * not block on each other.
     */
    private static function connectionLockKey(Server $server): string
    {
        return 'ssh_mux_lock_'.(gethostname() ?: 'unknown').'_'.$server->uuid;
    }

    /**
     * Check whether a multiplexed master connection currently exists for the server.
     */
    private static function masterConnectionExists(Server $server): bool
    {
        $sshConfig = self::serverSshConfiguration($server);
        $muxSocket = $sshConfig['muxFilename'];

        $checkCommand = "ssh -O check -o ControlPath=$muxSocket ";
        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $checkCommand .= '-o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }
        $checkCommand .= self::escapedUserAtHost($server);

        return Process::run($checkCommand)->exitCode() === 0;
    }

    /**
     * Determine whether the existing master connection can be reused as-is
     * (it exists, has not exceeded its max age, and passes the health check).
     */
    private static function connectionIsReusable(Server $server): bool
    {
        if (! self::masterConnectionExists($server)) {
            return false;
        }

        // Existing connection but no metadata, store current time as fallback.
        if (self::getConnectionAge($server) === null) {
            self::storeConnectionMetadata($server);
        }

        if (self::isConnectionExpired($server)) {
            return false;
        }

        if (config('constants.ssh.mux_health_check_enabled') && ! self::isConnectionHealthy($server)) {
            return false;
        }

        return true;
    }

    public static function establishNewMultiplexedConnection(Server $server): bool
    {
        $sshConfig = self::serverSshConfiguration($server);
        $sshKeyLocation = $sshConfig['sshKeyLocation'];
        $muxSocket = $sshConfig['muxFilename'];
        $connectionTimeout = self::getConnectionTimeout($server);
        $serverInterval = config('constants.ssh.server_interval');
        $muxPersistTime = config('constants.ssh.mux_persist_time');

        // No -M: it forces master mode and overrides ControlMaster=auto. When a
        // socket already exists -M leaves an orphaned non-master ssh -fN process
        // that ControlPersist never reaps. ControlMaster=auto reuses instead.
        $establishCommand = "ssh -fN -o ControlMaster=auto -o ControlPath=$muxSocket -o ControlPersist={$muxPersistTime} ";

        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $establishCommand .= ' -o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }
        $establishCommand .= self::getCommonSshOptions($server, $sshKeyLocation, $connectionTimeout, $serverInterval);
        $establishCommand .= self::escapedUserAtHost($server);
        $establishProcess = Process::run($establishCommand);
        if ($establishProcess->exitCode() !== 0) {
            return false;
        }

        // Store connection metadata for tracking
        self::storeConnectionMetadata($server);

        return true;
    }

    public static function removeMuxFile(Server $server)
    {
        $sshConfig = self::serverSshConfiguration($server);
        $muxSocket = $sshConfig['muxFilename'];

        $closeCommand = "ssh -O exit -o ControlPath=$muxSocket ";
        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $closeCommand .= '-o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }
        $closeCommand .= self::escapedUserAtHost($server);
        Process::run($closeCommand);

        // Clear connection metadata from cache
        self::clearConnectionMetadata($server);
    }

    public static function generateScpCommand(Server $server, string $source, string $dest)
    {
        $sshConfig = self::serverSshConfiguration($server);
        $sshKeyLocation = $sshConfig['sshKeyLocation'];
        $muxSocket = $sshConfig['muxFilename'];

        $timeout = config('constants.ssh.command_timeout');
        $muxPersistTime = config('constants.ssh.mux_persist_time');

        $scp_command = "timeout $timeout scp ";
        if ($server->isIpv6()) {
            $scp_command .= '-6 ';
        }
        if (self::isMultiplexingEnabled()) {
            try {
                if (self::ensureMultiplexedConnection($server)) {
                    $scp_command .= "-o ControlMaster=auto -o ControlPath=$muxSocket -o ControlPersist={$muxPersistTime} ";
                }
            } catch (\Exception $e) {
                Log::warning('SSH multiplexing failed for SCP, falling back to non-multiplexed connection', [
                    'server' => $server->name ?? $server->ip,
                    'error' => $e->getMessage(),
                ]);
                // Continue without multiplexing
            }
        }

        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $scp_command .= '-o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }

        $scp_command .= self::getCommonSshOptions($server, $sshKeyLocation, self::getConnectionTimeout($server), config('constants.ssh.server_interval'), isScp: true);
        if ($server->isIpv6()) {
            $scp_command .= "{$source} ".escapeshellarg($server->user).'@['.escapeshellarg($server->ip)."]:{$dest}";
        } else {
            $scp_command .= "{$source} ".self::escapedUserAtHost($server).":{$dest}";
        }

        return $scp_command;
    }

    public static function generateSshCommand(Server $server, string $command, bool $disableMultiplexing = false)
    {
        if ($server->settings->force_disabled) {
            throw new \RuntimeException('Server is disabled.');
        }

        $sshConfig = self::serverSshConfiguration($server);
        $sshKeyLocation = $sshConfig['sshKeyLocation'];

        self::validateSshKey($server->privateKey);

        $muxSocket = $sshConfig['muxFilename'];

        $timeout = config('constants.ssh.command_timeout');
        $muxPersistTime = config('constants.ssh.mux_persist_time');

        $ssh_command = "timeout $timeout ssh ";

        $multiplexingSuccessful = false;
        if (! $disableMultiplexing && self::isMultiplexingEnabled()) {
            try {
                $multiplexingSuccessful = self::ensureMultiplexedConnection($server);
                if ($multiplexingSuccessful) {
                    $ssh_command .= "-o ControlMaster=auto -o ControlPath=$muxSocket -o ControlPersist={$muxPersistTime} ";
                }
            } catch (\Exception $e) {
                Log::warning('SSH multiplexing failed, falling back to non-multiplexed connection', [
                    'server' => $server->name ?? $server->ip,
                    'error' => $e->getMessage(),
                ]);
                // Continue without multiplexing
            }
        }

        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $ssh_command .= "-o ProxyCommand='cloudflared access ssh --hostname %h' ";
        }

        $ssh_command .= self::getCommonSshOptions($server, $sshKeyLocation, self::getConnectionTimeout($server), config('constants.ssh.server_interval'));

        $delimiter = Hash::make($command);
        $delimiter = base64_encode($delimiter);
        $command = str_replace($delimiter, '', $command);

        $ssh_command .= self::escapedUserAtHost($server)." 'bash -se' << \\$delimiter".PHP_EOL
            .$command.PHP_EOL
            .$delimiter;

        return $ssh_command;
    }

    private static function escapedUserAtHost(Server $server): string
    {
        return escapeshellarg($server->user).'@'.escapeshellarg($server->ip);
    }

    private static function isMultiplexingEnabled(): bool
    {
        return config('constants.ssh.mux_enabled') && ! config('constants.coolify.is_windows_docker_desktop');
    }

    private static function validateSshKey(PrivateKey $privateKey): void
    {
        $keyLocation = $privateKey->getKeyLocation();
        $filename = "ssh_key@{$privateKey->uuid}";
        $disk = Storage::disk('ssh-keys');

        $needsRewrite = false;

        if (! $disk->exists($filename)) {
            $needsRewrite = true;
        } else {
            $diskContent = $disk->get($filename);
            if ($diskContent !== $privateKey->private_key) {
                Log::warning('SSH key file content does not match database, resyncing', [
                    'key_uuid' => $privateKey->uuid,
                ]);
                $needsRewrite = true;
            }
        }

        if ($needsRewrite) {
            $privateKey->storeInFileSystem();
        }

        // Ensure correct permissions (SSH requires 0600)
        if (file_exists($keyLocation)) {
            $currentPerms = fileperms($keyLocation) & 0777;
            if ($currentPerms !== 0600 && ! chmod($keyLocation, 0600)) {
                Log::warning('Failed to set SSH key file permissions to 0600', [
                    'key_uuid' => $privateKey->uuid,
                    'path' => $keyLocation,
                ]);
            }
        }
    }

    public static function getConnectionTimeout(Server $server): int
    {
        $timeout = data_get($server, 'settings.connection_timeout');

        return is_numeric($timeout) && (int) $timeout > 0
            ? (int) $timeout
            : (int) config('constants.ssh.connection_timeout');
    }

    private static function getCommonSshOptions(Server $server, string $sshKeyLocation, int $connectionTimeout, int $serverInterval, bool $isScp = false): string
    {
        $options = "-i {$sshKeyLocation} "
            .'-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null '
            .'-o PasswordAuthentication=no '
            ."-o ConnectTimeout=$connectionTimeout "
            ."-o ServerAliveInterval=$serverInterval "
            .'-o RequestTTY=no '
            .'-o LogLevel=ERROR ';

        // Bruh
        if ($isScp) {
            $options .= '-P '.escapeshellarg((string) $server->port).' ';
        } else {
            $options .= '-p '.escapeshellarg((string) $server->port).' ';
        }

        return $options;
    }

    /**
     * Check if the multiplexed connection is healthy by running a test command
     */
    public static function isConnectionHealthy(Server $server): bool
    {
        $sshConfig = self::serverSshConfiguration($server);
        $muxSocket = $sshConfig['muxFilename'];
        $healthCheckTimeout = config('constants.ssh.mux_health_check_timeout');

        $healthCommand = "timeout $healthCheckTimeout ssh -o ControlMaster=auto -o ControlPath=$muxSocket ";
        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $healthCommand .= '-o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }
        $healthCommand .= self::escapedUserAtHost($server)." 'echo \"health_check_ok\"'";

        $process = Process::run($healthCommand);
        $isHealthy = $process->exitCode() === 0 && str_contains($process->output(), 'health_check_ok');

        return $isHealthy;
    }

    /**
     * Check if the connection has exceeded its maximum age
     */
    public static function isConnectionExpired(Server $server): bool
    {
        $connectionAge = self::getConnectionAge($server);
        $maxAge = config('constants.ssh.mux_max_age');

        return $connectionAge !== null && $connectionAge > $maxAge;
    }

    /**
     * Get the age of the current connection in seconds
     */
    public static function getConnectionAge(Server $server): ?int
    {
        $cacheKey = "ssh_mux_connection_time_{$server->uuid}";
        $connectionTime = Cache::get($cacheKey);

        if ($connectionTime === null) {
            return null;
        }

        return time() - $connectionTime;
    }

    /**
     * Refresh a multiplexed connection by closing and re-establishing it
     */
    public static function refreshMultiplexedConnection(Server $server): bool
    {
        // Close existing connection
        self::removeMuxFile($server);

        // Establish new connection
        return self::establishNewMultiplexedConnection($server);
    }

    /**
     * Store connection metadata when a new connection is established
     */
    private static function storeConnectionMetadata(Server $server): void
    {
        $cacheKey = "ssh_mux_connection_time_{$server->uuid}";
        Cache::put($cacheKey, time(), config('constants.ssh.mux_persist_time') + 300); // Cache slightly longer than persist time
    }

    /**
     * Clear connection metadata from cache
     */
    private static function clearConnectionMetadata(Server $server): void
    {
        $cacheKey = "ssh_mux_connection_time_{$server->uuid}";
        Cache::forget($cacheKey);
    }
}
