---
title: "Upload"
description: "Stream a file from the device to a URL, with a result event when it finishes."
---

# Upload

Stream a file from the device to a remote URL. The upload runs on the native
side, so large files do not block the WebView.

```php
use NativeBlade\Facades\NativeBlade;

return NativeBlade::upload($path, 'https://api.example.com/upload')->toResponse();
```

- `$path` is the absolute path to the file on the device, for example a photo
  returned by the camera or a file from `Storage`.
- The second argument is the destination URL.

## Headers and an id

Pass a callback to add headers or tag the upload with an id:

```php
use NativeBlade\Plugins\Upload;

return NativeBlade::upload($path, $url, function (Upload $u) {
    $u->headers(['Authorization' => "Bearer {$token}"])
      ->id('avatar');
})->toResponse();
```

## Result

Completion arrives as the `nb:upload-result` event, carrying the HTTP status and
the response body:

```php
#[On('nb:upload-result')]
public function onUploaded(int $status = 0, string $body = '')
{
    if ($status >= 200 && $status < 300) {
        // upload succeeded
    }
}
```

## Enable the plugin

```php
NativeBladeConfig::plugins([
    Plugin::UPLOAD,
]);
```
