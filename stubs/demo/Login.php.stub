<?php

namespace App\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;
use NativeBlade\Facades\NativeBlade;

#[Layout('components.layouts.auth')]
class Login extends Component
{
    public string $email = '';
    public string $password = '';
    public string $error = '';
    public function mount()
    {
        NativeBlade::forget('auth.user');
        NativeBlade::forget('auth.logged_at');
        NativeBlade::forget('trail.xp');
        NativeBlade::forget('trail.streak');
        NativeBlade::forget('trail.completed');
    }

    public function login()
    {
        $this->error = '';

        if ($this->email === 'admin@admin.com' && $this->password === '123456') {
            NativeBlade::setState('auth.user', [
                'name' => 'Admin',
                'email' => $this->email,
            ]);
            NativeBlade::setState('auth.logged_at', now()->timestamp);

            return NativeBlade::navigate('/', replace: true)->toResponse();
        }

        $this->error = 'Invalid credentials';
    }

    public function render()
    {
        return view('livewire.login');
    }
}
