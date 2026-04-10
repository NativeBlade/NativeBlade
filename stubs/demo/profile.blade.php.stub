<div class="relative">

{{-- Shell Modal (pre-rendered, triggered via wire:nb-bridge="showModal") --}}
<x-nativeblade-modal>
    <div style="padding:24px;border-top:1px solid #2a2a2a">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <h3 style="font-size:18px;font-weight:900;color:#fff;margin:0">Sign out?</h3>
            <button data-dismiss style="background:none;border:none;color:#6b7280;cursor:pointer;padding:4px">
                <svg width="20" height="20" viewBox="0 0 256 256" fill="currentColor"><path d="M205.66,194.34a8,8,0,0,1-11.32,11.32L128,139.31,61.66,205.66a8,8,0,0,1-11.32-11.32L116.69,128,50.34,61.66A8,8,0,0,1,61.66,50.34L128,116.69l66.34-66.35a8,8,0,0,1,11.32,11.32L139.31,128Z"/></svg>
            </button>
        </div>
        <p style="color:#9ca3af;font-size:14px;margin:0 0 24px">Your progress is saved. You can sign back in anytime.</p>
        <div style="display:flex;flex-direction:column;gap:12px">
            <button data-nav="/login" data-replace
                style="width:100%;padding:12px;border-radius:12px;font-weight:700;font-size:18px;background:#8B0000;color:#fff;border:none;cursor:pointer;text-transform:uppercase;letter-spacing:1px;box-shadow:0 4px 0 rgba(60,0,0,1)">
                Sign Out
            </button>
            <button data-dismiss
                style="width:100%;padding:12px;border-radius:12px;font-weight:700;color:#9ca3af;background:none;border:1px solid #2a2a2a;cursor:pointer">
                Cancel
            </button>
        </div>
    </div>
</x-nativeblade-modal>

{{-- Header --}}
<div class="bg-[#111111] border-b border-[#2a2a2a] px-4 pt-10 pb-5">
    <div class="max-w-lg mx-auto">
        <div class="flex items-start gap-4">
            <div nb-animation="zoomIn" nb-animation-speed="fast" class="relative group">
                @if($avatarSrc)
                    <img src="{{ $avatarSrc }}" class="w-20 h-20 rounded-full border-2 border-[#c0392b] shadow-[0_0_20px_rgba(192,57,43,0.4)] object-cover" />
                @else
                    <div class="w-20 h-20 rounded-full bg-[#8B0000] border-2 border-[#c0392b] shadow-[0_0_20px_rgba(192,57,43,0.4)] flex items-center justify-center">
                        <span class="text-3xl font-black text-white">{{ strtoupper(substr($userName, 0, 1)) }}</span>
                    </div>
                @endif
                <div class="absolute -bottom-1 -right-1 bg-[#f1c40f] rounded-full w-7 h-7 flex items-center justify-center border-2 border-[#0a0a0a]">
                    <span class="text-[10px] font-black text-black">{{ intdiv($xp, 200) + 1 }}</span>
                </div>
                <div class="absolute inset-0 rounded-full bg-black/50 flex items-center justify-center opacity-0 group-active:opacity-100 transition-opacity">
                    <x-nativeblade-icon name="camera" size="24" class="text-white" />
                </div>
            </div>

            <div nb-animation="fadeInUp" nb-animation-delay="100ms" class="flex-1">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-black">{{ $userName }}</h2>
                    <button wire:nb-bridge="showModal"
                        class="flex items-center gap-1.5 text-[#6b7280] hover:text-[#e74c3c] transition-colors bg-[#1a1a1a] border border-[#2a2a2a] rounded-lg px-2.5 py-1.5">
                        <x-nativeblade-icon name="sign-out" size="16" />
                        <span class="text-xs font-bold uppercase tracking-wide">Logout</span>
                    </button>
                </div>
                <p class="text-[#9ca3af] text-sm">{{ $email }}</p>
                <div class="flex items-center gap-1 mt-1">
                    <x-nativeblade-icon name="flame-fill" size="16" class="text-orange-500" />
                    <span class="text-orange-500 font-bold text-sm">{{ $streak }}-day streak</span>
                </div>
            </div>
        </div>

        <div nb-animation="fadeInUp" nb-animation-delay="150ms" class="mt-4">
            <div class="flex justify-between text-xs font-bold mb-1.5">
                <span class="text-[#f1c40f]">Level {{ intdiv($xp, 200) + 1 }}</span>
                <span class="text-[#6b7280]">{{ number_format($xp) }} / {{ number_format((intdiv($xp, 200) + 1) * 200) }} XP</span>
            </div>
            <div class="h-3 bg-[#1a1a1a] rounded-full overflow-hidden border border-[#2a2a2a]">
                <div class="h-full bg-[#f1c40f] rounded-full relative overflow-hidden" style="width: {{ ($xp % 200) / 2 }}%">
                    <div nb-animation="shimmer" nb-animation-repeat="infinite" class="absolute top-0 bottom-0 w-[20%] bg-gradient-to-r from-transparent via-white/40 to-transparent"></div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Stats --}}
<div class="px-4 py-4">
    <div class="max-w-lg mx-auto grid grid-cols-2 gap-3">
        @foreach($stats as $i => $stat)
            <div nb-animation="fadeInUp" nb-animation-delay="{{ 100 + $i * 60 }}ms"
                 class="bg-[#111111] border border-[#2a2a2a] rounded-xl p-3 flex flex-col">
                <span class="text-[#6b7280] text-[10px] uppercase font-bold mb-1">{{ $stat['label'] }}</span>
                <span class="font-black text-xl" style="color: {{ $stat['color'] }}">{{ $stat['value'] }}</span>
            </div>
        @endforeach
    </div>
