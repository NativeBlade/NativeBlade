<?php

namespace NativeBlade\Components;

use Illuminate\View\Component;
use NativeBlade\IconRegistry;

class NbIcon extends Component
{
    public string $svg;

    public function __construct(
        public string $name,
        public string $size = '24',
        public string $class = '',
    ) {
        $this->svg = IconRegistry::svg($name, $class, $size);
    }

    public function render()
    {
        return $this->svg;
    }
}
