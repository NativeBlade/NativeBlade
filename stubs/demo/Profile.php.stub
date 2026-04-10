<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Storage\StoragePath;

#[Layout('components.layouts.app')]
class Profile extends Component
{
    public string $userName = '';
    public string $email = '';
    public int $xp = 0;
    public int $streak = 0;
    public int $completed = 0;
    public int $accuracy = 87;
    public array $achievements = [];
    public array $stats = [];
    public string $exportMessage = '';
    public string $avatarSrc = '';

    public function mount()
    {
        $user = NativeBlade::getState('auth.user');
        $this->userName = $user['name'] ?? 'Guest';
        $this->email = $user['email'] ?? '';
        $this->xp = NativeBlade::getState('trail.xp', 0);
        $this->streak = NativeBlade::getState('trail.streak', 0);
        $this->completed = count(NativeBlade::getState('trail.completed', []));

        try {
            $content = Storage::disk('native')->get(native_path('avatar.jpg'));
            if ($content) {
                $this->avatarSrc = 'data:image/jpeg;base64,' . base64_encode($content);
            }
        } catch (\Throwable) {}

        $this->stats = [
            ['label' => 'Total XP', 'value' => number_format($this->xp), 'color' => '#f1c40f'],
            ['label' => 'Streak', 'value' => $this->streak . ' days', 'color' => '#e74c3c'],
            ['label' => 'Lessons', 'value' => (string) $this->completed, 'color' => '#2ecc71'],
            ['label' => 'Accuracy', 'value' => $this->accuracy . '%', 'color' => '#3498db'],
        ];

        $this->achievements = [
            ['icon' => 'flame-fill', 'label' => '7-day streak', 'done' => $this->streak >= 7],
            ['icon' => 'star-fill', 'label' => '100 XP in one day', 'done' => $this->xp >= 100],
            ['icon' => 'trophy-fill', 'label' => 'Top 3 in Leaderboard', 'done' => false],
            ['icon' => 'book-open-fill', 'label' => '10 lessons complete', 'done' => $this->completed >= 10],
            ['icon' => 'target', 'label' => 'Perfect score, no mistakes', 'done' => $this->completed > 0],
            ['icon' => 'lightning-fill', 'label' => '5 days in a row', 'done' => $this->streak >= 5],
        ];
    }

    #[On('nb:camera-result')]
    public function onPhoto($data = null, $name = null, $mime = null, $size = null)
    {
        if (!$data) return;

        $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $data);
        Storage::disk('native')->put(native_path('avatar.jpg'), base64_decode($base64));

        $this->avatarSrc = $data;
    }

    public function exportStats()
    {
        $data = json_encode([
            'user' => $this->userName,
            'email' => $this->email,
            'xp' => $this->xp,
            'streak' => $this->streak,
            'lessons' => $this->completed,
            'exported_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT);

        Storage::disk('native')->put(native_path('stats.json', StoragePath::EXPORT), $data);

        $this->exportMessage = "Exported to Documents!";
    }

    public function deleteExport()
    {
        Storage::disk('native')->delete(native_path('stats.json', StoragePath::EXPORT));
        $this->exportMessage = "File deleted!";
    }

    public function render()
    {
        return view('livewire.profile');
    }
}
