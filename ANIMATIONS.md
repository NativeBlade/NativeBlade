# Animations

NativeBlade includes [Animate.css](https://animate.style/) (90+ animations) plus custom NativeBlade animations. No CSS keyframes needed.

## Animate Component

The `<x-nativeblade-animate>` component handles enter/exit animations, auto-dismiss, and Livewire morph compatibility:

```blade
{{-- Enter + exit after 3 seconds --}}
<x-nativeblade-animate in="shakeX" out="fadeOutUp" dismiss="3s">
    Error message here
</x-nativeblade-animate>

{{-- Enter only, re-animates on every Livewire render --}}
<x-nativeblade-animate in="fadeInUp">
    Content
</x-nativeblade-animate>

{{-- Enter only, animates ONCE (no re-animation on morph) --}}
<x-nativeblade-animate in="fadeInUp" :once="true">
    Static content
</x-nativeblade-animate>

{{-- With delay and speed --}}
<x-nativeblade-animate in="bounceIn" out="zoomOut" dismiss="5s" delay="200ms" speed="fast">
    Toast notification
</x-nativeblade-animate>

{{-- Infinite animation --}}
<x-nativeblade-animate in="pulse" repeat="infinite">
    Loading...
</x-nativeblade-animate>
```

### Props

| Prop | Default | Description |
|------|---------|-------------|
| `in` | `fadeIn` | Enter animation name |
| `out` | — | Exit animation name |
| `dismiss` | — | Time before exit animation (`2s`, `3s`, `500ms`) |
| `delay` | — | Delay before enter animation |
| `speed` | — | `slower`, `slow`, `fast`, `faster` |
| `repeat` | — | Repeat count or `infinite` |
| `:once` | `false` | When `true`, animates only once (Livewire morph won't re-trigger) |

### Livewire Behavior

- **Default** (`once=false`): re-animates on every Livewire re-render. Use for error messages, toasts, notifications.
- **Once** (`once=true`): animates only on first render. Use for page layout elements that shouldn't replay.

## HTML Attributes

For inline use without the component:

```blade
<div nb-animation="fadeInUp">Hello</div>
<div nb-animation="bounceIn" nb-animation-delay="200ms">Bounce!</div>
<div nb-animation="zoomIn" nb-animation-speed="fast">Fast zoom</div>
<div nb-animation="pulse" nb-animation-repeat="infinite">Loading...</div>
```

| Attribute | Values | Description |
|-----------|--------|-------------|
| `nb-animation` | Any animation name | The animation to apply |
| `nb-animation-delay` | `100ms`, `0.5s`, etc. | Delay before animation starts |
| `nb-animation-speed` | `slower`, `slow`, `fast`, `faster` | Animation speed |
| `nb-animation-repeat` | `1`, `2`, `3`, `infinite` | Repeat count |
| `nb-animation-out` | Any animation name | Exit animation |
| `nb-animation-dismiss` | `2s`, `3s`, etc. | Time before exit |

Elements with `nb-animation` attributes animate once per DOM insertion. Livewire morphs that reuse the same DOM node won't re-trigger the animation.

## Haptic Feedback

Add `nb-feedback` to any element for haptic selection feedback on tap:

```blade
<button wire:click="save" nb-feedback>Save</button>
<button wire:nb-bridge="showModal" nb-feedback>Open</button>
```

Does not conflict with `wire:click` or `wire:nb-bridge`. Bottom navigation has haptic feedback built-in. Only triggers on mobile devices.

## Animate.css Animations

All [Animate.css](https://animate.style/) animations work out of the box:

**Entrances:** `fadeIn`, `fadeInUp`, `fadeInDown`, `fadeInLeft`, `fadeInRight`, `bounceIn`, `bounceInUp`, `zoomIn`, `slideInUp`, `slideInRight`, `flipInX`, `flipInY`, `jackInTheBox`, `backInUp`, `backInRight`, `lightSpeedInRight`, `rotateIn`, `rollIn`

**Exits:** `fadeOut`, `fadeOutUp`, `fadeOutDown`, `fadeOutLeft`, `fadeOutRight`, `bounceOut`, `zoomOut`, `slideOutUp`, `slideOutRight`, `flipOutX`, `backOutRight`, `lightSpeedOutRight`, `rotateOut`, `rollOut`, `hinge`

**Attention:** `shakeX`, `shakeY`, `tada`, `pulse`, `bounce`, `flash`, `swing`, `wobble`, `jello`, `heartBeat`, `rubberBand`, `headShake`, `flip`

Browse all at [animate.style](https://animate.style/).

## NativeBlade Custom Animations

| Name | Description |
|------|-------------|
| `pulseGlow` | Pulsating glow effect |
| `shimmer` | Shine effect for progress bars |
| `confetti` | Falling particle effect |
| `xpFill` | Progress bar fill |
| `springPop` | Bouncy scale with rotation |
| `float` | Gentle floating up and down |
| `glow` | Pulsating box-shadow glow |
| `scaleTap` | Quick press feedback |
| `shakeSubtle` | Gentle horizontal shake |
| `slideFadeInRight` | Slide + fade combined (right) |
| `slideFadeInLeft` | Slide + fade combined (left) |
| `slideFadeInUp` | Slide + fade combined (up) |
| `slideFadeInDown` | Slide + fade combined (down) |
| `popIn` / `popOut` | Scale from/to 0 with overshoot |
| `celebrate` | Quick scale pulse for success |
| `wiggle` | Playful rotation wiggle |
| `revealUp` / `revealDown` | Clip-path reveal |
| `blurIn` / `blurOut` | Blur to sharp |

## Page Transitions

Animations also power page transitions. See [CONFIGURATION.md](CONFIGURATION.md#page-transitions).
