<?php

namespace NativeBlade\Plugins;

/**
 * Fluent builder for an in-app purchase.
 *
 * Collected via `NativeBlade::purchase(function (Purchase $p) { ... })`. The
 * native plugin presents the store sheet (StoreKit on iOS, Play Billing on
 * Android), then reports the outcome on the `nb:purchase-result` Livewire
 * event with the store receipt. Always validate that receipt on a server
 * before granting entitlement.
 */
class Purchase
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** Store product identifier to buy. */
    public function product(string $id): static
    {
        $this->data['product'] = $id;
        return $this;
    }

    /** Tag echoed back on `nb:purchase-result`, for routing several products through one listener. */
    public function id(string $tag): static
    {
        $this->data['id'] = $tag;
        return $this;
    }

    /**
     * Consume the purchase after it completes so it can be bought again
     * (credits, coins). Leave off for durable entitlements (subscriptions,
     * premium unlocks), which are acknowledged instead.
     */
    public function consumable(bool $consumable = true): static
    {
        $this->data['consumable'] = $consumable;
        return $this;
    }

    /**
     * Web checkout URL used only on desktop, where there is no store billing.
     * On mobile, native billing is always used and this is ignored.
     */
    public function external(string $url): static
    {
        $this->data['external'] = $url;
        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
