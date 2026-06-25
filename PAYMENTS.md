# Payments

Native in-app purchases and subscriptions. On iOS the plugin uses **StoreKit 2** and on Android **Google Play Billing**, the billing systems Apple and Google require for any digital good consumed inside the app (subscriptions, credits, unlocking premium features or paid plugins). v1 covers the full flow: fetch products with localized prices, start a purchase, restore previous purchases, and read active entitlements. The native side only starts the flow and hands back the store **receipt**; you validate that receipt on your own server before granting access. Requires `Plugin::PAYMENTS`.

Physical goods and services are out of scope here. Store billing is never allowed for them, so those go through Stripe instead (a separate concern). The plugin is for digital goods only.

## Platforms

Mobile-only (Android + iOS). On desktop there is no sideload-friendly store billing, so a purchase falls back to opening a web checkout in the browser when you pass an `external(...)` URL; every other call reports an empty or `unsupported` result so the same handler code runs everywhere without branching.

- **iOS:** StoreKit 2, which needs **iOS 15+**. Set your app's iOS deployment target to 15 or higher.
- **Android:** Google Play Billing Library 9. The `com.android.vending.BILLING` permission is added by the library itself.

## Testing (important)

Unlike ads (which serve test impressions in any debug build with zero setup), in-app purchases need real test configuration, and the two stores differ:

|                          | Without any store? | Without real money? |
|--------------------------|--------------------|---------------------|
| **iOS** (StoreKit local) | yes                | yes                 |
| **Android** (Play)       | no, needs internal track | yes (test instrument) |

**1. iOS, fully local (best).** Xcode's **StoreKit Testing** runs purchases entirely on-device from a `.storekit` configuration file, with no App Store Connect, no sandbox account and nothing uploaded. Define your products in the `.storekit` file, enable it in the scheme, and the whole flow (purchase, restore, subscription, refund simulation) works in the simulator or on device. Sandbox testing (products in App Store Connect, a sandbox tester account, no public release) is the alternative when you want to exercise the real servers.

**2. Android, on the internal testing track.** Play Billing does not work on a plain sideloaded APK; the Play Store app processes the flow and must know your app. Upload the AAB to the **internal testing** track (not public), add your account as a **license tester** in the Play Console, and purchases then use a **test instrument** with no real charge. The reserved static product ids (`android.test.purchased`, `android.test.canceled`) exist but are limited; the internal track is the reliable path.

> Both stores require **server-side receipt validation** before you grant anything. Never unlock a feature from the `nb:purchase-result` event alone, an event can be spoofed. Send the receipt to your Laravel backend and verify it against Apple, Google or RevenueCat there.

## Setup

```php
use NativeBlade\Config\Plugin;
use NativeBlade\Facades\NativeBladeConfig;

NativeBladeConfig::plugins([Plugin::PAYMENTS, /* ... */]);
```

Run `php artisan nativeblade:config`. There is no app-level id to configure: products are identified by the ids you create in App Store Connect and the Play Console.

## Showing prices

Always show the store's own localized price string (it handles currency and tax), never a hardcoded one. The store query is async and crosses the native bridge, so don't run it in `mount()` (that blocks the first paint). Kick it off with `wire:init` after the screen renders and show a skeleton while prices load:

```blade
<div wire:init="loadProducts">
    @if ($proPrice)
        Pro plan: {{ $proPrice }}
        <button nb-feedback wire:click="buyPro">Subscribe</button>
    @else
        <div class="skeleton h-6 w-24"></div>
    @endif
</div>
```

```php
use Livewire\Attributes\On;
use NativeBlade\Facades\NativeBlade;

public ?string $proPrice = null;

public function loadProducts()
{
    return NativeBlade::products([
        'com.nativeblade.pro.monthly',
        'com.nativeblade.pro.yearly',
    ])->toResponse();
}

#[On('nb:products')]
public function onProducts($products)
{
    // $products = [['id' => ..., 'price' => 'R$ 19,90', 'title' => ..., 'type' => ...], ...]
    $this->proPrice = collect($products)->firstWhere('id', 'com.nativeblade.pro.monthly')['price'] ?? null;
}
```

## Purchasing

Start the purchase, then validate the receipt on the server before unlocking:

```php
use Livewire\Attributes\On;
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Plugins\Purchase;

public function buyPro()
{
    return NativeBlade::purchase(function (Purchase $p) {
        $p->id('pro_monthly')->product('com.nativeblade.pro.monthly');
    })->toResponse();
}

#[On('nb:purchase-result')]
public function onPurchase($success, $receipt = null, $productId = null, $error = null, $status = null, $id = null)
{
    if (!$success) {
        // $status = 'cancelled' | 'pending' | 'failed'
        if ($status !== 'cancelled') $this->addError('payment', $error ?: 'Purchase failed');
        return;
    }

    // Never trust this event alone. Verify the receipt on your server against
    // Apple / Google, then grant the entitlement there.
    if (app(ReceiptValidator::class)->verify($productId, $receipt)) {
        auth()->user()->grantPro();
        return NativeBlade::navigate('/dashboard', replace: true)->toResponse();
    }
}
```

For **consumables** (credits, coins) mark the purchase so it is consumed and can be bought again:

```php
NativeBlade::purchase(fn (Purchase $p) =>
    $p->id('coins')->product('com.nativeblade.coins.100')->consumable()
)->toResponse();
```

Durable purchases (non-consumables, subscriptions) are acknowledged automatically and should not be marked consumable.

## Restoring and subscription status

