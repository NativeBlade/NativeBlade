# NativeBlade Documentation

This folder is the source for [docs.nativeblade.dev](https://docs.nativeblade.dev).
It is a static site built with [docmd](https://docs.docmd.io). If you want to fix
a typo, improve a page, or add a new one, everything you need is here.

## Run it locally

Requirements: Node 18 or newer and [pnpm](https://pnpm.io).

```bash
cd docs
pnpm install
pnpm dev
```

Open http://localhost:3000. The site reloads as you edit.

Build the static output (goes to `site/`):

```bash
pnpm build
```

## Structure

```
docs/
  docmd.config.json    site config: title, theme, versions, and the sidebar
  docs/                the content, one folder per section
    core/              framework concepts (architecture, plugins, ...)
    mobile/            mobile setup and per-plugin pages
    desktop/           desktop features
    configuration/     app configuration
    guides/            publish, studio, upgrade, portal, ...
  assets/              brand CSS, logo, favicon
```

Every page is a Markdown file with frontmatter:

```markdown
---
title: "Windows"
description: "One line shown in search results and page metadata."
---

# Windows

Your content here.
```

## Add or edit a page

1. Create or edit a `.md` file under `docs/`.
2. Add it to the sidebar in `docmd.config.json` under `navigation`. The `path` is
   the file location without `.md` and with a trailing slash, for example a file
   at `docs/core/windows.md` has the path `/core/windows/`.
3. Run `pnpm dev` to preview, and `pnpm build` to confirm it builds.

## Conventions

Keep the docs consistent:

- **English only.**
- **No em-dashes.** Use commas, colons, or separate sentences.
- **No decorative icons.** Keep pages clean and readable.
- **Do not link to `.md` files.** Link to internal pages instead, such as
  `[Plugins](/core/plugins/)`. The only exception is the few files that live at
  the repository root (README, ARCHITECTURE, CONTRIBUTING, SPONSORS): link to
  those on GitHub and open them in a new tab.
- **Keep Mobile and Desktop as flat lists** in the sidebar. docmd renders nested
  groups and anchor-only nav items poorly.
- Rich blocks are available and can be used sparingly: callouts
  (`::: callout tip "Title"`), cards (`::: card "Title"`), and tabs.

## Versioning

The docs follow NativeBlade SDK versions, and the content here is the current SDK
line. To cut a new version, copy the current `docs/` content into a versioned
folder and register it in `docmd.config.json` under `versions`. Old versions stay
reachable from the version switcher at the top of the sidebar.

## Contributing

Pull requests are welcome. Open a PR against the framework repository with your
changes under this `docs/` folder, follow the conventions above, run
`pnpm build` to confirm the site builds, and describe what you changed. That
is all it takes.
