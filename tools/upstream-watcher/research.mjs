// Upstream research — local, harness-driven.
//
// For each watched dependency: get its latest release and let the Claude Code
// CLI (`claude -p`, your logged-in account, no API key) actually grep/read THIS
// repo to decide whether the release breaks anything we rely on. Writes one
// Markdown report per dependency under tools/upstream-watcher/research/.
//
// Two source kinds:
//   github: has clean GitHub releases -> we fetch the notes and pass them in.
//   web:    no clean release feed (Android AGP/NDK, iOS Xcode/WKWebView) -> we
//           give the agent the release-notes URLs and it WebFetches them itself.
//
// The hints are a SEED, not an exhaustive map — the agent finds the real call
// sites, which is the whole reason to use the harness over a single API call.
//
// Requirements: Node 18+ (global fetch) and the `claude` CLI installed + logged in.
//
// Run:
//   node tools/upstream-watcher/research.mjs            # all watched deps
//   node tools/upstream-watcher/research.mjs laravel    # just one
//   node tools/upstream-watcher/research.mjs --force     # re-research

import { spawn } from "node:child_process";
import { writeFileSync, mkdirSync, existsSync } from "node:fs";
import { fileURLToPath } from "node:url";
import path from "node:path";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = process.env.REPO_ROOT || path.resolve(HERE, "../..");
const OUT_DIR = path.join(HERE, "research");
const MAX_NOTES = 20_000;
const TODAY = new Date().toISOString().slice(0, 10);

const args = process.argv.slice(2);
const FORCE = args.includes("--force");
const ONLY = args.find((a) => !a.startsWith("--"));

// The one place to tweak the CLI invocation. Read-only tools + web fetch, so it
// never edits/writes/runs bash. If YOUR Claude Code version blocks on a
// permission prompt, add the flag it wants (see `claude --help`).
const CLAUDE_CMD =
  'claude -p --output-format text --allowedTools "Read Grep Glob WebFetch WebSearch" --max-turns 40';

const WATCH = [
  {
    name: "livewire",
    kind: "github",
    repo: "livewire/livewire",
    hint: [
      "We depend on some non-public Livewire internals:",
      "- dehydrate/hydrate lifecycle + snapshot shape: src/Concerns/HasNativeShell.php hooks",
      "  hydrate/rendered to sync #[NativeProp] state to the native shell (src/Attributes/NativeProp.php).",
      "- the /livewire/update request contract: js/wasm-app/interceptor/fetch-override.js captures",
      "  Livewire's POST and routes it into php-wasm; js/wasm-app/router.js + js/runtime/request-handler.js",
      "  parse the response (components / snapshot / effects).",
    ].join("\n"),
  },
  {
    name: "laravel",
    kind: "github",
    repo: "laravel/framework",
    hint: [
      "We extend Laravel internals heavily:",
      "- src/Database/NativeConnection.php extends Illuminate\\Database\\Connection and overrides",
      "  select()/insert()/update()/delete()/statement()/transactions, using stock query+schema",
      "  Grammars and Processors per driver. select()'s signature has drifted across versions",
      "  (it gained a $fetchUsing param).",
      "- src/Database/HasNativeDatabase.php overrides updateOrCreate()/firstOrCreate().",
      "- src/Storage/NativeFilesystemAdapter.php implements League\\Flysystem\\FilesystemAdapter.",
      "- src/NativeBladeServiceProvider.php swaps the Http client's Guzzle handler",
      "  (Http::globalOptions) and syncs the Date/Carbon facade.",
      "Seams: Connection/Grammar/Processor method signatures, the HTTP client handler contract,",
      "the Flysystem adapter interface, Carbon/Date.",
    ].join("\n"),
  },
  {
    name: "tauri",
    kind: "github",
    repo: "tauri-apps/tauri",
    hint: [
      "Tauri v2. We use invoke/IPC, the plugin ACL/capabilities JSON format, and desktop-gated",
      "WebviewWindow builder methods (rust/src/commands/window.rs uses #[cfg(desktop)]). JS dispatch",
      "goes through js/wasm-app/bridge.js + js/wasm-app/actions/*. We also ship custom plugins under",
      "rust/plugins/* that implement the Tauri plugin traits.",
      "Seams: invoke/IPC contract, capability/permission format, WebviewWindow builder API, plugin",
      "init()/trait signatures. Note: the reused OFFICIAL plugins (geolocation, biometric, nfc,",
      "haptics, clipboard, ...) version independently from tauri core.",
    ].join("\n"),
  },
  {
    name: "android",
    kind: "web",
    sources: [
      "https://developer.android.com/build/releases/gradle-plugin",
      "https://developer.android.com/ndk/downloads/revision_history",
    ],
    hint: [
      "Our Android build config lives in rust/plugins/*/android/build.gradle.kts (compileSdk, minSdk,",
      "ndk). We had to satisfy Play's 16 KB page-size requirement (NDK r26+, linker/cargo flags).",
      "Seams: compileSdk/targetSdk bumps, AGP/Gradle compatibility, NDK version + 16 KB page size,",
      "any new Play manifest/permission requirement.",
    ].join("\n"),
  },
  {
    name: "ios",
    kind: "web",
    sources: ["https://developer.apple.com/documentation/xcode-release-notes"],
    hint: [
      "Our iOS plugins are Swift packages: rust/plugins/*/ios/Package.swift + Sources/*.swift. The app",
      "renders in WKWebView (js/wasm-app/router.js leans on WKWebView behavior).",
      "Seams: min iOS / Swift tools version, WKWebView behavior changes, signing/provisioning",
      "requirement changes, StoreKit/APNS SDK shifts used by the payments/push plugins.",
    ].join("\n"),
  },
];

