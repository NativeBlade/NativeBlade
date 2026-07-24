---
title: "Native Shell Modules"
description: "Stateful native UI bound to a Livewire component with HasNativeShell."
---

# Native Shell Modules (prototype)

A shell component is a JS module that lives in the webview **shell**,
the parent window, outside the app iframe. Because the shell survives SPA
navigations, a shell module can keep playing audio/video across screens
(mini-player), hold a canvas, or wrap any long-lived JS the page lifecycle
would otherwise kill.

The state bridge is attribute-driven: mark component props with `#[NativeProp]`
and the framework keeps the two sides in sync over pipes that already exist,
no new request types, and never a request-per-frame.

## Two ways to summon it

One file in `nativeblade-components/{name}/`, summoned either way. You pick per
use, not per scaffold.

**Declarative (chrome, no host).** Drop the Blade tag in any view:

```blade
<x-nativeblade-status-bar message="Saving..." />
```

The setup function runs with the tag's attributes as `props`, and the component
is torn down when a screen stops rendering it. No Livewire component sits behind
it, so `nb.php.set` has nowhere to land (ignored with a warning) and `nb.php.emit`
fires only the **generic global** event, which any
`#[On('nb:shell:status-bar:{event}')]` on any component can catch. Use it for a
nav bar, toast, or dialog that carries no server state.

**Bound (stateful, two-way).** `use HasNativeShell` on a Livewire component (the
rest of this page). `#[NativeProp]` props then sync both directions,
`$this->shell(...)` sends commands, and `$shellPersist` keeps the module across
navigation.

Same JS file both ways. Start declarative and add `HasNativeShell` the day you
need server state, without rewriting the module.

::: callout warning "The shell has no Livewire, so no wire: directives"
A shell component's markup lives in the shell document; the Livewire client
runtime lives inside the app iframe and never reaches it. So `wire:model`,
`wire:click`, `wire:submit`, and every other `wire:` directive are **dead** in a
shell component, it is plain HTML and CSS (even your app's Tailwind classes do
not cross the iframe boundary). You write the reactivity yourself in the
module's JS, and the way back to PHP is the `nb.php` bridge (`emit` for events,
`set` for shell-owned props), never a directive. If a piece of UI truly
needs `wire:`, it belongs **in-app**, inside the iframe, not in the shell.
:::

## PHP side

```php
use Livewire\Component;
use Livewire\Attributes\On;
use NativeBlade\Attributes\NativeProp;
use NativeBlade\Concerns\HasNativeShell;

class VideoScreen extends Component
{
    use HasNativeShell;

    protected string $shell = 'video-player';   // -> nativeblade-components/video-player/video-player.js
    protected bool $shellPersist = false;        // true: survives navigation (mini-player)

    #[NativeProp] public string $url = '';       // PHP owns: pushed to the module on render
    #[NativeProp] public bool $playing = false;

    #[NativeProp(from: NativeProp::SHELL)]
    public int $position = 0;                    // shell owns: fresh at every hydrate

    public function saveBookmark(): void
    {
        // $this->position is current, injected at hydrate from the shell's
        // latest value, with zero extra requests.
        Bookmark::create(['url' => $this->url, 'position' => $this->position]);
    }

    public function seekTo(int $seconds): void
    {
        $this->shell('seek', $seconds);          // explicit command, not a prop write
    }

    #[On('nb:shell:video-player:ended')]
    public function onEnded(): void { $this->playing = false; }
}
```

## JS side

Shell components live in the app's `nativeblade-components/` folder (the same
`@components` build alias custom shell components use), bundled at build time, so
split one into as many files as you like and `import` freely. Scaffold it with
`php artisan nativeblade:component`; `nativeblade:dev` rebuilds it on change.

The module is a **setup function** that receives one handle, `nb`. The function
body IS the mount: state is plain closure variables, reactions register through
`nb`, and cleanup is automatic.

```js
export default (nb) => {
    const video = nb.element.appendChild(document.createElement('video'));

    nb.php.watch('url',     (url) => video.src = url);                 // <- #[NativeProp] $url
    nb.php.watch('playing', (on)  => on ? video.play() : video.pause());
    nb.php.listen('seek',   ([s]) => video.currentTime = s);          // <- $this->shell('seek', 30)

    video.ontimeupdate = () => nb.php.set('position', Math.floor(video.currentTime));
    video.onended      = () => nb.php.emit('ended');
};
```

