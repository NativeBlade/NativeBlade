<?php

use NativeBlade\Storage\StoragePath;

if (!function_exists('native_path')) {
    function native_path(string $path, StoragePath $purpose = StoragePath::APP): string
    {
        return '__nb:' . $purpose->value . ':' . ltrim($path, '/');
    }
}
