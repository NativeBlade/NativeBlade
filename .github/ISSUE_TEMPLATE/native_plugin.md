---
name: Native Plugin Proposal
about: Propose a new native plugin (Kotlin/Swift/Rust)
title: "[Plugin] "
labels: enhancement, plugin, help wanted
assignees: ''
---

## Plugin name

e.g. Maps, Video Player, PDF Viewer

## Description

What does this plugin do?

## Platforms

- [ ] Android (Kotlin)
- [ ] iOS (Swift)
- [ ] Desktop (Rust)

## Approach

- [ ] Overlay (opens on top of the WebView)
- [ ] Embedded (renders inline with WebView content)

## Proposed API

### Blade usage

```blade
{{-- How the developer would use it --}}
```

### Bridge usage

```blade
{{-- Alternative via bridge --}}
<button wire:nb-bridge="..." wire:nb-payload='...'>
```

### PHP usage

```php
// If it needs server-side interaction
```

## Native implementation notes

Brief description of the native APIs involved:

**Android:**
```kotlin
// e.g. Google Maps SDK, CameraX, etc.
```

**iOS:**
```swift
// e.g. MapKit, AVFoundation, etc.
```

## JS alternative

Is there a JS library that could work inside the WebView instead?

- Library: 
- Pros: 
- Cons: 

## References

- Links to relevant documentation
