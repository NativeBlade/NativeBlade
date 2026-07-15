# Native Shell Modules (prototype)

Bind a Livewire component to a JS module that lives in the webview **shell** —
the parent window, outside the app iframe. Because the shell survives SPA
navigations, a shell module can keep playing audio/video across screens
(mini-player), hold a canvas, or wrap any long-lived JS the page lifecycle
would otherwise kill.

The state bridge is attribute-driven: mark component props with `#[NativeProp]`
and the framework keeps the two sides in sync over pipes that already exist —
no new request types, and never a request-per-frame.

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
        // $this->position is current — injected at hydrate from the shell's
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

## JS side — `nativeblade-components/video-player/video-player.js`

Shell modules live in the app's `nativeblade-components/` folder — the same
place (and `@components` build alias) custom shell components use. They are
bundled at build time, so split the module into as many files as you like and
`import` freely; the default export is the module contract. Changes are picked
up by the `nativeblade:dev` rebuild like any other shell component. Scaffold
one with `php artisan nativeblade:component` (type: **module**).

Besides `ctx.set` / `ctx.emit`, the ctx offers an **optional** positioning
helper: `ctx.place(el, position, { offset = 10, zIndex = 99999 })` with
positions `top-left|top-center|top-right|bottom-left|bottom-center|bottom-right|center`
— fixed placement, safe-area aware. It only sets what positioning needs;
apply your own styles after the call to extend or override any of it (or skip
the helper entirely and position by hand).

```js
export default {
    video: null,

    mount(ctx, props) {
        this.video = document.createElement('video');
        this.video.src = props.url;
        document.body.appendChild(this.video);

        this.video.addEventListener('timeupdate', () => {
            ctx.set('position', Math.floor(this.video.currentTime));  // shell-owned prop
        });
        this.video.addEventListener('ended', () => ctx.emit('ended'));
    },

    update(props) {                       // PHP-owned props after each render
        if (this.video.src !== props.url) this.video.src = props.url;
        props.playing ? this.video.play() : this.video.pause();
    },

    command(name, args) {                 // $this->shell('seek', 30)
        if (name === 'seek') this.video.currentTime = args[0];
    },

    destroy() {
        this.video?.remove();
        this.video = null;
    },
};
```

## Prop directions — one owner each

| Declaration | Owner | How the other side sees it | Cost |
|---|---|---|---|
| `#[NativeProp]` | PHP | module's `update(props)` on every render | rides the response |
| `#[NativeProp(from: SHELL)]` | shell (`ctx.set`) | injected at hydrate on the **next natural request** | **zero** extra requests |
| `#[NativeProp(from: SHELL, throttle: 500)]` | shell | hydrate injection **plus** an active Livewire update at most once per 500 ms | one request per push — keep coarse |

Default to ride-along. `ctx.set` at any frequency is safe — 60 writes/s cost
nothing until PHP happens to run a request. Reach for `throttle` only when PHP
must *react* to the change (re-render from the value), and never below a few
hundred ms. To move a value against its direction (PHP adjusting a shell-owned
position), use a command: `$this->shell('seek', 30)`.

**`updated*()` hooks never fire for shell-owned props.** This is permanent
semantics, not a gap: both hydrate injection and the throttled push assign the
value directly, so `updatedPosition()` will not run — don't write it by reflex.
A shell-owned prop is data you *read* inside an action, not an event you react
to; when PHP must react to a moment, have the module `ctx.emit()` an event and
listen with `#[On]`.

## Events

`ctx.emit('ended', {reason: 'eof'})` is delivered on two names — pick one:

- `#[On('nb:shell:video-player:ended')]` — generic; payload includes `$id`.
- `#[On('nb:shell:video-player:{shellId}:ended')]` — instance-scoped, for
  multiple instances of the same component on one screen (`$shellId` is a
  public prop the trait fills with the component id).

Events are for **human-paced** moments (ended, error, levelUp). High-frequency
data goes in shell-owned props or a `deliver: 'js'` realtime connection
([SOCKET.md](SOCKET.md) §8) — never per-frame events.

## Lifecycle

- Instance is created when the component mounts. The default export may be a
  plain object (shallow-cloned per instance, so `this` state never leaks
  across mounts), a factory function, or a class.
- Non-persistent instances are destroyed on navigation. `$shellPersist = true`
  keeps the module alive across screens; end it explicitly with
  `$this->shellDestroy()` (e.g. "close mini-player") and bring it back with
  `$this->shellMount()` ("reopen mini-player").
- A **persistent module is a singleton per shell name**: navigating back to
  the screen gives the component a new Livewire id, and the new mount *adopts*
  the running instance (no second `mount()` — the video keeps playing; current
  props are applied via `update()`). One persistent instance per `$shell` name.
- **A persistent shell has ONE owner component.** Adoption is the semantics of
  *the same owner coming back to its screen* — if two different components
  declare the same `$shell`, the second one adopts the instance and its props
  silently overwrite the first's (a `tab-bar` with `unread = 3` on Home gets
  zeroed by Profile's `unread = 0`). The framework logs a console warning when
  it detects a different owner adopting. The right shape: declare the shell in
  a component that lives above navigation (an app-shell/layout component) and
  have screens message that owner instead of redeclaring it:

  ```php
  // AppShell.php — lives as long as the app lives
  protected string $shell = 'tab-bar';
  protected bool $shellPersist = true;
  #[NativeProp] public int $unread = 0;

  // HomeScreen.php — doesn't declare $shell, just tells the owner
  $this->dispatch('unread-changed', count: 5)->to(AppShell::class);
  ```
- A remount of the same component id replaces the instance (old `destroy()`
  runs first).
- **Keep state on `this`, not at module scope.** Each mount gets its own
  object, so `this` state resets on destroy → mount, while `$shellPersist`
  adoption keeps it across navigation — both behave as expected. Module-scope
  variables (`let n = 0` above the export) survive even `shellDestroy()`,
  because the loaded code is cached; use them only when you explicitly want
  state to outlive the module instance.

## Current limitations (prototype)

- PHP-owned props are re-pushed on every render (no diffing yet); keep them
  small and JSON-serializable.
- One module per component (`$shell` is a single name).