### The `nb` handle

| Member | What it does |
|---|---|
| `nb.element` | Your root `<div id="nb-{name}">`. Created on first access, appended for you, removed on destroy. |
| `nb.php.watch(prop, fn)` | React to a PHP-owned `#[NativeProp]`. `fn(value)` runs with the initial value on mount and again on every change (partial patch, changed props only). |
| `nb.php.listen(name, fn)` | React to `$this->shell(name, ...args)`. `fn(args)` gets the args array. |
| `nb.php.set(key, value)` | Write a `#[NativeProp(from: SHELL)]` prop (bound only). |
| `nb.php.emit(event, data)` | Fire `#[On('nb:shell:{name}:event')]` on any component. |
| `nb.php.props` | Read-only snapshot of the last props PHP pushed. |
| `nb.context.place(el, pos, opts)` | Optional safe-area positioning: `top-left\|top-center\|top-right\|bottom-left\|bottom-center\|bottom-right\|center`, `{ offset = 10, zIndex = 99999 }`. Sets only what positioning needs; style the rest yourself. |
| `nb.context.id` / `nb.context.shell` | The instance id and the shell name. |
| `nb.onCleanup(fn)` | Teardown for anything you made yourself (a `setInterval`, a `window` listener). What `nb` gave you (element, watchers, listeners) is torn down automatically. |

::: callout warning "`nb.php` is not `$this`, and it is not RPC"
`nb.php` is an async MESSAGE bridge to your bound Livewire component, not the
live `$this` (a PHP object rebuilt per request inside the iframe, unreachable
from the shell). So it is verbs only, `emit` / `listen` / `set` / `watch` /
`props`, never `nb.php.call('SomeService::method')` or reading a component
property inline. Everything crosses the iframe boundary as a message; nothing
returns a value synchronously. A host-less (declarative `<x-...>`) component has
no Livewire behind it, so `listen` and `set` are inert there, while `emit` still
fires the global event and `props` still reflects the tag attributes.
:::

### Organize by feature, not by lifecycle

Because `nb` is a value you pass around, split a growing component into helper
functions (or files) grouped by concern, not by lifecycle phase, one feature no
longer smears across mount/update/command/destroy:

```js
export default (nb) => {
    const video = nb.element.appendChild(document.createElement('video'));
    playback(nb, video);
    progress(nb, video);
};

function playback(nb, video) {
    nb.php.watch('url',     (url) => video.src = url);
    nb.php.watch('playing', (on)  => on ? video.play() : video.pause());
    nb.php.listen('seek',   ([s]) => video.currentTime = s);
}

function progress(nb, video) {
    video.ontimeupdate = () => nb.php.set('position', Math.floor(video.currentTime));
    video.onended      = () => nb.php.emit('ended');
}
```

## Prop directions, one owner each

| Declaration | Owner | How the other side sees it | Cost |
|---|---|---|---|
| `#[NativeProp]` | PHP | the module's `nb.php.watch(prop, fn)`, fired with the value on mount and again on each change (partial patch) | rides the response |
| `#[NativeProp(from: SHELL)]` | shell (`nb.php.set`) | injected at hydrate on the **next natural request** | **zero** extra requests |
| `#[NativeProp(from: SHELL, throttle: 500)]` | shell | hydrate injection **plus** an active Livewire update at most once per 500 ms | one request per push, keep coarse |

Default to ride-along. `nb.php.set` at any frequency is safe, 60 writes/s cost
nothing until PHP happens to run a request. Reach for `throttle` only when PHP
must *react* to the change (re-render from the value), and never below a few
hundred ms. To move a value against its direction (PHP adjusting a shell-owned
position), use a command: `$this->shell('seek', 30)`.

**`updated*()` hooks never fire for shell-owned props.** This is permanent
semantics, not a gap: both hydrate injection and the throttled push assign the
value directly, so `updatedPosition()` will not run, don't write it by reflex.
A shell-owned prop is data you *read* inside an action, not an event you react
to; when PHP must react to a moment, have the module `nb.php.emit()` an event and
listen with `#[On]`.

