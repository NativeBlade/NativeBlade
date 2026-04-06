<?php

namespace NativeBlade\Components;

use Illuminate\View\Component;

class NbAction extends Component
{
    public function __construct(
        public string $icon = '',
        public string $action = '',
        public string $badge = '',
        public string $color = '',
    ) {}

    public function render()
    {
        return view('nativeblade::components.nativeblade.action');
    }
}
