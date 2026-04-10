# Animations

NativeBlade includes [Animate.css](https://animate.style/) (90+ animations) plus custom NativeBlade animations. Use them declaratively with HTML attributes — no CSS keyframes needed.

## Usage

```blade
<div nb-animation="fadeInUp">Hello</div>
<div nb-animation="bounceIn" nb-animation-delay="200ms">Bounce!</div>
<div nb-animation="zoomIn" nb-animation-speed="fast">Fast zoom</div>
<div nb-animation="pulse" nb-animation-repeat="infinite">Loading...</div>
<div nb-animation="shakeX" nb-animation-repeat="3">Shake 3 times</div>
```

## Attributes

| Attribute | Values | Description |
|-----------|--------|-------------|
| `nb-animation` | Any animation name | The animation to apply |
| `nb-animation-delay` | `100ms`, `0.5s`, etc. | Delay before animation starts |
| `nb-animation-speed` | `slower`, `slow`, `fast`, `faster` | Animation speed |
| `nb-animation-repeat` | `1`, `2`, `3`, `infinite` | Repeat count |

## Haptic Feedback

Add `nb-feedback` to any element for haptic selection feedback on tap:

```blade
<button wire:click="save" nb-feedback>Save</button>
<button wire:nb-bridge="showModal" nb-feedback>Open</button>
```

Does not conflict with `wire:click` or `wire:nb-bridge`. Bottom navigation has haptic feedback built-in.

## Animate.css Animations

All [Animate.css](https://animate.style/) animations work: `fadeIn`, `fadeInUp`, `fadeInDown`, `fadeInLeft`, `fadeInRight`, `bounceIn`, `zoomIn`, `slideInUp`, `slideInRight`, `flipInX`, `jackInTheBox`, `shakeX`, `tada`, `pulse`, `heartBeat`, and [many more](https://animate.style/).

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