## Events

`nb.php.emit('ended', {reason: 'eof'})` is delivered on two names, pick one:

- `#[On('nb:shell:video-player:ended')]`, generic; payload includes `$id`.
- `#[On('nb:shell:video-player:{shellId}:ended')]`, instance-scoped, for
  multiple instances of the same component on one screen (`$shellId` is a
  public prop the trait fills with the component id).

Events are for **human-paced** moments (ended, error, levelUp). High-frequency
data goes in shell-owned props or a `deliver: 'js'` realtime connection
([Realtime](/core/realtime/) §8), never per-frame events.

## Lifecycle

- The instance is created when the component mounts: the setup function runs
  once, and its closure holds the state for that instance.
- Non-persistent instances are destroyed on navigation. `$shellPersist = true`
  keeps the module alive across screens; end it explicitly with
  `$this->shellDestroy()` (e.g. "close mini-player") and bring it back with
  `$this->shellMount()` ("reopen mini-player").
- A **persistent module is a singleton per shell name**: navigating back to
  the screen gives the component a new Livewire id, and the new mount *adopts*
  the running instance (the setup does not run again, the video keeps playing;
  current props flow in through the watchers). One instance per `$shell` name.
- **A persistent shell has ONE owner component.** Adoption is the semantics of
  *the same owner coming back to its screen*, if two different components
  declare the same `$shell`, the second one adopts the instance and its props
  silently overwrite the first's (a `tab-bar` with `unread = 3` on Home gets
  zeroed by Profile's `unread = 0`). The framework logs a console warning when
  it detects a different owner adopting. The right shape: declare the shell
  **once**, in a component that lives above navigation (an app-shell/layout
  component); every other screen or service interacts with the module **by
  name**, without declaring anything:

  ```php
  // AppShell.php, the single owner, lives as long as the app lives
  protected string $shell = 'audio-player';
  protected bool $shellPersist = true;
  #[NativeProp] public string $trackUrl = '';

  // AlbumScreen.php, no $shell here; commands the module by name
  return NativeBlade::shellCommand('audio-player', 'play', [
      'trackId' => $trackId,
      'queue'   => $this->queueIds,
  ])->toResponse();
  ```

  **What a command may touch, and how breaking this fails.** A command must
  never change state that a PHP-owned prop describes. The framework cannot
  enforce this (a command name is an opaque string, nothing can detect that
  `'play'` collides with what `$trackUrl` shows), and because prop updates are
  **diffed** (only changed props are pushed, so an unrelated owner render never
  reverts module state), breaking it **fails silently**: command `play(B)` from
  a screen without writing the shared state, and on the owner's next render it
  derives `A`, the diff sees no change, nothing is pushed, the module keeps
  playing B while PHP believes A. No flicker, no revert, no console error. The
  bug surfaces days later, as a bookmark saved with the wrong track.

  That makes the shared source **mandatory, not a style preference**: state
  that PHP-owned props display (`trackUrl`, `playing`) lives in exactly one
  place, a service / `app/Native/State` wrapper. A screen that commands the
  module writes that state through the service **in the same action** as the
  `shellCommand`, and the owner derives its props from that source whenever it
  next runs. Shell-owned props (`position`) and transient behavior are fair
  game from anywhere, they have no PHP-owned description to contradict.
- A remount of the same component id replaces the instance (the old one's
  `onCleanup` runs and its `nb.element` is removed first).
- **Keep state in the setup closure, not at module scope.** Each mount runs the
  setup afresh, so closure state resets on destroy → mount, while `$shellPersist`
  adoption keeps it across navigation, both behave as expected. Module-scope
  variables (`let n = 0` above the export) survive even `shellDestroy()`,
  because the loaded code is cached; use them only when you explicitly want
  state to outlive the module instance.

## Current limitations (prototype)

- `#[NativeProp]` values must be small and JSON-serializable, a PHP-owned
  prop rides the Livewire payload **twice** (its value plus the last-flushed
  shadow that powers update diffing), so a big blob in a prop costs double.
- One module per component (`$shell` is a single name).
