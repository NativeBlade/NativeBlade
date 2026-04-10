# Native Filesystem

Access the real filesystem on desktop and mobile using Laravel's Storage API.

## Setup

Add the `native` disk to `config/filesystems.php`:

```php
'disks' => [
    'native' => [
        'driver' => 'nativeblade',
    ],
],
```

## Usage

```php
use Illuminate\Support\Facades\Storage;
use NativeBlade\Storage\StoragePath;

// Write (app internal data - default)
Storage::disk('native')->put(native_path('settings.json'), $content);

// Read
$content = Storage::disk('native')->get(native_path('settings.json'));

// Delete
Storage::disk('native')->delete(native_path('settings.json'));

// Check existence
Storage::disk('native')->exists(native_path('config.json'));

// List files
Storage::disk('native')->files(native_path('reports/'));

// Copy / Move
Storage::disk('native')->copy(native_path('old.txt'), native_path('new.txt'));
Storage::disk('native')->move(native_path('draft.txt'), native_path('final.txt'));
```

## Storage Purposes

The second argument of `native_path()` defines where the file goes:

```php
native_path('file.txt')                          // App internal (default)
native_path('report.pdf', StoragePath::EXPORT)   // User Documents
native_path('backup.zip', StoragePath::DOWNLOADS) // User Downloads
native_path('thumb.jpg', StoragePath::CACHE)      // App cache (temporary)
native_path('tmp.dat', StoragePath::TEMP)         // System temp
```

| Purpose | Enum | Android | iOS | Desktop |
|---------|------|---------|-----|---------|
| `APP` (default) | `StoragePath::APP` | App internal | App sandbox | AppData |
| `EXPORT` | `StoragePath::EXPORT` | Documents | Documents | ~/Documents |
| `DOWNLOADS` | `StoragePath::DOWNLOADS` | Downloads | Downloads | ~/Downloads |
| `CACHE` | `StoragePath::CACHE` | App cache | Caches | AppCache |
| `TEMP` | `StoragePath::TEMP` | System temp | System temp | System temp |

## Examples

### Save user avatar from camera

```php
use Livewire\Attributes\On;

#[On('nb:camera-result')]
public function onPhoto($data = null)
{
    if (!$data) return;

    $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $data);
    Storage::disk('native')->put(native_path('avatar.jpg'), base64_decode($base64));

    $this->avatarSrc = $data;
}
```

### Load avatar on mount

```php
public function mount()
{
    try {
        $content = Storage::disk('native')->get(native_path('avatar.jpg'));
        if ($content) {
            $this->avatarSrc = 'data:image/jpeg;base64,' . base64_encode($content);
        }
    } catch (\Throwable) {}
}
```

### Export to user Documents

```php
public function export()
{
    $data = json_encode($this->stats, JSON_PRETTY_PRINT);
    Storage::disk('native')->put(native_path('stats.json', StoragePath::EXPORT), $data);
}
```

### Delete a file

```php
public function deleteExport()
{
    Storage::disk('native')->delete(native_path('stats.json', StoragePath::EXPORT));
}
```

## How It Works

The native filesystem uses a bridge pattern (same as HTTP Bridge):

1. PHP calls `Storage::disk('native')->put(...)` 
2. The adapter writes a pending operation to a temp file
3. PHP signals the bridge and exits
4. JavaScript picks up the operation and calls `@tauri-apps/plugin-fs`
5. The result is cached
6. PHP re-executes, finds the cache, and continues normally

This is transparent — your code uses standard Laravel Storage without any changes.

## Camera Integration

Compress photos before saving with `wire:nb-payload`:

```blade
<button wire:nb-bridge="camera" 
    wire:nb-payload='{"maxWidth":400,"maxHeight":400,"quality":0.5}'>
    Take Photo
</button>

<button wire:nb-bridge="gallery" 
    wire:nb-payload='{"maxWidth":400,"maxHeight":400,"quality":0.5}'>
    Gallery
</button>
```

| Option | Default | Description |
|--------|---------|-------------|
| `maxWidth` | 800 | Maximum width in pixels |
| `maxHeight` | 800 | Maximum height in pixels |
| `quality` | 0.6 | JPEG quality (0-1) |
| `compress` | true | Enable compression |
