<?php

namespace NativeBlade\Components;

use Illuminate\View\Component;

class NbModal extends Component
{
    public function __construct(
        public string $bg = '#111111',
        public string $overlay = 'rgba(0,0,0,0.7)',
        public string $position = 'bottom',
    ) {}

    public function render()
    {
        return view('nativeblade::components.nativeblade.modal');
    }
}
