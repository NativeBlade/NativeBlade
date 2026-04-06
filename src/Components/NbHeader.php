<?php

namespace NativeBlade\Components;

use Illuminate\View\Component;

class NbHeader extends Component
{
    public function __construct(
        public string $title = '',
        public bool $back = false,
        public string $bg = '',
        public string $color = '',
        public string $borderColor = '',
    ) {}

    public function render()
    {
        return view('nativeblade::components.nativeblade.header');
    }
}