Apple requires a visible **Restore purchases** action for non-consumables and subscriptions:

```php
public function restore()
{
    return NativeBlade::restorePurchases()->toResponse();
}

#[On('nb:purchases-restored')]
public function onRestored($purchases)
{
    // $purchases = [['productId' => ..., 'receipt' => ...], ...]
    foreach ($purchases as $p) {
        app(ReceiptValidator::class)->verify($p['productId'], $p['receipt']);
    }
}
```

Read the current entitlements (owned non-consumables and active subscriptions) to gate premium UI. Trigger it with `wire:init` (same reason as prices: don't block the first paint):

```php
public function loadStatus()
{
    return NativeBlade::subscriptionStatus(['com.nativeblade.pro.monthly'])->toResponse();
}

#[On('nb:subscription-status')]
public function onStatus($entitlements)
{
    // $entitlements = [['productId' => ..., 'active' => true, 'expiresAt' => ..., 'receipt' => ...], ...]
    $this->isPro = collect($entitlements)->contains(fn ($e) => $e['active']);
}
```

Pass no ids to `subscriptionStatus()` to get every active entitlement.

## Starting simple (no backend)

For an indie app you don't need a server to **gate the UI**. `subscriptionStatus()` reads an entitlement that the store itself signed on the device (StoreKit 2 verifies the JWS locally, Play Billing purchases are signed), so it is trustworthy enough to decide what to show. The entitlement is derived and disposable (you can always recompute it by re-querying the store), so it belongs in Laravel's `Cache`, not in durable `setState`.

The cleanest trick: **you sell the plan, so you know its length.** Don't even read the store's expiry, just cache the entitlement with a TTL equal to the plan length when the purchase succeeds. The TTL *is* the expiry, the entry vanishes on its own when the plan ends, so there is nothing to compare. This also sidesteps an asymmetry between the stores: StoreKit returns `expiresAt` but Play Billing does not expose a subscription expiry on the client, so deriving it from the plan length works identically on both.

```php
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;

#[On('nb:purchase-result')]
public function onPurchase($success, $productId = null, $receipt = null)
{
    if (!$success) return;

    $days = match ($productId) {        // you sell it, you know the duration
        'com.app.pro.monthly' => 30,
        'com.app.pro.yearly'  => 365,
        default               => 0,
    };

    // The TTL is the entitlement window; the entry expires by itself.
    Cache::put('pro', true, now()->addDays($days));
}
```

Gate premium UI anywhere, the cache miss after expiry downgrades them automatically:

```php
$this->isPro = Cache::has('pro');
```

On boot, call `subscriptionStatus()` once (via `wire:init`) just to **reconcile**: if the store still reports the subscription as `active`, it renewed, so re-put the cache for another cycle; if it has dropped off the list, do nothing and let the entry lapse. That single re-check is the only safety net you need, and it also covers a refund.

```php
#[On('nb:subscription-status')]
public function onStatus($entitlements)
{
    $active = collect($entitlements)->firstWhere('active', true);
    if ($active) {
        // Renewed: push the cache forward by another cycle.
        $days = $active['productId'] === 'com.app.pro.yearly' ? 365 : 30;
        Cache::put('pro', true, now()->addDays($days));
    }
    // Not active anymore: do nothing, the cache entry lapses on its own.
}
```

What you give up by skipping the server: status only refreshes when the app is opened (fine, a cancelled subscription keeps access until it expires anyway), and a determined user on a rooted/modified device could fake it (the store's local signature makes casual fakes hard, so this is an acceptable risk for an indie app). When fraud starts costing real money, graduate to server-side validation and store webhooks.

> **Cancellation:** users always cancel in the store (Play Store > Subscriptions, or iOS Settings > Apple ID > Subscriptions), never in the app. Cancelling only turns off auto-renew; access continues until the period ends. With the cache-from-purchase approach this is automatic, the cache entry simply lapses on the right day. For real-time reactions while the app is closed you need store webhooks (Google Real-time Developer Notifications, Apple App Store Server Notifications V2) hitting your backend, which is a server concern outside this client-side plugin.

## Builder methods

| Method | Description |
|---|---|
| `->product($id)` | Store product identifier to buy (required) |
| `->id($tag)` | Tag echoed back on `nb:purchase-result` for routing several products through one listener |
| `->consumable()` | Consume the purchase so it can be bought again (credits, coins); leave off for durable entitlements |
| `->external($url)` | Desktop-only web checkout URL, opened in the browser; ignored on mobile, which always uses native billing |

## Events

| Event | Payload |
|---|---|
| `nb:products` | `products`, `error`, `id` |
| `nb:purchase-result` | `success`, `status`, `receipt`, `productId`, `error`, `id` |
| `nb:purchases-restored` | `purchases`, `error`, `id` |
| `nb:subscription-status` | `entitlements`, `error`, `id` |

The `->toResponse()` rule applies: inside a Livewire component action call `->toResponse()`; inside a push or deep-link handler return the bare `NativeResponse`.

## External purchase links

Where store policy allows it (currently the United States, and the EU under the External Purchase Link Entitlement), apps may send users to a web checkout instead of store billing. The rules differ by region and keep changing, and Apple's EU fee stack still applies, so native billing stays the default that works everywhere. The `external(...)` builder method is wired for the desktop fallback today; the mobile system disclosure-sheet flow is reserved for a later version.

## See Also

- [PLUGINS.md](PLUGINS.md) — the `NativeBlade` facade
- [ADMOB.md](ADMOB.md) — the other monetization plugin
