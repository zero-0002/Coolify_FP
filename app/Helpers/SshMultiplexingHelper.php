<?php

namespace App\Helpers;

use App\Models\PrivateKey;
use App\Models\Server;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class SshMultiplexingHelper
{
    public static function serverSshConfiguration(Server $server): array
    {
        $privateKey = PrivateKey::findOrFail($server->private_key_id);

        return [
            'sshKeyLocation' => $privateKey->getKeyLocation(),
            'muxFilename' => self::muxSocket($server),
        ];
    }

    public static function ensureMultiplexedConnection(Server $server): bool
    {
        return self::isMultiplexingEnabled();
    }

    public static function removeMuxFile(Server $server): void
    {
        $closeCommand = self::muxControlCommand($server, 'exit');
        Process::run($closeCommand);
    }

    private static function muxControlCommand(Server $server, string $operation): string
    {
        $command = "ssh -O {$operation} -o ControlPath=".self::muxSocket($server).' ';
        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $command .= '-o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }

        return $command.self::escapedUserAtHost($server);
    }

    public static function generateScpCommand(Server $server, string $source, string $dest): string
    {
        $sshConfig = self::serverSshConfiguration($server);
        $sshKeyLocation = $sshConfig['sshKeyLocation'];
        $multiplexingEnabled = self::isMultiplexingEnabled();
        $scpCommand = 'timeout '.config('constants.ssh.command_timeout').' scp ';

        if ($server->isIpv6()) {
            $scpCommand .= '-6 ';
        }

        if ($multiplexingEnabled) {
            $scpCommand .= self::multiplexingOptions($server);
        }

        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $scpCommand .= '-o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }

        $scpCommand .= self::getCommonSshOptions($server, $sshKeyLocation, self::getConnectionTimeout($server), config('constants.ssh.server_interval'), isScp: true);

        if ($server->isIpv6()) {
            $scpCommand .= "{$source} ".escapeshellarg($server->user).'@['.escapeshellarg($server->ip)."]:{$dest}";
        } else {
            $scpCommand .= "{$source} ".self::escapedUserAtHost($server).":{$dest}";
        }

        return $multiplexingEnabled
            ? self::withFirstUseMuxLock($server, $scpCommand)
            : $scpCommand;
    }

    public static function generateSshCommand(Server $server, string $command, bool $disableMultiplexing = false): string
    {
        if ($server->settings->force_disabled) {
            throw new \RuntimeException('Server is disabled.');
        }

        $sshConfig = self::serverSshConfiguration($server);
        $sshKeyLocation = $sshConfig['sshKeyLocation'];
        $multiplexingEnabled = ! $disableMultiplexing && self::isMultiplexingEnabled();

        self::validateSshKey($server->privateKey);

        $sshCommand = 'timeout '.config('constants.ssh.command_timeout').' ssh ';

        if ($multiplexingEnabled) {
            $sshCommand .= self::multiplexingOptions($server);
        }

        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $sshCommand .= "-o ProxyCommand='cloudflared access ssh --hostname %h' ";
        }

        $sshCommand .= self::getCommonSshOptions($server, $sshKeyLocation, self::getConnectionTimeout($server), config('constants.ssh.server_interval'));

        $delimiter = base64_encode(Hash::make($command));
        $command = str_replace($delimiter, '', $command);

        $sshCommand .= self::escapedUserAtHost($server)." 'bash -se' << \\$delimiter".PHP_EOL
            .$command.PHP_EOL
            .$delimiter;

        return $multiplexingEnabled
            ? self::withFirstUseMuxLock($server, $sshCommand)
            : $sshCommand;
    }

    private static function multiplexingOptions(Server $server): string
    {
        return '-o ControlMaster=auto '
            .'-o ControlPath='.self::muxSocket($server).' '
            .'-o ControlPersist='.config('constants.ssh.mux_persist_time').' ';
    }

    private static function muxSocket(Server $server): string
    {
        return '/var/www/html/storage/app/ssh/mux/mux_'.$server->uuid;
    }

    private static function muxLockDirectory(Server $server): string
    {
        return self::muxSocket($server).'.lock';
    }

    private static function withFirstUseMuxLock(Server $server, string $command): string
    {
        $muxSocket = self::muxSocket($server);
        $lockDirectory = self::muxLockDirectory($server);
        $lockTimeout = (int) config('constants.ssh.mux_lock_timeout');

        $checkCommand = self::muxControlCommand($server, 'check');

        $script = <<<'SH'
cmd=$1
socket=$2
lock=$3
timeout=$4
check=$5

run_command() {
    sh -c "$cmd"
}

mux_ready() {
    [ -S "$socket" ] && sh -c "$check" >/dev/null 2>&1
}

if mux_ready; then
    run_command
    exit $?
fi

waited=0
while ! mkdir "$lock" 2>/dev/null; do
    if mux_ready; then
        run_command
        exit $?
    fi

    if [ "$waited" -ge "$timeout" ]; then
        run_command
        exit $?
    fi

    waited=$((waited + 1))
    sleep 1
done

cleanup() {
    if [ -n "${child:-}" ] && kill -0 "$child" 2>/dev/null; then
        kill "$child" 2>/dev/null
    fi
    rmdir "$lock" 2>/dev/null
}
trap cleanup INT TERM HUP

sh -c "$cmd" &
child=$!

for _ in 1 2 3 4 5 6 7 8 9 10; do
    if mux_ready || ! kill -0 "$child" 2>/dev/null; then
        break
    fi
    sleep 0.1
done

rmdir "$lock" 2>/dev/null
wait "$child"
exit $?
SH;

        return 'sh -c '.escapeshellarg($script).' -- '
            .escapeshellarg($command).' '
            .escapeshellarg($muxSocket).' '
            .escapeshellarg($lockDirectory).' '
            .escapeshellarg((string) $lockTimeout).' '
            .escapeshellarg($checkCommand);
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

        if ($isScp) {
            return $options.'-P '.escapeshellarg((string) $server->port).' ';
        }

        return $options.'-p '.escapeshellarg((string) $server->port).' ';
    }
}
