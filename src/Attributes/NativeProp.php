<?php

namespace NativeBlade\Attributes;

use Attribute;

/**
 * Marks a Livewire public property as synced with the component's native
 * shell module (see HasNativeShell). Direction is per-property:
 *
 *  - `from: NativeProp::PHP` (default) — PHP owns the value. Every render
 *    pushes it to the shell module's `update(props)` hook. The shell never
 *    writes it back.
 *
 *  - `from: NativeProp::SHELL` — the shell module owns the value (written
 *    via `ctx.set(key, value)` at any frequency). PHP reads it two ways:
 *      * ride-along (default, `throttle: null`): the current value is
 *        injected into the property at hydrate on every request that
 *        happens anyway — zero extra requests. `$this->position` is fresh
 *        whenever an interaction runs.
 *      * active push (`throttle: 500`): the shell also dispatches a
 *        Livewire update at most once per N ms, for the rare case where
 *        PHP must REACT to the change. Each push costs a full request —
 *        keep throttles coarse.
 *
 * A property has exactly one owner. To move a value against its direction
 * use an explicit command (`$this->shell('seek', 30)`), never a prop write.
 *
 * ```
 * use HasNativeShell;
 *
 * protected string $shell = 'video-player';
 *
 * #[NativeProp] public string $url = '';
 * #[NativeProp] public bool $playing = false;
 *
 * #[NativeProp(from: NativeProp::SHELL)]
 * public int $position = 0;      // shell writes, PHP reads at hydrate
 * ```
 *
 * @see \NativeBlade\Concerns\HasNativeShell
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class NativeProp
{
    public const PHP = 'php';
    public const SHELL = 'shell';

    public function __construct(
        public string $from = self::PHP,
        public ?int $throttle = null,
    ) {
    }
}
