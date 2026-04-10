<?php

namespace NativeBlade\Storage;

enum StoragePath: string
{
    case APP = 'app';
    case CACHE = 'cache';
    case EXPORT = 'export';
    case DOWNLOADS = 'downloads';
    case TEMP = 'temp';
}
