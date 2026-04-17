<?php

namespace NativeBlade\Plugins;

/**
 * Fluent builder for a shell command execution.
 *
 * Desktop-only. On mobile platforms the call is a no-op and an error
 * result is emitted with `exitCode = -1` and a "not supported" stderr
 * so listener code can still handle it uniformly.
 *
 * Results arrive via the `nb:shell-result` Livewire event with four
 * arguments: `$stdout`, `$stderr`, `$exitCode` and `$id`.
 *
 * Example:
 * ```php
 * NativeBlade::shell(function (Shell $s) {
 *     $s->id('docker_check')->run('docker ps');
 * });
 * ```
 *
 * When `openTerminal()` is called, the command is spawned in the OS
 * terminal (Windows Terminal / cmd / PowerShell on Windows, Terminal.app
 * on macOS, gnome-terminal/xterm on Linux) instead of being captured.
 * In that mode no `nb:shell-result` event is emitted — the command runs
 * visibly in a terminal window the user can interact with.
 *
 * @see \NativeBlade\NativeResponse::shell()
 */
class Shell
{
    private ?string $id = null;
    private ?string $command = null;
    private ?string $cwd = null;
    private array $env = [];
    private ?int $timeout = null;
    private bool $openTerminal = false;
    private ?string $terminalType = null;

    /**
     * Tag the execution with an identifier echoed back in the result event.
     *
     * Use this when a component fires more than one shell command — the
     * id arrives as the `$id` argument on the `nb:shell-result` listener
     * so you can route the response without tracking state between the
     * request and the reply.
     */
    public function id(string $id): static
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Set the command line to execute.
     *
     * The string is passed to the platform shell (`cmd /C` on Windows,
     * `/bin/sh -c` on Unix) so shell features like pipes and redirection
     * work as expected.
     */
    public function run(string $command): static
    {
        $this->command = $command;
        return $this;
    }

    /**
     * Override the working directory for the command.
     *
     * Defaults to the current working directory of the Tauri process
     * when not set.
     */
    public function cwd(string $path): static
    {
        $this->cwd = $path;
        return $this;
    }

    /**
     * Additional environment variables to expose to the command.
     *
     * These are merged on top of the inherited process environment.
     *
     * @param  array<string, string>  $env
     */
    public function env(array $env): static
    {
        $this->env = $env;
        return $this;
    }

    /**
     * Maximum time in seconds to wait for the command to finish before
     * it is killed and a timeout error is reported.
     */
    public function timeout(int $seconds): static
    {
        $this->timeout = $seconds;
        return $this;
    }

    /**
     * Spawn the command inside the OS terminal so the user can see and
     * interact with it.
     *
     * When this flag is set the command is launched with a visible
     * terminal window and no `nb:shell-result` event is emitted — the
     * process lives outside the app and its output is not captured.
     *
     * @param  string|null  $type  Optional Windows-only preference:
     *                             `'wt'` (Windows Terminal), `'cmd'` or
     *                             `'powershell'`. Ignored on other
     *                             platforms, where the default terminal
     *                             is auto-detected.
     */
    public function openTerminal(?string $type = null): static
    {
        $this->openTerminal = true;
        $this->terminalType = $type;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'command' => $this->command,
            'openTerminal' => $this->openTerminal,
        ];

        if ($this->id !== null)           $payload['id'] = $this->id;
        if ($this->cwd !== null)          $payload['cwd'] = $this->cwd;
        if (!empty($this->env))           $payload['env'] = $this->env;
        if ($this->timeout !== null)      $payload['timeout'] = $this->timeout;
        if ($this->terminalType !== null) $payload['terminalType'] = $this->terminalType;

        return $payload;
    }
}
