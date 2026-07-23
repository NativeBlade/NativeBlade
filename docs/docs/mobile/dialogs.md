---
title: "Dialogs"
description: "Native alert, confirm, and file pickers."
---

# Dialogs

Backed by [`tauri-plugin-dialog`](https://v2.tauri.app/plugin/dialog/).

Both `alert` and `confirm` are configured through the same `Dialog` builder passed as a closure. This keeps all dialog-specific options (title, message, kind, button labels) together and out of the generic modifier chain.

The `Dialog` builder supports:

| Method | Description |
|---|---|
| `->title($text)` | Title shown above the message |
| `->message($text)` | Main body text of the dialog |
| `->kind($level)` | `'info'`, `'warning'` or `'error'`, affects icon/color |
| `->confirmLabel($text)` | Override the OK / confirm button label |
| `->cancelLabel($text)` | Override the Cancel button label (confirm only) |
| `->id($identifier)` | Tag the dialog so its result can be routed (see below) |

### alert

Native alert dialog with a single OK button.

**Blade:**
```blade
<button wire:nb-bridge="alert" wire:nb-payload='{"title":"Heads up","message":"Your session will expire soon","kind":"warning"}'>
    Show alert
</button>
```

**PHP:**
```php
use NativeBlade\Plugins\Dialog;

return NativeBlade::alert(function (Dialog $d) {
    $d->title('Heads up')
      ->message('Your session will expire soon')
      ->kind('warning');
})->toResponse();
```

### confirm

Native confirmation dialog with OK/Cancel buttons. The user's choice is delivered via the `nb:confirm-result` Livewire event.

**Blade:**
```blade
<button wire:nb-bridge="confirm" wire:nb-payload='{"title":"Delete?","message":"This cannot be undone"}'>
    Delete
</button>
```

**PHP:**
```php
use NativeBlade\Plugins\Dialog;

return NativeBlade::confirm(function (Dialog $d) {
    $d->title('Delete?')
      ->message('This cannot be undone')
      ->kind('warning')
      ->confirmLabel('Delete')
      ->cancelLabel('Keep');
})->toResponse();
```

### Handling multiple confirms in the same component

When a component has more than one confirm dialog (e.g. a delete button **and** a sign out button), tag each one with `->id()` and route the result in a single listener. The id is echoed back in the `nb:confirm-result` event:

```php
use Livewire\Attributes\On;
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Plugins\Dialog;

public function deleteExport()
{
    return NativeBlade::confirm(function (Dialog $d) {
        $d->id('delete')
          ->title('Delete export?')
          ->message('This will permanently remove stats.json.')
          ->kind('warning')
          ->confirmLabel('Delete');
    })->toResponse();
}

public function signOut()
{
    return NativeBlade::confirm(function (Dialog $d) {
        $d->id('signout')
          ->title('Sign out?')
          ->message('Your progress is saved.')
          ->confirmLabel('Sign out');
    })->toResponse();
}

#[On('nb:confirm-result')]
public function onConfirm($confirmed, $id = null)
{
    if (!$confirmed) return;

    return match ($id) {
        'delete'  => $this->performDelete(),
        'signout' => $this->performSignOut(),
        default   => null,
    };
}
```

Without `->id()`, the event still fires but `$id` arrives as `null`, fine when a component only has a single confirm dialog.

See [Receiving Results](#receiving-results-in-php) for more on handling dialog responses.

---

