---
title: "Animations"
description: "Entrance and exit animations powered by Animate.css, plus custom NativeBlade animations."
---

# Animations

NativeBlade bundles [Animate.css](https://animate.style/){target="_blank" rel="noopener"}
(all 90+ animations) plus a set of custom NativeBlade animations. You never write
keyframes: you name an animation and it plays. Every name from
[animate.style](https://animate.style/){target="_blank" rel="noopener"} works out
of the box, and each family comes in an **entrance** and an **exit** variant.

## A complete cycle

The `<x-nativeblade-animate>` component drives the full lifecycle: an element
enters, stays, then exits on its own.

```blade
<x-nativeblade-animate in="fadeInUp" out="fadeOutDown" dismiss="3s" speed="fast">
    Saved successfully
</x-nativeblade-animate>
```

It enters with `fadeInUp`, waits three seconds, then leaves with `fadeOutDown`.
Every knob is optional, so the same component covers the whole range:

```blade
{{-- Entrance only --}}
<x-nativeblade-animate in="fadeInUp">
    Content
</x-nativeblade-animate>

{{-- Entrance only, played once (a Livewire morph will not replay it) --}}
<x-nativeblade-animate in="fadeInUp" :once="true">
    Static content
</x-nativeblade-animate>

{{-- Entrance, delay, then a timed exit --}}
<x-nativeblade-animate in="bounceIn" out="zoomOut" dismiss="5s" delay="200ms" speed="fast">
    Toast notification
</x-nativeblade-animate>

{{-- Attention loop that never stops --}}
<x-nativeblade-animate in="pulse" repeat="infinite">
    Loading
</x-nativeblade-animate>
```

### Props

| Prop | Default | Description |
|------|---------|-------------|
| `in` | `fadeIn` | Entrance animation name. |
| `out` | none | Exit animation name. |
| `dismiss` | none | Time before the exit plays (`2s`, `3s`, `500ms`). |
| `delay` | none | Delay before the entrance plays. |
| `speed` | none | `slower`, `slow`, `fast`, `faster`. |
| `repeat` | none | Repeat count or `infinite`. |
| `:once` | `false` | When `true`, animates only on first render. |

### Livewire behavior

- Default (`:once="false"`): re-animates on every Livewire re-render. Use it for
  error messages, toasts, and notifications.
- Once (`:once="true"`): animates only on first render. Use it for layout
  elements that should not replay when the component morphs.

## Inline attributes

For a one-off animation without the component:

```blade
<div nb-animation="fadeInUp">Hello</div>
<div nb-animation="bounceIn" nb-animation-delay="200ms">Bounce</div>
<div nb-animation="zoomIn" nb-animation-speed="fast">Fast zoom</div>
<div nb-animation="pulse" nb-animation-repeat="infinite">Loading</div>
```

| Attribute | Values | Description |
|-----------|--------|-------------|
| `nb-animation` | Any animation name | The animation to apply. |
| `nb-animation-delay` | `100ms`, `0.5s` | Delay before it starts. |
| `nb-animation-speed` | `slower`, `slow`, `fast`, `faster` | Animation speed. |
| `nb-animation-repeat` | `1`, `2`, `infinite` | Repeat count. |
| `nb-animation-out` | Any animation name | Exit animation. |
| `nb-animation-dismiss` | `2s`, `3s` | Time before the exit. |

Elements with `nb-animation` animate once per DOM insertion. A Livewire morph
that reuses the same node will not re-trigger them.

## The full catalog

Browse and preview every one at
[animate.style](https://animate.style/){target="_blank" rel="noopener"}. The names
map one to one.

**Entrances:** `fadeIn`, `fadeInUp`, `fadeInDown`, `fadeInLeft`, `fadeInRight`,
`bounceIn`, `bounceInUp`, `zoomIn`, `slideInUp`, `slideInRight`, `flipInX`,
`flipInY`, `jackInTheBox`, `backInUp`, `backInRight`, `lightSpeedInRight`,
`rotateIn`, `rollIn`

**Exits:** `fadeOut`, `fadeOutUp`, `fadeOutDown`, `fadeOutLeft`, `fadeOutRight`,
`bounceOut`, `zoomOut`, `slideOutUp`, `slideOutRight`, `flipOutX`, `backOutRight`,
`lightSpeedOutRight`, `rotateOut`, `rollOut`, `hinge`

**Attention:** `shakeX`, `shakeY`, `tada`, `pulse`, `bounce`, `flash`, `swing`,
`wobble`, `jello`, `heartBeat`, `rubberBand`, `headShake`, `flip`

## Custom NativeBlade animations

Beyond Animate.css, these ship with the framework:

| Name | Description |
|------|-------------|
| `pulseGlow` | Pulsating glow. |
| `shimmer` | Shine effect for progress bars. |
| `confetti` | Falling particle effect. |
| `xpFill` | Progress bar fill. |
| `springPop` | Bouncy scale with rotation. |
| `float` | Gentle floating up and down. |
| `glow` | Pulsating box-shadow glow. |
| `scaleTap` | Quick press feedback. |
| `shakeSubtle` | Gentle horizontal shake. |
| `slideFadeInRight` / `Left` / `Up` / `Down` | Slide and fade combined. |
| `popIn` / `popOut` | Scale from or to zero with overshoot. |
| `celebrate` | Quick scale pulse for success. |
| `wiggle` | Playful rotation wiggle. |
| `revealUp` / `revealDown` | Clip-path reveal. |
| `blurIn` / `blurOut` | Blur to sharp. |

## Shell component animations

Shell components render in the parent document, outside the page, so the page's
inline `nb-animation` attributes do not reach them. Animate from the module with
`nb.animate(target).enter/leave` instead, using the same Animate.css names.

`nb.animate()` targets the shell root (`nb.element`). Pass a selector to scope to
an element inside the shell, or an element directly:

```js
export default (nb) => {
    nb.php.watch('open', (open) => {
        if (open) nb.animate().enter('slideInLeft');
        else nb.animate().leave('slideOutLeft');   // hides the element when the exit ends
    });

    // An isolated element, e.g. a bell that reacts on its own:
    nb.php.listen('ring', () => nb.animate('#bell').enter('tada'));
};
```

`enter(name, opts)` plays an entrance and keeps the element visible. `leave(name,
opts)` plays an exit and hides the element when it ends, so `await
nb.animate().leave(...)` returns with it already gone. Both resolve on
`animationend` (capped by a timeout so a caller never hangs), and take the same
`{ speed, delay, repeat }` options as `<x-nativeblade-animate>`. `repeat:
'infinite'` leaves it looping, a pulsing badge for one, until you animate it out.

The shell carries the full Animate.css catalog plus the custom NativeBlade
animations, the same set the page has, so any name works: `bounceIn`, `flipInX`,
`rotateIn`, all of them. It costs about 5 KB gzipped in the parent bundle.

Starting a new animation on an element supersedes the one in flight, so a value
that toggles fast (open, close, open) never leaves two animations fighting: the
previous is dropped and the new one takes over. When the component is torn down
(navigation, its owner gone), its element leaves the DOM, so a looping `repeat:
'infinite'` stops on its own, with no orphan left pulsing.

## Haptics

Add `nb-feedback` to any element for selection haptics on tap. It does not
conflict with `wire:click` or `wire:nb-bridge`, and only fires on mobile devices.

```blade
<button wire:click="save" nb-feedback>Save</button>
```

## Page transitions

Animations also drive page transitions. See
[Configuration](/configuration/transitions/).
