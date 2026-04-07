---
name: Component Package Proposal
about: Propose a new NativeBlade component package (composer)
title: "[Component] "
labels: enhancement, component, help wanted
assignees: ''
---

## Component name

e.g. Toast, Card, Modal, FAB

## Type

- [ ] Shell (renders outside the WebView)
- [ ] Embedded (renders inside the WebView)

## Description

What does this component do?

## Proposed API

### Blade usage

```blade
{{-- How the developer would use it --}}
```

### Bridge usage (if shell)

```blade
{{-- How to trigger via bridge --}}
<button wire:nb-bridge="..." wire:nb-payload='...'>
```

## Design

Describe the visual design or attach a mockup.

## Package structure

```
nativeblade/{name}/
├── {name}/
│   ├── {Name}.php
│   ├── {name}.blade.php
│   ├── {name}.js          (shell only)
│   └── {name}.css          (shell only)
├── composer.json
└── README.md
```

## References

- Links to similar components in other frameworks
