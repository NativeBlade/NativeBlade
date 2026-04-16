# Native Filesystem

Access the real filesystem on desktop and mobile using Laravel's Storage API.

## Two types of paths

NativeBlade has two path helpers for two different situations:

| Helper | When to use | Example |
|--------|-------------|---------|
| `native_path()` | Reading/writing files inside your app's storage | `Storage::disk('native')->put(native_path('data.json'), $content)` |
| `native_basename()` | Extracting the filename from a path that came from the OS (file picker, camera, etc.) | `native_basename($paths[0])` returns `photo.jpg` |

`native_path()` works with `Storage::disk('native')` — it creates virtual paths mapped to app directories (AppData, Documents, Downloads, etc).

`native_basename()` replaces PHP's `basename()` because PHP-WASM runs in a Linux context and won't recognize Windows `\` separators. Always use it for paths returned by the file picker.

They don't mix: use `native_path()` for internal storage, `native_basename()` for external file paths.

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

---

## File Picker

Open the native file picker dialog on desktop, Android, and iOS. The selected file path(s) are returned via the `nb:file-result` Livewire event — the file is never loaded into WASM memory.

```php
use NativeBlade\Plugins\FilePicker;

public function selectDocument()
{
    return NativeBlade::filePicker(function (FilePicker $picker) {
        $picker
            ->filters(['PDF' => ['pdf'], 'Images' => ['jpg', 'png', 'webp']])
            ->title('Select a document')
            ->id('doc');
    })->toResponse();
}

#[On('nb:file-result')]
public function onFileSelected($paths = [], $id = null)
{
    if ($id !== 'doc' || empty($paths)) return;

    $this->selectedFile = $paths[0];
}
```

### Multiple files

```php
return NativeBlade::filePicker(function (FilePicker $picker) {
    $picker->filters(['Spreadsheets' => ['csv', 'xlsx']])
           ->multiple()
           ->id('imports');
})->toResponse();
```

### Select directory

```php
return NativeBlade::filePicker(function (FilePicker $picker) {
    $picker->directory()
           ->title('Choose export folder')
           ->id('folder');
})->toResponse();
```

### Save dialog

Choose where to save a file:

```php
use NativeBlade\Plugins\FilePicker;

public function exportReport()
{
    return NativeBlade::fileSave('report.pdf', function (FilePicker $picker) {
        $picker->filters(['PDF' => ['pdf']]);
    })->toResponse();
}

#[On('nb:file-save-result')]
public function onSavePathChosen($path = null, $id = null)
{
    if (!$path) return;

    $pdf = $this->generatePdf();
    Storage::disk('native')->put($path, $pdf);
}
```

### FilePicker options

| Method | Description |
|--------|-------------|
| `filters([...])` | File type filters (`['Label' => ['ext1', 'ext2']]`) |
| `multiple()` | Allow selecting multiple files |
| `directory()` | Pick a directory instead of a file |
| `defaultPath(string)` | Start in this directory |
| `title(string)` | Dialog title |
| `id(string)` | Routing ID for multiple pickers in one component |

---

## Upload

Upload a file directly from the device filesystem to a remote server. The file streams through Rust — it never passes through PHP-WASM, so memory is not a concern even for large files.

```php
use NativeBlade\Plugins\Upload;

public function uploadDocument()
{
    return NativeBlade::upload($this->selectedFile, 'https://api.myapp.com/upload', function (Upload $upload) {
        $upload
            ->headers(['Authorization' => 'Bearer ' . $this->token])
            ->id('doc');
    })->toResponse();
}
```

### Progress & completion

```php
#[On('nb:upload-progress')]
public function onUploadProgress($progress = 0, $total = 0, $id = null)
{
    if ($id !== 'doc') return;
    $this->uploadPercent = $total > 0 ? round(($progress / $total) * 100) : 0;
}

#[On('nb:upload-complete')]
public function onUploadComplete($success = false, $error = null, $id = null)
{
    if ($id !== 'doc') return;

    if ($success) {
        $this->uploadMessage = 'Upload complete!';
    } else {
        $this->uploadMessage = 'Upload failed: ' . ($error ?? 'Unknown error');
    }
}
```

### Full example: pick + upload

```php
use NativeBlade\Plugins\FilePicker;
use NativeBlade\Plugins\Upload;

public string $selectedFile = '';
public int $uploadPercent = 0;

public function pickFile()
{
    return NativeBlade::filePicker(function (FilePicker $picker) {
        $picker->filters(['Documents' => ['pdf', 'docx', 'xlsx']])
               ->id('attachment');
    })->toResponse();
}

#[On('nb:file-result')]
public function onFileSelected($paths = [], $id = null)
{
    if ($id !== 'attachment' || empty($paths)) return;
    $this->selectedFile = $paths[0];

    return NativeBlade::upload($this->selectedFile, 'https://api.myapp.com/files', function (Upload $u) {
        $u->headers(['Authorization' => 'Bearer ' . $this->token])
          ->id('attachment');
    })->toResponse();
}

