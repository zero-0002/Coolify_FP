<?php

it('copies the realtime terminal utilities into the container image', function () {
    $dockerfile = file_get_contents(base_path('docker/coolify-realtime/Dockerfile'));

    expect($dockerfile)->toContain('COPY docker/coolify-realtime/terminal-utils.js /terminal/terminal-utils.js');
});

it('mounts the realtime terminal utilities in local development compose files', function (string $composeFile) {
    $composeContents = file_get_contents(base_path($composeFile));

    expect($composeContents)->toContain('./docker/coolify-realtime/terminal-utils.js:/terminal/terminal-utils.js');
})->with([
    'default dev compose' => 'docker-compose.dev.yml',
    'maxio dev compose' => 'docker-compose-maxio.dev.yml',
]);

it('keeps terminal browser logging restricted to Vite development mode', function () {
    $terminalClient = file_get_contents(base_path('resources/js/terminal.js'));

    expect($terminalClient)
        ->toContain('const terminalDebugEnabled = import.meta.env.DEV;')
        ->toContain("logTerminal('log', '[Terminal] WebSocket connection established.');")
        ->not->toContain("console.log('[Terminal] WebSocket connection established. Cool cool cool cool cool cool.');");
});

it('keeps realtime terminal server logging restricted to development environments', function () {
    $terminalServer = file_get_contents(base_path('docker/coolify-realtime/terminal-server.js'));

    expect($terminalServer)
        ->toContain("const terminalDebugEnabled = ['local', 'development'].includes(")
        ->toContain('if (!terminalDebugEnabled) {')
        ->not->toContain("console.log('Coolify realtime terminal server listening on port 6002. Let the hacking begin!');");
});

it('configures a server-initiated WebSocket heartbeat to survive proxy idle timeouts', function () {
    $terminalServer = file_get_contents(base_path('docker/coolify-realtime/terminal-server.js'));

    expect($terminalServer)
        ->toContain('ws.isAlive = true;')
        ->toContain("ws.on('pong'")
        ->toContain('ws.ping();')
        ->toContain('ws.terminate();')
        ->toContain('HEARTBEAT_INTERVAL_MS');
});

it('removes the keepalive short-circuit that fired when the tab was hidden', function () {
    $terminalClient = file_get_contents(base_path('resources/js/terminal.js'));

    expect($terminalClient)
        ->not->toContain('// Skip keepalive when document is hidden to prevent unnecessary disconnects');
});

it('uses a fast probe timeout when the tab regains visibility', function () {
    $terminalClient = file_get_contents(base_path('resources/js/terminal.js'));

    expect($terminalClient)
        ->toContain("'Visibility-resume timeout'");
});

it('does not hard close terminal sessions after 30 minutes on the server', function () {
    $terminalServer = file_get_contents(base_path('docker/coolify-realtime/terminal-server.js'));

    expect($terminalServer)
        ->not->toContain('IDLE_TIMEOUT_MS = 30 * 60 * 1000')
        ->not->toContain("ws.send('idle-timeout');")
        ->not->toContain("ws.close(1000, 'Idle timeout');");
});

it('does not close the client terminal from an idle-timeout sentinel', function () {
    $terminalClient = file_get_contents(base_path('resources/js/terminal.js'));

    expect($terminalClient)
        ->not->toContain("event.data === 'idle-timeout'")
        ->not->toContain('Terminal closed after 30 minutes of inactivity.');
});

it('keeps Livewire alive in background tabs while a terminal is connected', function () {
    $terminalComponent = file_get_contents(base_path('app/Livewire/Project/Shared/Terminal.php'));
    $terminalView = file_get_contents(base_path('resources/views/livewire/project/shared/terminal.blade.php'));

    expect($terminalComponent)
        ->toContain('public bool $isTerminalConnected = false;')
        ->toContain("#[On('terminalConnected')]")
        ->toContain('public function markTerminalConnected(): void')
        ->toContain('public function keepTerminalPageAlive(): void')
        ->and($terminalView)
        ->toContain('@if ($isTerminalConnected)')
        ->toContain('wire:poll.keep-alive.30s="keepTerminalPageAlive"');
});

it('replays the last command on reconnect so the PTY respawns automatically', function () {
    $terminalClient = file_get_contents(base_path('resources/js/terminal.js'));

    expect($terminalClient)
        ->toContain('lastSentCommand')
        ->toContain('Replaying last command after reconnect.')
        ->toContain('this.lastSentCommand = null;');
});

it('buffers messages received before the realtime server finishes auth so the replay is not lost', function () {
    $terminalServer = file_get_contents(base_path('docker/coolify-realtime/terminal-server.js'));

    expect($terminalServer)
        ->toContain('authReady: false')
        ->toContain('pendingMessages: []')
        ->toContain('userSession.pendingMessages.push(message)')
        ->toContain('userSession.authReady = true');
});

it('preserves terminal scrollback across transient reconnects', function () {
    $terminalClient = file_get_contents(base_path('resources/js/terminal.js'));

    expect($terminalClient)
        ->toContain('── Connection lost at')
        ->toContain('── Reconnected at')
        // resetTerminal must NOT call term.reset()/term.clear() any more — those wipe scrollback.
        ->not->toContain("this.term.reset();\n                    this.term.clear();");
});
