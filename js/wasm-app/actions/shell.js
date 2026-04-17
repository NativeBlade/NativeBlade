// Shell action (desktop only) — captured execution + openTerminal
// Uses: ctx.shellApi, ctx.osApi, ctx.isMobile, ctx.post

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

        // Captured execution — run via platform shell and report stdout/stderr/exitCode
        const program = isWin ? 'cmd' : 'sh';
        const args = isWin ? ['/C', payload.command] : ['-c', payload.command];
        const options = {};
        if (payload.cwd) options.cwd = payload.cwd;
        if (payload.env && typeof payload.env === 'object') options.env = payload.env;

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