</div>

{{-- Achievements --}}
<div class="px-4 pb-24">
    <div class="max-w-lg mx-auto">
        <p class="text-sm font-black uppercase text-[#9ca3af] tracking-widest mb-3">Achievements</p>
        <div class="space-y-2">
            @foreach($achievements as $i => $a)
                <div nb-animation="fadeInLeft" nb-animation-delay="{{ 100 + $i * 50 }}ms" nb-animation-speed="fast"
                     class="rounded-xl border {{ $a['done'] ? 'bg-[#0f1a0f] border-[#2ecc71]/40' : 'bg-[#111111] border-[#2a2a2a]' }}">
                  <div class="flex items-center gap-3 p-3 {{ $a['done'] ? '' : 'opacity-50' }}">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center {{ $a['done'] ? 'bg-[#1a2a1a]' : 'bg-[#1a1a1a]' }}">
                        <x-nativeblade-icon name="{{ $a['icon'] }}" size="20" class="{{ $a['done'] ? 'text-[#2ecc71]' : 'text-[#4b5563]' }}" />
                    </div>
                    <span class="text-sm font-bold flex-1 {{ $a['done'] ? 'text-white' : 'text-[#6b7280]' }}">
                        {{ $a['label'] }}
                    </span>
                    @if($a['done'])
                        <x-nativeblade-icon name="check-circle-fill" size="20" class="text-[#2ecc71]" />
                    @else
                        <x-nativeblade-icon name="lock-simple" size="16" class="text-[#4b5563]" />
                    @endif
                  </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Avatar Actions --}}
    <div class="mt-4 max-w-lg mx-auto flex gap-3">
        <button wire:nb-bridge="camera" wire:nb-payload='{"maxWidth":400,"maxHeight":400,"quality":0.5}' nb-feedback
            class="flex-1 py-2.5 rounded-xl font-bold text-xs uppercase tracking-wide bg-[#1a1a1a] border border-[#2a2a2a] text-[#9ca3af] hover:text-white transition-colors flex items-center justify-center gap-2">
            <x-nativeblade-icon name="camera" size="16" />
            Take Photo
        </button>
        <button wire:nb-bridge="gallery" wire:nb-payload='{"maxWidth":400,"maxHeight":400,"quality":0.5}' nb-feedback
            class="flex-1 py-2.5 rounded-xl font-bold text-xs uppercase tracking-wide bg-[#1a1a1a] border border-[#2a2a2a] text-[#9ca3af] hover:text-white transition-colors flex items-center justify-center gap-2">
            <x-nativeblade-icon name="images" size="16" />
            Gallery
        </button>
    </div>

    {{-- Export Test --}}
    <div class="mt-4 max-w-lg mx-auto">
        <div class="flex gap-3">
            <button wire:click="exportStats" nb-feedback
                class="flex-1 py-3 rounded-xl font-bold text-sm uppercase tracking-wide bg-[#1a1a1a] border border-[#2a2a2a] text-[#9ca3af] hover:text-white transition-colors flex items-center justify-center gap-2">
                <x-nativeblade-icon name="export" size="18" />
                Export
            </button>
            <button wire:click="deleteExport" nb-feedback
                class="flex-1 py-3 rounded-xl font-bold text-sm uppercase tracking-wide bg-[#1a1a1a] border border-[#8B0000] text-[#e74c3c] hover:text-white transition-colors flex items-center justify-center gap-2">
                <x-nativeblade-icon name="trash" size="18" />
                Delete
            </button>
        </div>
        @if($exportMessage)
            <x-nativeblade-animate in="fadeInUp" out="fadeOutUp" dismiss="2.5s" class="mt-3 p-3 bg-[#2ecc71]/10 border border-[#2ecc71]/30 rounded-xl">
                <p class="text-[#2ecc71] text-sm font-bold text-center">{{ $exportMessage }}</p>
            </x-nativeblade-animate>
        @endif
    </div>
</div>

{{-- Bottom Nav --}}
<x-nativeblade-bottom-nav bg="#111111" border-color="#2a2a2a">
    <div style="display:flex;justify-content:space-around;align-items:center;padding:12px 24px 24px;max-width:600px;margin:0 auto">
        <div data-nav="/" style="display:flex;flex-direction:column;align-items:center;gap:4px;color:#6b7280;cursor:pointer">
            <x-nativeblade-icon name="map-trifold" size="24" />
            <span style="font-size:10px;font-weight:700;text-transform:uppercase">Trail</span>
        </div>
        <div data-nav="/rank" style="display:flex;flex-direction:column;align-items:center;gap:4px;color:#6b7280;cursor:pointer">
            <x-nativeblade-icon name="trophy" size="24" />
            <span style="font-size:10px;font-weight:700;text-transform:uppercase">Rank</span>
        </div>
        <div style="display:flex;flex-direction:column;align-items:center;gap:4px;color:#c0392b;cursor:pointer">
            <x-nativeblade-icon name="user-fill" size="24" />
            <span style="font-size:10px;font-weight:700;text-transform:uppercase;color:#c0392b">Profile</span>
        </div>
    </div>
</x-nativeblade-bottom-nav>
</div>
