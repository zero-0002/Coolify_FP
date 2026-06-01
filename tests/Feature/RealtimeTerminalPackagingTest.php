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

it('keeps realtime terminal server logging behind the explicit debug flag', function () {
    $terminalServer = file_get_contents(base_path('docker/coolify-realtime/terminal-server.js'));

    expect($terminalServer)
        ->toContain("const terminalDebugEnabled = ['1', 'true', 'yes'].includes(")
        ->toContain('process.env.TERMINAL_DEBUG')
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

it('closes idle terminal sessions after 30 minutes on the server', function () {
    $terminalServer = file_get_contents(base_path('docker/coolify-realtime/terminal-server.js'));

    expect($terminalServer)
        ->toContain('IDLE_TIMEOUT_MS = 30 * 60 * 1000')
        ->toContain('lastActivityAt')
        ->toContain("ws.send('idle-timeout');")
        ->toContain("ws.close(1000, 'Idle timeout');");
});

it('reacts to idle-timeout sentinel on the client and shows a user-facing error', function () {
    $terminalClient = file_get_contents(base_path('resources/js/terminal.js'));

    expect($terminalClient)
        ->toContain("event.data === 'idle-timeout'")
        ->toContain('Terminal closed after 30 minutes of inactivity.');
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

it('renders a compact mobile terminal toolbar with shell control keys', function () {
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));
    $appCss = file_get_contents(resource_path('css/app.css'));

    expect($terminalView)
        ->toContain('Terminal keys')
        ->toContain('sm:hidden')
        ->toContain("sendTerminalControl('arrowUp')")
        ->toContain("sendTerminalControl('arrowDown')")
        ->toContain("sendTerminalControl('arrowLeft')")
        ->toContain("sendTerminalControl('arrowRight')")
        ->toContain("sendTerminalControl('tab')")
        ->toContain("sendTerminalControl('escape')")
        ->not->toContain("sendTerminalControl('ctrlC')")
        ->not->toContain('pasteFromClipboard()')
        ->not->toContain('copyTerminalSelection()')
        ->toContain('mobileToolbarCollapsed')
        ->toContain("fullscreen ? 'absolute inset-x-0 bottom-0 z-[9999] px-2 pb-2' : 'relative mt-2'")
        ->toContain('data-terminal-mobile-toolbar')
        ->and($appCss)
        ->toContain('.terminal-mobile-key');
});

it('sends terminal mobile toolbar controls through the websocket', function () {
    $terminalClient = file_get_contents(resource_path('js/terminal.js'));

    expect($terminalClient)
        ->toContain('sendTerminalInput(data)')
        ->toContain('sendTerminalControl(sequence)')
        ->toContain("arrowUp: '\\x1b[A'")
        ->toContain("arrowDown: '\\x1b[B'")
        ->toContain("arrowRight: '\\x1b[C'")
        ->toContain("arrowLeft: '\\x1b[D'")
        ->toContain("tab: '\\t'")
        ->toContain("escape: '\\x1b'")
        ->toContain("ctrlC: '\\x03'")
        ->toContain('navigator.clipboard.readText()')
        ->toContain('navigator.clipboard.writeText(selection)');
});

it('uses terminal dimensions when resizing so mobile controls do not cover terminal rows', function () {
    $terminalClient = file_get_contents(resource_path('js/terminal.js'));

    expect($terminalClient)
        ->toContain("document.getElementById('terminal')")
        ->toContain('terminalHeight')
        ->toContain('terminalWidth')
        ->not->toContain('const wrapperHeight = this.$refs.terminalWrapper.clientHeight;');
});

it('uses simple fullscreen bottom margin based on mobile toolbar visibility', function () {
    $terminalClient = file_get_contents(resource_path('js/terminal.js'));
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));

    expect($terminalClient)
        ->not->toContain('updateFullscreenLayout()')
        ->not->toContain('terminalFullscreenHeight')
        ->not->toContain('window.visualViewport?.height')
        ->and($terminalView)
        ->toContain("mobileToolbarCollapsed ? 'h-[calc(100dvh-3.5rem)] mb-14 px-2 py-1 bg-black' : 'h-[calc(100dvh-11rem)] mb-[11rem] px-2 py-1 bg-black'")
        ->toContain("fullscreen ? 'absolute inset-x-0 bottom-0 z-[9999] px-2 pb-2'");
});

it('resizes after toggling the mobile terminal toolbar', function () {
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));

    expect($terminalView)
        ->toContain('$nextTick(() => resizeTerminal())');
});

it('uses fixed viewport positioning for fullscreen terminal instead of inherited container size', function () {
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));

    expect($terminalView)
        ->toContain('fixed inset-0')
        ->toContain('h-[100dvh]')
        ->toContain('w-screen')
        ->toContain('max-w-none')
        ->toContain('overflow-hidden');
});

it('constrains normal terminal height after leaving fullscreen', function () {
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));

    expect($terminalView)
        ->toContain('h-[510px] max-h-[calc(100dvh-10rem)] overflow-hidden');
});
