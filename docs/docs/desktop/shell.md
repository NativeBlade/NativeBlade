---
title: "Shell Commands"
description: "Run external commands on desktop."
---

# Shell

Backed by [`tauri-plugin-shell`](https://v2.tauri.app/plugin/shell/). **Desktop only**, on mobile the call is a no-op and a failure event is emitted with `exitCode = -1` and stderr `"not supported on this platform"`, so your listener code can handle both paths uniformly.

Three modes:

- **Captured execution**, runs the command in the platform shell (`cmd /C` on Windows, `/bin/sh -c` on Unix) and delivers stdout / stderr / exit code via the `nb:shell-result` Livewire event once it finishes.
- **`spawn()` (streaming)**, runs the command as a long-lived process, streaming its output line by line as it happens. Each line arrives on `nb:shell-data` (`$chunk`, `$stream`, `$id`) and completion on `nb:shell-exit` (`$exitCode`, `$id`). Feed the process stdin with `NativeBlade::shellWrite($id, ...)` and stop it with `NativeBlade::shellKill($id)`. This is the mode for CLIs that stream (e.g. `--output-format stream-json`), dev servers, and tunnels.
- **`openTerminal()`**, spawns the command inside a visible OS terminal window (Windows Terminal / cmd / PowerShell on Windows, Terminal.app on macOS, gnome-terminal / konsole / xterm on Linux). Fire-and-forget: no result event is emitted, and the user can interact with the process directly.

The `Shell` builder supports:

| Method | Description |
|---|---|
| `->id($identifier)` | Tag the execution, echoed back as `$id` on every result/stream event. **Required for `spawn()`** (it is the handle used to write stdin and kill). |
| `->run($command)` | Command line to execute (passed to the platform shell) |
| `->cwd($path)` | Working directory for the command |
| `->env(['K' => 'V'])` | Extra environment variables, merged on top of the process environment |
| `->timeout($seconds)` | (captured mode) Kill the command and emit a timeout error after N seconds |
| `->spawn()` | Stream a long-lived process instead of capturing. Output arrives on `nb:shell-data` / `nb:shell-exit` |
| `->openTerminal($type = null)` | Spawn inside a visible terminal instead of capturing output. `$type` is Windows-only and accepts `'wt'`, `'cmd'` or `'powershell'`, on macOS/Linux the default terminal is auto-detected |

Streaming control (facade methods, not builder):

| Method | Description |
|---|---|
| `NativeBlade::shellWrite($id, $data, $newline = true)` | Write to a spawned process's stdin. A trailing newline is appended unless `$newline` is `false`. No-op if no process with `$id` is running. |
| `NativeBlade::shellKill($id)` | Terminate a spawned process (and its child tree). Idempotent. |
| `NativeBlade::shellKillAll()` | Terminate every spawned process, wire to app teardown so nothing is orphaned. |

### Example: run a captured command

```php
use Livewire\Attributes\On;
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Plugins\Shell;

public function checkDocker()
{
    return NativeBlade::shell(function (Shell $s) {
        $s->id('docker_check')->run('docker ps');
    })->toResponse();
}

public function gitPull()
{
    return NativeBlade::shell(function (Shell $s) {
        $s->id('pull')
          ->cwd('/home/user/repo')
          ->env(['GIT_PAGER' => 'cat'])
          ->timeout(30)
          ->run('git pull');
    })->toResponse();
}

#[On('nb:shell-result')]
public function onShellResult($stdout = null, $stderr = null, $exitCode = null, $id = null)
{
    match ($id) {
        'docker_check' => $this->parseDocker($stdout),
        'pull'         => $this->updateBranchStatus($exitCode),
        default        => null,
    };
}
```

### Example: stream a long-lived CLI (spawn + stdin + kill)

Drive an interactive, streaming CLI, start it, feed it a prompt on stdin, render output as it arrives, and stop it on demand. This is exactly how a tool like NativeBlade Studio drives `claude`, a dev server, or a tunnel.

```php
use Livewire\Attributes\On;
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Plugins\Shell;

public array $log = [];

public function startAgent()
{
    return NativeBlade::shell(function (Shell $s) {
        $s->id('agent')
          ->cwd($this->projectPath)
          ->run('claude -p --output-format stream-json --verbose')
          ->spawn();                       // long-lived, streamed
    })->toResponse();
}

public function ask(string $prompt)
{
    // Feed one JSON line to the running process's stdin.
    return NativeBlade::shellWrite('agent', json_encode(['role' => 'user', 'content' => $prompt]))
        ->toResponse();
}

public function stop()
{
    return NativeBlade::shellKill('agent')->toResponse();
}

#[On('nb:shell-data')]
public function onData($chunk = '', $stream = 'stdout', $id = null)
{
    if ($id === 'agent') {
        $this->log[] = ['stream' => $stream, 'line' => $chunk];
    }
}

#[On('nb:shell-exit')]
public function onExit($exitCode = null, $id = null)
{
    if ($id === 'agent') {
        $this->log[] = ['stream' => 'system', 'line' => "process exited ({$exitCode})"];
    }
}
```

> **High-frequency streams:** each `nb:shell-data` line is a Livewire round-trip. For very chatty processes, buffer/coalesce lines on the JS side or throttle how often you re-render (e.g. accumulate into a plain array and only surface a summary to the reactive view).

### Example: open the command in the OS terminal

```php
public function connectSsh()
{
    return NativeBlade::shell(function (Shell $s) {
        $s->openTerminal()->run('ssh prod-server');
    })->toResponse();
}
```

On Windows you can pick a specific terminal:

```php
NativeBlade::shell(fn (Shell $s) => $s->openTerminal('powershell')->run('Get-Service'));
NativeBlade::shell(fn (Shell $s) => $s->openTerminal('wt')->cwd('C:\\repo')->run('npm run dev'));
```

### Permissions & scope

`nativeblade:install` wires up `shell:allow-execute` + a scope for common shells (`cmd`, `powershell`, `wt`, `sh`, `bash`, `osascript`, `gnome-terminal`, `konsole`, `xfce4-terminal`, `xterm`) in `src-tauri/capabilities/default.json`. To call a different binary directly (without going through the shell), add it to the `shell:allow-execute` scope.

> **Security note.** Shell execution is only enforced by the Tauri capabilities scope, NativeBlade does not sandbox the command itself. Never forward untrusted input into `->run()`. For apps that accept user input, whitelist the set of commands you expose and build the command line yourself.

---

