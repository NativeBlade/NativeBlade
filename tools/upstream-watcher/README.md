# Upstream research (local, harness-driven)

Checks the projects NativeBlade leans on and, when one moves, has the **Claude
Code CLI** investigate this repo (grep/read, for real) to decide whether the
change breaks anything we rely on, then writes a Markdown report + proposal.

No GitHub Actions, no API key, no issue creation. Runs locally on your logged-in
Claude Code account.

## Watched dependencies

| Dep | Source kind | Where |
|---|---|---|
| `livewire` | github | `livewire/livewire` releases |
| `laravel`  | github | `laravel/framework` releases |
| `tauri`    | github | `tauri-apps/tauri` releases |
| `android`  | web    | AGP + NDK release-notes pages (agent WebFetches them) |
| `ios`      | web    | Xcode release notes (agent WebFetches) |

`github` deps have a clean `releases/latest` feed, so the script fetches the
notes and passes them in. `android` / `ios` have no such feed, so the agent
fetches the release-notes pages itself with WebFetch.

## Why the harness instead of an API call

"Does this change break us?" is a search + read + reason task. A single API call
only sees files you hand it, so it needs a hand-maintained map that goes stale.
The Claude Code harness greps the repo itself and finds the real call sites, so
each watch entry only needs a *hint* of the fragile seams, not a full map.

## Requirements

- Node 18+ (global `fetch`).
- The `claude` CLI installed and logged in (`claude --version` works here).

## Run

```bash
node tools/upstream-watcher/research.mjs            # all five
node tools/upstream-watcher/research.mjs laravel    # just one
node tools/upstream-watcher/research.mjs --force     # re-research
```

Reports land in `tools/upstream-watcher/research/`:
`<dep>-<version>.md` for github deps, `<dep>-<date>.md` for web deps. An existing
report is skipped unless `--force`.

## Tweaking

- The CLI invocation is one editable constant (`CLAUDE_CMD`) at the top. If your
  Claude Code version blocks on a permission prompt for the read-only tools, add
  the permission flag it expects (`claude --help`).
- Add a dependency by appending to the `WATCH` array: `{ name, kind, ... , hint }`.
  Keep any paths in the hint real (`git ls-files`). For `tauri`, remember the
  reused official plugins (geolocation, biometric, nfc, ...) version
  independently from tauri core — worth their own entries later.
