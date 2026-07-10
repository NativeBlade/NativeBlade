// Shell action (desktop only) — captured execution, streaming spawn, openTerminal
// Uses: ctx.shellApi, ctx.osApi, ctx.isMobile, ctx.post
//
// Long-lived processes started with the PHP builder's `->spawn()` are tracked
// here by id so `shell_write` (feed stdin) and `shell_kill` (terminate) can
// reach them later. Streamed output is posted incrementally as
// `nativeblade-shell-data` ({ chunk, stream, id }) and completion as
// `nativeblade-shell-exit` ({ exitCode, id }); the interceptor re-dispatches
// both as the Livewire events `nb:shell-data` / `nb:shell-exit`.
const running = new Map(); // id -> Tauri shell Child

export async function shell(payload, ctx) {
    const id = payload.id || null;
    const post = (stdout, stderr, exitCode) => {
        ctx.post('nativeblade-shell-result', { stdout, stderr, exitCode, id });
    };

    if (ctx.isMobile) {
        post('', 'not supported on this platform', -1);
        return;
    }

    if (!ctx.shellApi || !payload.command) {
        post('', 'shell plugin not available', -1);
        return;
    }

    try {
        const platform = ctx.osApi ? await ctx.osApi.platform() : 'linux';
        const isWin = platform === 'windows';

        if (payload.openTerminal) {
            await openTerminal(payload, ctx, platform, isWin);
            return;
        }

        // Run via the platform shell so pipes/redirection work; the whole
        // command line rides as a single arg to cmd/sh.
        const program = isWin ? 'cmd' : 'sh';
        const args = isWin ? ['/C', payload.command] : ['-c', payload.command];
        const options = {};
        if (payload.cwd) options.cwd = payload.cwd;
        if (payload.env && typeof payload.env === 'object') options.env = payload.env;

        // Streaming spawn — long-lived process, output streamed line by line.
        if (payload.spawn) {
            await spawnStreaming(payload, ctx, program, args, options);
            return;
        }

        // Captured execution — run to completion and report stdout/stderr/exitCode
        const command = ctx.shellApi.Command.create(program, args, options);

        let timer = null;
        let timedOut = false;
        const runPromise = command.execute();

        const racers = [runPromise];
        if (payload.timeout && payload.timeout > 0) {
            racers.push(new Promise((_, reject) => {
                timer = setTimeout(() => {
                    timedOut = true;
                    reject(new Error(`timeout after ${payload.timeout}s`));
                }, payload.timeout * 1000);
            }));
        }

        try {
            const output = await Promise.race(racers);
            if (timer) clearTimeout(timer);
            post(output.stdout || '', output.stderr || '', output.code ?? -1);
        } catch (e) {
            if (timer) clearTimeout(timer);
            if (timedOut) {
                post('', `timeout after ${payload.timeout}s`, -1);
            } else {
                post('', e?.message || String(e), -1);
            }
        }
    } catch (e) {
        post('', e?.message || String(e), -1);
    }
}