#[On('nb:upload-progress')]
public function onProgress($progress = 0, $total = 0, $id = null)
{
    $this->uploadPercent = $total > 0 ? round(($progress / $total) * 100) : 0;
}

#[On('nb:upload-complete')]
public function onComplete($success = false, $error = null, $id = null)
{
    $this->uploadPercent = $success ? 100 : 0;
}
```

```blade
<button wire:click="pickFile" nb-feedback>Select File</button>

@if($selectedFile)
    <p class="text-sm text-gray-400">{{ $selectedFile }}</p>
    <div class="h-2 bg-gray-800 rounded-full overflow-hidden">
        <div class="h-full bg-green-500 transition-all" style="width: {{ $uploadPercent }}%"></div>
    </div>
@endif
```

### Upload options

| Method | Description |
|--------|-------------|
| `url(string)` | Destination URL (set automatically by `NativeBlade::upload()`) |
| `headers([...])` | HTTP headers (auth tokens, content-type, etc.) |
| `id(string)` | Routing ID for multiple uploads in one component |

---

## Copy & Move

Copy or move any file on the device filesystem via Rust. The source is the absolute path (e.g. from the file picker), the destination is a relative path resolved to the chosen storage purpose (`app`, `export`, `downloads`, `cache`, `temp`).

```php
// Copy a selected file to app storage (default purpose: 'app')
NativeBlade::copyFile($selectedPath, 'documents/file.pdf');

// Copy to user's Documents folder
NativeBlade::copyFile($selectedPath, 'file.pdf', 'export');

// Move instead of copy
NativeBlade::moveFile($selectedPath, 'backups/file.pdf');
```

Result arrives via `nb:file-op-result`:

```php
#[On('nb:file-op-result')]
public function onFileOp($success = false, $operation = null, $error = null)
{
    if ($success) {
        $this->message = ucfirst($operation) . ' complete!';
    } else {
        $this->message = 'Failed: ' . $error;
    }
}
```

---

## Recipes

### Pick a file and save locally

Select a file from anywhere on the device and copy it into your app's storage:

```php
use NativeBlade\Plugins\FilePicker;

public string $savedFile = '';

public function importFile()
{
    return NativeBlade::filePicker(function (FilePicker $picker) {
        $picker->filters(['Documents' => ['pdf', 'docx', 'csv']])
               ->id('import');
    })->toResponse();
}

#[On('nb:file-result')]
public function onFileSelected($paths = [], $id = null)
{
    if ($id !== 'import' || empty($paths)) return;

    $filename = native_basename($paths[0]);
    $this->savedFile = $filename;

    return NativeBlade::copyFile($paths[0], 'imports/' . $filename)
        ->impact('light')
        ->toResponse();
}

#[On('nb:file-op-result')]
public function onCopy($success = false, $operation = null, $error = null)
{
    if ($success) {
        $this->message = $this->savedFile . ' imported!';
    }
}
```

### Pick a file and upload to server

Select a file and stream it directly to your backend without loading into memory:

```php
use NativeBlade\Plugins\FilePicker;
use NativeBlade\Plugins\Upload;

public string $selectedFile = '';
public int $percent = 0;

public function selectAndUpload()
{
    return NativeBlade::filePicker(function (FilePicker $picker) {
        $picker->filters(['Images' => ['jpg', 'png', 'webp']])
               ->id('avatar');
    })->toResponse();
}

#[On('nb:file-result')]
public function onFile($paths = [], $id = null)
{
    if ($id !== 'avatar' || empty($paths)) return;
    $this->selectedFile = native_basename($paths[0]);

    return NativeBlade::upload($paths[0], 'https://api.myapp.com/avatar', function (Upload $u) {
        $u->headers(['Authorization' => 'Bearer ' . NativeBlade::getState('auth.token')])
          ->id('avatar');
    })->toResponse();
}

#[On('nb:upload-progress')]
public function onProgress($progress = 0, $total = 0, $id = null)
{
    $this->percent = $total > 0 ? round(($progress / $total) * 100) : 0;
}

#[On('nb:upload-complete')]
public function onDone($success = false, $error = null, $id = null)
{
    $this->percent = $success ? 100 : 0;
}
```

### Let user choose where to save

Use the save dialog to let the user pick the destination:

```php
use NativeBlade\Plugins\FilePicker;

public function exportData()
{
    return NativeBlade::fileSave('report.csv', function (FilePicker $picker) {
        $picker->filters(['CSV' => ['csv']]);
    })->toResponse();
}

#[On('nb:file-save-result')]
public function onSavePath($path = null, $id = null)
{
    if (!$path) return;

    $csv = $this->generateCsv();
    Storage::disk('native')->put($path, $csv);

    return NativeBlade::notification(function ($n) {
        $n->title('Exported')->body('Report saved successfully.');
    })->toResponse();
}
```
