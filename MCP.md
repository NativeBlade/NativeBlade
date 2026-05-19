# MCP — AI Coding Agent Integration

NativeBlade ships a built-in [Model Context Protocol](https://modelcontextprotocol.io) server so AI coding agents (Claude Code, Cursor, Windsurf, Cline, and anything MCP-compliant) can introspect the **live state of your project** instead of guessing from stale documentation.

Without MCP, an agent suggesting NativeBlade code is working blind. It paraphrases what it saw in training data, hallucinates methods that never existed, and references plugins the user hasn't installed. With MCP, the agent calls the framework on demand and gets exact answers grounded in the version of NativeBlade actually installed in your `vendor/`.

## Start the server

From your project root:

```bash
php artisan nativeblade:mcp
```

The server speaks JSON-RPC 2.0 over stdin/stdout. You normally don't run it by hand. Your agent's MCP client launches it for you when configured (see below).

## Agent configuration

### Claude Code

Add to `~/.claude/mcp_servers.json` (global) or `.mcp.json` at the project root (per-project):

```json
{
    "mcpServers": {
        "nativeblade": {
            "command": "php",
            "args": ["artisan", "nativeblade:mcp"]
        }
    }
}
```

### Cursor

`~/.cursor/mcp.json`:

```json
{
    "mcpServers": {
        "nativeblade": {
            "command": "php",
            "args": ["artisan", "nativeblade:mcp"]
        }
    }
}
```

### Windsurf

`~/.codeium/windsurf/mcp_config.json`:

```json
{
    "mcpServers": {
        "nativeblade": {
            "command": "php",
            "args": ["artisan", "nativeblade:mcp"]
        }
    }
}
```

### Any other MCP client

Any client that speaks the standard stdio transport works. Point it at `php artisan nativeblade:mcp` and that's it.

## What the agent gets

Six tools, called on demand. Nothing is loaded into context until the agent decides it needs it.

### `list_facade_methods`

Returns every method on the `NativeBlade` and `NativeBladeConfig` facades, each with its signature and a one-line summary. The agent calls this first to discover what is available.

```json
[
    {
        "facade": "NativeBlade",
        "purpose": "Runtime / native action builders...",
        "methods": [
            {"name": "notification", "signature": "NativeResponse notification(Closure $callback)", "static": true},
            {"name": "biometric",    "signature": "NativeResponse biometric(Closure $callback)",    "static": true},
            ...
        ]
    }
]
```

### `describe_facade_method`

Full signature, summary, parameter list, return type, and (where available) a code example for one specific method. The agent calls this after finding the method it wants via `list_facade_methods`.

```json
{
    "found": true,
    "name": "notification",
    "facade": "NativeBlade\\Facades\\NativeBlade",
    "signature": "NativeResponse notification(Closure $callback)",
    "summary": "Queue a system notification built via a fluent Notification builder.",
    "description": "Fire-and-forget — no result is returned to PHP.",
    "params": [{"name": "callback", "type": "Closure(Notification): void", "description": ""}],
    "source": "NativeBlade\\NativeResponse::notification()"
}
```

Reflection-based, so it works against the exact version of NativeBlade installed in your `vendor/`, not whatever was current when the agent's training data was frozen.

### `project_state`

The actual state of the project the agent is editing: which plugins are declared in `AppServiceProvider`, which per-platform configs are set (window size, identifier, permissions, statusbar), the default page transition, and the installed framework version.

```json
{
    "nativeblade_version": "1.7.8",
    "plugins": {
        "declared": ["media", "push", "biometric"],
        "all_available": ["media", "push", "geolocation", "biometric", ...],
        "mode": "explicit (only declared plugins ship in the binary)"
    },
    "transition": "slide",
    "app_configs": { "android": {...}, "ios": {...}, "desktop": {...} }
}
```

This is the killer tool: an agent that knows you only have `push` and `biometric` declared won't suggest `NativeBlade::scan()` and silently break your build.

### `list_docs`

Returns the list of framework `.md` documentation files (README, PLUGINS, MEDIA, PUSH, SCHEDULER, etc.) with their topic and a short summary. The agent uses this to figure out which doc to read for a given question.

```json
{
    "docs": [
        {"name": "ANIMATIONS.md", "title": "Animations", "summary": "..."},
        {"name": "PLUGINS.md",    "title": "Native Plugins", "summary": "..."},
        ...
    ]
}
```

### `read_doc`

Returns the full Markdown content of one doc file. Only callable for files inside the framework docs directory — path traversal is blocked.

```json
{ "name": "MEDIA.md" }
```

### `architecture_recipe`

Returns the canonical NativeBlade pattern for a specific use case: component-controller, form-validation, global-state, push-handler, deep-link, biometric-flow, multiple-http-pool, repository-vs-eloquent, http-client, file-organization, anti-patterns. Call with no arguments to list every available recipe; call with `use_case=<name>` for the full text plus example code.

This is the **opinionated** tool. The other five describe what NativeBlade IS; this one tells the agent how to USE it. Agents that follow these recipes produce code that fits the framework's architecture out of the box (thin Livewire controllers, services for business logic, typed state wrappers, push handlers as classes, etc.).

```json
{ "use_case": "push-handler" }
```

The agent typically calls `architecture_recipe()` (no args) once to discover all recipes, then `architecture_recipe(use_case='X')` whenever generating code that touches X.

## Typical agent flow

For a request like *"add a camera button that uploads the photo to /api/uploads"*, a well-behaved agent does:

1. `project_state()` — confirms `media` plugin is declared and what permissions are set.
2. `list_facade_methods()` — finds `pickCamera`, `upload`.
3. `describe_facade_method("pickCamera")` + `describe_facade_method("upload")` — gets exact signatures.
4. Generates code that compiles against the installed framework version on the first try.

Total tokens consumed: a few hundred. No 17 markdown files loaded up front.

## Implementation notes

- **Transport: stdio.** No HTTP server, no port, no auth surface. Each agent client spawns its own server process.
- **Reflection-based.** When you add a method to `NativeResponse` or a case to the `Plugin` enum, the agent sees it on the next call. No manual sync.
- **Zero-config.** No env vars, no setup script. Run the Artisan command and it works.
- **Sandboxed file access.** `read_doc` only opens files inside the framework root, validated by regex and `basename()`.
- **Protocol version negotiation.** Server speaks `2025-11-25` (latest) and negotiates down to any of `2025-06-18`, `2025-03-26`, `2024-11-05` if the client requests an older one. Compatible with current Claude Code, Cursor, Windsurf, Cline releases.

## Troubleshooting

**Agent says it can't find the server.** Make sure `php` is on your PATH and the working directory in your MCP client config points at the project root (where `artisan` lives). Some clients let you set `cwd` explicitly in the config object.

**Tool calls return errors.** Make sure NativeBlade is fully installed (`composer install`) and the autoloader knows about the `NativeBlade\Facades\*` classes. The server reflects on real classes; if they aren't loadable, the tool fails gracefully and reports the error back.

**Want to verify the server works.** Run the self-test:

```bash
php artisan nativeblade:mcp --test
```

You should see five green checkmarks confirming `initialize`, `tools/list`, and each of the live tools (`project_state`, `list_docs`, `list_facade_methods`) returning sane data.

**Or pipe a JSON-RPC handshake by hand:**

```bash
echo '{"jsonrpc":"2.0","id":1,"method":"initialize"}' | php artisan nativeblade:mcp
```

You should get back a single JSON line with `serverInfo.name = "nativeblade"`.

**Running it without arguments shows nothing?** That's actually a feature, not a bug — when you run `php artisan nativeblade:mcp` directly in a terminal, the command detects the interactive TTY and prints setup instructions to stderr instead of starting the JSON-RPC loop (which would just sit there waiting for messages no human would type). Run `--test` to verify, or pipe input as shown above.
