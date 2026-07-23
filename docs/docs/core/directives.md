---
title: "Directives"
description: "Blade directives NativeBlade adds."
---

## PHP Attributes

### `#[Flash]`

Marks a Livewire property as a flash value, automatically reset to its declared default at the start of every subsequent request. Use for one-shot messages that should disappear on the next interaction.

```php
use NativeBlade\Attributes\Flash;

#[Flash]
public string $exportMessage = '';

public function exportStats()
{
    $this->exportMessage = 'Exported!';
}
```

Any other action in the component runs without needing to clear `$exportMessage` manually. The reset value is inferred from the property's declared default.
