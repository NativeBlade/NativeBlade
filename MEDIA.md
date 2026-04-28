# Media (Camera, Gallery, Video)

Native camera capture, gallery picker, and video picker with on-device resize. Replaces the JS canvas approach with platform pickers — much lower memory pressure on mobile WebViews.

A single Tauri plugin (`nativeblade-media`) backs all three sources. Picks are dispatched like every other NativeBlade native action: from PHP via a `NativeResponse` builder, returned to a Livewire component via the `nb:media-result` event. Use `->id()` on the builder when a single component triggers multiple pickers (e.g. avatar + document + product gallery).

---

## Quick start

Trigger a pick from a Livewire action by returning a `NativeResponse`:

```php
use Livewire\Attributes\On;
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Plugins\Media;

class ProfileEditor extends Component
{
    public function takeAvatar()
    {
        return NativeBlade::pickCamera(function (Media $m) {
            $m->id('avatar')
              ->maxDimensions(800, 800)
              ->quality(0.7)
              ->output('both');
        });
    }

    public function addProductPhotos()
    {
        return NativeBlade::pickGallery(function (Media $m) {
            $m->id('product-images')
              ->multiple()
              ->max(5)
              ->output('url');
        });
    }

    #[On('nb:media-result')]
    public function onMedia($items = [], $source = null, $id = null)
    {
        match ($id) {
            'avatar'         => $this->saveAvatar($items[0] ?? null),
            'product-images' => $this->saveProductImages($items),
            default          => null,
        };
    }
}
```

Trigger from Blade:

```html
<button wire:click="takeAvatar">Take photo</button>
<button wire:click="addProductPhotos">Add product photos</button>
```

---

## PHP API

Three facade methods, all returning a chainable `NativeResponse`:

- `NativeBlade::pickCamera(Closure $callback = null)` — open the native camera.
- `NativeBlade::pickGallery(Closure $callback = null)` — open the system photo picker (permission-free on Android 13+ / iOS 14+).
- `NativeBlade::pickVideo(Closure $callback = null)` — open the system video picker.

The callback receives a `NativeBlade\Plugins\Media` builder:

```php
$media
    ->maxWidth(1600)            // pixel cap, preserved aspect ratio
    ->maxHeight(1600)
    ->maxDimensions(1600, 1600) // shortcut for both
    ->quality(0.85)             // JPEG compression 0.0–1.0
    ->facing('back')            // 'back' | 'front' (camera only)
    ->output('both')            // 'url' | 'dataUrl' | 'both'
    ->multiple()                // gallery/video only
    ->max(5)                    // cap when multiple()
    ->id('avatar');             // echoed back on nb:media-result
```

Defaults when no builder runs: `1600 × 1600 @ quality 0.85`, `facing='back'`, `output='both'`, single pick, no id.

---

## Result event

Every successful pick fires the `nb:media-result` Livewire event with three named arguments:

```php
#[On('nb:media-result')]
public function onMedia($items = [], $source = null, $id = null)
{
    // $items  — array of media items (see shape below)
    // $source — 'camera' | 'gallery' | 'video'
    // $id     — the id passed to ->id(), or null
}
```

Each entry in `$items` is a plain associative array:

```php
[
    'url'      => 'asset://localhost/…',   // webview-loadable asset URL
    'path'     => '/var/.../IMG_1234.jpg', // raw filesystem path
    'dataUrl'  => 'data:image/jpeg;base64,…', // only when output is 'dataUrl' or 'both'
    'assetUrl' => 'asset://localhost/…',   // added by the JS bridge — use in <img src="…">
    'mime'     => 'image/jpeg',
    'size'     => 84231,
    'width'    => 1600,
    'height'   => 1200,
    'name'     => 'IMG_1234.jpg',
]
```

If the user cancels, `$items` arrives as an empty array. If the native plugin throws, an additional `$error` parameter is included — accept it on the listener if you want to surface it:

```php
#[On('nb:media-result')]
public function onMedia($items = [], $source = null, $id = null, $error = null) { … }
```

---

## Routing multiple pickers on the same component

The typical case — one avatar picker plus one gallery upload — uses `match` on the id:

```php
#[On('nb:media-result')]
public function onMedia($items, $source, $id = null)
{
    match ($id) {
        'avatar'         => $this->saveAvatar($items[0]['path'] ?? null),
        'product-images' => $this->saveProducts($items),
        'receipt'        => $this->saveReceipt($items[0] ?? null),
        default          => null,
    };
}
```

Without `->id()`, all pickers on the component collapse onto a single listener — fine when there's only one call site.

---

## Output modes

`output` controls what each item carries:

- `'url'` — only the file path / asset URL. Cheapest on memory. Use when you'll upload or `Storage::put()` immediately.
- `'dataUrl'` — only the base64 data URL. Convenient for `<img>` previews without `convertFileSrc`.
- `'both'` — return both. Default.

---

## Resize behavior

Both platforms downsample to fit inside `maxWidth × maxHeight` while preserving aspect ratio:

- **Android** — two-pass `BitmapFactory.inSampleSize` decode, then precise scale. JPEG written to `cacheDir/nb_media/`.
- **iOS** — `UIGraphicsImageRenderer` + `jpegData(compressionQuality:)`. Written to `NSTemporaryDirectory()/nb_media/`.

---

## Permissions

- **Android** — `CAMERA` declared on the plugin manifest. Gallery picker (API 33+) is permission-free. The legacy fallback uses `READ_MEDIA_IMAGES` / `READ_EXTERNAL_STORAGE (maxSdk 32)`.
- **iOS** — `PHPickerViewController` is permission-free. Camera capture goes through `AVCaptureDevice.requestAccess(.video)`. Add the usage strings in your `IosConfig::permissions([...])` block (typically `NSCameraUsageDescription` and `NSPhotoLibraryUsageDescription`).

---

## Imperative JS escape hatch

For the rare case where you want to trigger a pick from plain JS in a component (without going through a PHP action), `window.nbMedia` is still exposed:

```js
const result = await window.nbMedia.pickFromCamera({
    id: 'avatar',
    maxWidth: 800,
    maxHeight: 800,
    quality: 0.7,
    facing: 'back',
    output: 'both',
});
// result → { source: 'camera', items: [...], id: 'avatar' }
```

Results obtained this way are **not** forwarded to Livewire automatically — that's the whole point of preferring the PHP `NativeResponse::pickCamera()` path. Use this hatch only for in-page UI that doesn't need a server round-trip.

---

## Troubleshooting

- **Listener never fires** — verify the component has `#[On('nb:media-result')]` on a public method and that the component is mounted when the pick happens (Livewire can't dispatch into components that aren't in the DOM).
- **Android: black image after capture** — FileProvider config issue. Verify `${applicationId}.nbmedia.fileprovider` is declared in the merged manifest after build.
- **iOS: gallery returns nothing** — confirm the build actually included the plugin (`tauri-plugin-nativeblade-media` in `Cargo.toml` and `nativeblade-media:default` in `capabilities/mobile.json`).
- **Memory still high** — don't request both `dataUrl` and `url` if you only need one. Use `output: 'url'` and bind `<img src="{{ $item['assetUrl'] }}">` — no base64 buffer.
