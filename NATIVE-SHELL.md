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

    protected string $shell = 'video-player';   // -> public/js/shell/video-player.js
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

## JS side — `public/js/shell/video-player.js`

A single-file ES module (the shell imports it as a blob, so relative imports
don't resolve — keep the module self-contained; your in-page code under
`public/js/` can stay modular as usual).

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

- Instance is created when the component mounts, keyed by the component id.
- Non-persistent instances are destroyed on navigation. `$shellPersist = true`
  keeps the module alive across screens; end it explicitly with
  `$this->shellDestroy()` (e.g. "close mini-player").
- A remount of the same component id replaces the instance (old `destroy()`
  runs first).

## Current limitations (prototype)

- Shell modules are **single-file** (blob import — no relative imports).
- PHP-owned props are re-pushed on every render (no diffing yet); keep them
  small and JSON-serializable.
- Ride-along injection assigns values directly (no `updated*` hooks fire).
- One module per component (`$shell` is a single name).
