<?php

namespace NativeBlade\Components;

use Illuminate\View\Component;
use NativeBlade\NativeBladeServiceProvider;

class NbImage extends Component
{
    public string $src;

    public function __construct(
        public string $asset,
        public string $alt = '',
        public string $class = '',
    ) {
        $this->src = NativeBladeServiceProvider::assetToDataUri($asset);
    }

    public function render()
    {
        return '<img src="{{ $src }}" alt="{{ $alt }}" class="{{ $class }}" wire:ignore.self />';
    }
}