function slug(s) {
  return String(s).replace(/[^a-zA-Z0-9._-]/g, "_");
}

async function latestRelease(repo) {
  const headers = { "User-Agent": "nb-upstream-research", Accept: "application/vnd.github+json" };
  if (process.env.GITHUB_TOKEN) headers.Authorization = `Bearer ${process.env.GITHUB_TOKEN}`;
  const res = await fetch(`https://api.github.com/repos/${repo}/releases/latest`, { headers });
  if (!res.ok) throw new Error(`GitHub ${res.status} for ${repo}: ${await res.text()}`);
  const r = await res.json();
  return { version: r.tag_name, url: r.html_url, notes: (r.body || "").slice(0, MAX_NOTES) };
}

const REPORT_SHAPE = (title) => `Then output ONLY a Markdown report, no preamble and no closing chatter, in exactly this shape:

# ${title}

**Verdict:** <one line: impacted / no impact on our code>
**Confidence:** <high | medium | low>

## What changed upstream
<the changes relevant to us, or "nothing that touches our surfaces">

## Where it hits us
<for each: \`path/file.ext:line\`, what we do there, and the likely failure mode. Or "no affected call sites found.">

## Proposal
<concrete next steps: which files to change or verify, or "no action needed">

## Could not verify
<anything needing the upstream source/diff or a build to be sure; leave empty if none>`;

function githubPrompt(dep, rel) {
  return `You are running inside the NativeBlade framework repository (Laravel + Livewire -> native app framework). You have read-only tools: Read, Grep, Glob.

An upstream dependency shipped a new release. Investigate whether it breaks anything THIS repo relies on. The hint is a seed, NOT the whole story: use Grep/Glob/Read to find the ACTUAL places our code touches ${dep.name}, including call sites the hint does not mention.

UPSTREAM: ${dep.name} ${rel.version}
RELEASE: ${rel.url}

RELEASE NOTES:
${rel.notes || "(empty release body)"}

KNOWN FRAGILE SEAMS (seed):
${dep.hint}

STEPS:
1. From the release notes, identify what actually changed (APIs, signatures, payload/snapshot shapes, behavior).
2. Grep this repo for the affected symbols/patterns and read the relevant files to confirm whether we depend on it.
3. Decide impact honestly.

${REPORT_SHAPE(`${dep.name} ${rel.version} — impact review`)}`;
}

function webPrompt(dep) {
  return `You are running inside the NativeBlade framework repository (Laravel + Livewire -> native app framework, packaged for Android/iOS/desktop via Tauri). You have read-only tools: Read, Grep, Glob, WebFetch, WebSearch.

There is no clean release feed for ${dep.name}, so FIRST fetch the latest release/version notes yourself from these URLs (WebFetch; use WebSearch to fill gaps), then investigate whether anything recent breaks what THIS repo relies on. The hint is a seed, NOT the whole story — grep the repo for the real touch points.

RELEASE-NOTE SOURCES for ${dep.name}:
${dep.sources.map((u) => `- ${u}`).join("\n")}

KNOWN FRAGILE SEAMS (seed):
${dep.hint}

STEPS:
1. Fetch the sources; identify the newest relevant version and what changed (build tooling, SDK, signing, page-size/NDK, WKWebView, min OS, manifest requirements).
2. Grep this repo for where that lands (build config, plugins, manifests) and read the files.
3. Decide impact honestly. Say clearly which upstream version you assessed.

${REPORT_SHAPE(`${dep.name} — impact review (as of ${TODAY})`)}`;
}

function runClaude(promptText) {
  return new Promise((resolve, reject) => {
    // shell:true so `claude` resolves cross-platform (claude.cmd on Windows) and
    // the quoted --allowedTools arg parses correctly.
    const child = spawn(CLAUDE_CMD, { cwd: REPO_ROOT, stdio: ["pipe", "pipe", "inherit"], shell: true });
    let out = "";
    child.stdout.on("data", (d) => (out += d));
    child.on("error", (e) =>
      reject(new Error(`could not run \`claude\` (is the CLI installed and on PATH?): ${e.message}`)),
    );
    child.on("close", (code) => (code === 0 ? resolve(out) : reject(new Error(`claude exited ${code}`))));
    child.stdin.write(promptText);
    child.stdin.end();
  });
}

async function main() {
  const deps = WATCH.filter((d) => !ONLY || d.name === ONLY);
  if (deps.length === 0) {
    console.error(`No watched dependency named "${ONLY}". Known: ${WATCH.map((d) => d.name).join(", ")}`);
    process.exit(1);
  }
  mkdirSync(OUT_DIR, { recursive: true });

  for (const dep of deps) {
    let prompt, outFile, sourceLine;
    if (dep.kind === "github") {
      const rel = await latestRelease(dep.repo);
      outFile = path.join(OUT_DIR, `${dep.name}-${slug(rel.version)}.md`);
      prompt = githubPrompt(dep, rel);
      sourceLine = rel.url;
      console.log(`${dep.name} ${rel.version} — researching (${rel.url})...`);
    } else {
      outFile = path.join(OUT_DIR, `${dep.name}-${TODAY}.md`);
      prompt = webPrompt(dep);
      sourceLine = dep.sources.join(", ");
      console.log(`${dep.name} (web) — researching...`);
    }

    if (existsSync(outFile) && !FORCE) {
      console.log(`  report already exists, skipping (--force to redo).`);
      continue;
    }

    const md = await runClaude(prompt);
    const footer = `\n\n---\n_Researched ${TODAY} from ${sourceLine}. Verify against the code before acting._\n`;
    writeFileSync(outFile, md.trim() + footer);
    console.log(`  -> ${path.relative(REPO_ROOT, outFile)}`);
  }
}

main().catch((e) => {
  console.error(e.message || e);
  process.exit(1);
});
