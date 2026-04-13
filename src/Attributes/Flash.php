<?php

namespace NativeBlade\Attributes;

use Attribute;
use Livewire\Features\SupportAttributes\Attribute as LivewireAttribute;
use ReflectionProperty;
use Throwable;

/**
 * Marks a Livewire property as a flash value — one that lives for exactly
 * one request cycle and is automatically reset to its declared default at
 * the start of every subsequent request.
 *
 * Use this for one-shot messages (e.g. "Exported to Documents!") that
 * should appear after an action and disappear on the next interaction,
 * without the dev having to manually clear the property in every other
 * method of the component.
 *
 * The reset value is inferred from the property's declared default:
 *
 * ```
 * #[Flash]
 * public string $message = '';   // resets to ''
 *
 * #[Flash]
 * public array $items = [];      // resets to []
 *
 * #[Flash]
 * public ?int $count = null;     // resets to null
 * ```
 *
 * The hook runs in Livewire's `hydrate()` phase — before the incoming
 * action executes, so the action can still set a fresh flash value that
 * appears in the immediate re-render. Flash does not run on the very
 * first mount, so the initial default is preserved unchanged.
 *
 * @see \NativeBlade\NativeResponse
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Flash extends LivewireAttribute
{
    public function hydrate(): void
    {
        $component = $this->getComponent();
        $name = $this->getName();

        try {
            $reflection = new ReflectionProperty($component::class, $name);
            $default = $reflection->hasDefaultValue() ? $reflection->getDefaultValue() : null;
            $this->setValue($default);
        } catch (Throwable) {
            // ignore
        }
    }
}
