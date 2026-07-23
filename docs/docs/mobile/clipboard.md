---
title: "Clipboard"
description: "Read and write the system clipboard."
---

# Clipboard

Backed by [`tauri-plugin-clipboard-manager`](https://v2.tauri.app/plugin/clipboard/).

### Write

**Blade:**
```blade
<button wire:nb-bridge="clipboard_write" wire:nb-payload='{"text":"Copied text"}'>
    Copy
</button>
```

**PHP:**
```php
return NativeBlade::clipboardWrite($this->shareUrl)
    ->notification(fn (Notification $n) => $n->title('Copied')->body('Link copied to clipboard!'))
    ->toResponse();
```

### Read

**Blade (simple):**
```blade
<button wire:nb-bridge="clipboard_read">Paste from clipboard</button>
```

**Blade (with id):**
```blade
<button wire:nb-bridge="clipboard_read" wire:nb-payload='{"id":"password_field"}'>
    Paste password
</button>
<button wire:nb-bridge="clipboard_read" wire:nb-payload='{"id":"notes_field"}'>
    Paste notes
</button>
```

**PHP:**
```php
use NativeBlade\Plugins\Clipboard;

public function paste()
{
    // Simple case, no id needed:
    return NativeBlade::clipboardRead()->toResponse();
}

public function pastePassword()
{
    return NativeBlade::clipboardRead(fn (Clipboard $c) => $c->id('password_field'))->toResponse();
}

#[On('nb:clipboard')]
public function onPaste($text, $id = null)
{
    match ($id) {
        'password_field' => $this->password = $text,
        'notes_field'    => $this->notes = $text,
        default          => $this->content = $text,
    };
}
```

---