async function openTerminal(payload, ctx, platform, isWin) {
    const cmd = payload.command;
    const cwd = payload.cwd || null;

    if (isWin) {
        const prefer = (payload.terminalType || 'wt').toLowerCase();
        const tryList = [];
        if (prefer === 'wt') {
            const wtArgs = [];
            if (cwd) wtArgs.push('-d', cwd);
            wtArgs.push('cmd.exe', '/K', cmd);
            tryList.push({ program: 'wt.exe', args: wtArgs });
        }
        if (prefer === 'powershell') {
            const psArgs = ['-NoExit', '-Command'];
            const cdPart = cwd ? `Set-Location -Path "${cwd}"; ` : '';
            psArgs.push(cdPart + cmd);
            tryList.push({ program: 'powershell.exe', args: psArgs });
        }
        const cmdArgs = [];
        if (cwd) cmdArgs.push('/D', cwd);
        cmdArgs.push('/K', cmd);
        tryList.push({ program: 'cmd.exe', args: cmdArgs });

        let spawned = false;
        for (const t of tryList) {
            try {
                const child = ctx.shellApi.Command.create(t.program, t.args);
                await child.spawn();
                spawned = true;
                break;
            } catch {}
        }
        if (!spawned) console.warn('[NB] shell.openTerminal: no terminal found on Windows');
    } else if (platform === 'macos') {
        const script = cwd
            ? `tell application "Terminal" to do script "cd ${cwd.replace(/"/g, '\\"')} && ${cmd.replace(/"/g, '\\"')}"`
            : `tell application "Terminal" to do script "${cmd.replace(/"/g, '\\"')}"`;
        try {
            const child = ctx.shellApi.Command.create('osascript', ['-e', script]);
            await child.spawn();
        } catch (e) {
            console.warn('[NB] shell.openTerminal: failed to open Terminal.app', e);
        }
    } else {
        const sh = cwd ? `cd ${JSON.stringify(cwd)} && ${cmd}; exec bash` : `${cmd}; exec bash`;
        const candidates = [
            ['gnome-terminal', ['--', 'bash', '-c', sh]],
            ['konsole', ['-e', 'bash', '-c', sh]],
            ['xfce4-terminal', ['--command', `bash -c '${sh.replace(/'/g, "'\\''")}'`]],
            ['xterm', ['-e', 'bash', '-c', sh]],
        ];
        let spawned = false;
        for (const [bin, args] of candidates) {
            try {
                const child = ctx.shellApi.Command.create(bin, args);
                await child.spawn();
                spawned = true;
                break;
            } catch {}
        }
        if (!spawned) console.warn('[NB] shell.openTerminal: no terminal found on Linux');
    }
}

// Streaming spawn — start a long-lived process, stream its stdout/stderr back
// line by line, and keep the Child in `running` (keyed by id) so it can be fed
// stdin (shell_write) and terminated (shell_kill) afterwards.
async function spawnStreaming(payload, ctx, program, args, options) {
    const id = payload.id || null;

    // Force a text encoding so stdout/stderr emit newline-delimited strings
    // rather than raw Uint8Array chunks (the caller can still override it).
    const spawnOptions = { encoding: 'utf-8', ...options };
    const command = ctx.shellApi.Command.create(program, args, spawnOptions);

    command.stdout.on('data', (line) =>
        ctx.post('nativeblade-shell-data', { chunk: line, stream: 'stdout', id }));
    command.stderr.on('data', (line) =>
        ctx.post('nativeblade-shell-data', { chunk: line, stream: 'stderr', id }));
    command.on('close', (payload2) => {
        if (id !== null) running.delete(id);
        ctx.post('nativeblade-shell-exit', { exitCode: payload2?.code ?? -1, id });
    });
    command.on('error', (err) => {
        if (id !== null) running.delete(id);
        ctx.post('nativeblade-shell-exit', { exitCode: -1, error: String(err), id });
    });

    try {
        const child = await command.spawn();
        if (id !== null) running.set(id, child);
        ctx.post('nativeblade-shell-spawned', { id, pid: child?.pid ?? null });
    } catch (e) {
        if (id !== null) running.delete(id);
        ctx.post('nativeblade-shell-exit', { exitCode: -1, error: e?.message || String(e), id });
    }
}

// Write to a spawned process's stdin. A trailing newline is appended unless
// `newline: false` (line-delimited protocols like `claude --output-format
// stream-json` expect one line per message).
export async function shell_write(payload, ctx) {
    const child = running.get(payload.id);
    if (!child) return;
    try {
        const suffix = payload.newline === false ? '' : '\n';
        await child.write((payload.data ?? '') + suffix);
    } catch (e) {
        ctx.post('nativeblade-shell-exit', { exitCode: -1, error: e?.message || String(e), id: payload.id });
    }
}

// Terminate a spawned process (and its tree, per the OS). Idempotent: a
// missing id is a no-op, and the `close` handler removes it from `running`.
export async function shell_kill(payload) {
    const child = running.get(payload.id);
    if (!child) return;
    running.delete(payload.id);
    try { await child.kill(); } catch {}
}

// Kill every tracked process. Wired to app teardown so no child is orphaned
// when the window closes — the desktop equivalent of Electron's child-registry
// killAllSync.
export async function shell_kill_all() {
    const children = [...running.values()];
    running.clear();
    for (const child of children) {
        try { await child.kill(); } catch {}
    }
}
