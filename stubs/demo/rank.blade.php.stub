<div>

{{-- Header --}}
<div class="bg-[#111111] border-b border-[#2a2a2a] px-4 pt-10 pb-4">
    <div class="max-w-lg mx-auto">
        <div class="flex items-center gap-3 mb-1">
            <x-nativeblade-icon name="trophy-fill" size="28" class="text-[#f1c40f]" />
            <h1 class="text-2xl font-black uppercase tracking-wide">Leaderboard</h1>
        </div>
        <p class="text-[#9ca3af] text-sm">Weekly League &bull; Resets in <span class="text-[#e74c3c] font-bold">3 days</span></p>
    </div>
</div>

{{-- Podium --}}
@php
    $top3 = array_slice($players, 0, 3);
    $rest = array_slice($players, 3);
@endphp

<div nb-animation="fadeInDown" nb-animation-speed="fast" class="bg-[#111111] px-4 pt-4 pb-6 border-b border-[#2a2a2a]">
    <div class="max-w-lg mx-auto flex items-end justify-center gap-3">

        {{-- 2nd --}}
        <div nb-animation="fadeInUp" nb-animation-delay="200ms" class="flex flex-col items-center gap-1">
            <div class="w-12 h-12 rounded-full bg-[#1a1a1a] border-2 border-[#9ca3af] flex items-center justify-center font-black text-sm text-[#9ca3af]">
                {{ $top3[1]['avatar'] ?? '' }}
            </div>
            <x-nativeblade-icon name="medal-fill" size="16" class="text-[#9ca3af]" />
            <p class="text-[11px] text-[#9ca3af] font-bold">{{ explode(' ', $top3[1]['name'] ?? '')[0] }}</p>
            <p class="text-[10px] text-[#6b7280]">{{ number_format($top3[1]['xp'] ?? 0) }} XP</p>
            <div class="w-16 h-10 bg-[#1a1a1a] rounded-t-lg border border-[#2a2a2a] flex items-center justify-center">
                <span class="text-[#9ca3af] font-black text-lg">2</span>
            </div>
        </div>

        {{-- 1st --}}
        <div nb-animation="fadeInUp" nb-animation-delay="100ms" class="flex flex-col items-center gap-1">
            <x-nativeblade-icon name="crown-fill" size="20" class="text-[#f1c40f]" />
            <div class="w-16 h-16 rounded-full bg-[#1a1a1a] border-2 border-[#f1c40f] shadow-[0_0_15px_rgba(241,196,15,0.4)] flex items-center justify-center font-black text-base text-[#f1c40f]">
                {{ $top3[0]['avatar'] ?? '' }}
            </div>
            <p class="text-[11px] text-[#f1c40f] font-bold">{{ explode(' ', $top3[0]['name'] ?? '')[0] }}</p>
            <p class="text-[10px] text-[#9ca3af]">{{ number_format($top3[0]['xp'] ?? 0) }} XP</p>
            <div class="w-16 h-14 bg-[#1a1a1a] rounded-t-lg border border-[#f1c40f] border-b-0 shadow-[0_0_10px_rgba(241,196,15,0.2)] flex items-center justify-center">
                <span class="text-[#f1c40f] font-black text-xl">1</span>
            </div>
        </div>

        {{-- 3rd --}}
        <div nb-animation="fadeInUp" nb-animation-delay="300ms" class="flex flex-col items-center gap-1">
            <div class="w-12 h-12 rounded-full bg-[#1a1a1a] border-2 border-[#cd7f32] flex items-center justify-center font-black text-sm text-[#cd7f32]">
                {{ $top3[2]['avatar'] ?? '' }}
            </div>
            <x-nativeblade-icon name="medal-fill" size="16" class="text-[#cd7f32]" />
            <p class="text-[11px] text-[#cd7f32] font-bold">{{ explode(' ', $top3[2]['name'] ?? '')[0] }}</p>
            <p class="text-[10px] text-[#6b7280]">{{ number_format($top3[2]['xp'] ?? 0) }} XP</p>
            <div class="w-16 h-8 bg-[#1a1a1a] rounded-t-lg border border-[#2a2a2a] flex items-center justify-center">
                <span class="text-[#cd7f32] font-black text-lg">3</span>
            </div>
        </div>
    </div>
</div>

{{-- Player List --}}
<div class="flex-1 overflow-y-auto px-4 py-3 pb-24">
    <div class="max-w-lg mx-auto space-y-2">
        @foreach($rest as $idx => $player)
            @php $isMe = ($player['isMe'] ?? false); @endphp
            <div nb-animation="fadeInLeft" nb-animation-delay="{{ 50 + $idx * 50 }}ms" nb-animation-speed="fast"
                 class="flex items-center gap-3 p-3 rounded-xl border transition-all {{ $isMe ? 'bg-[#1a0a0a] border-[#8B0000] shadow-[0_0_12px_rgba(139,0,0,0.2)]' : 'bg-[#111111] border-[#2a2a2a]' }}">

                <div class="w-6 flex items-center justify-center">
                    <span class="text-[#6b7280] font-bold text-sm">{{ $player['rank'] }}</span>
                </div>

                <div class="w-10 h-10 rounded-full flex items-center justify-center font-black text-xs border {{ $isMe ? 'bg-[#8B0000] border-[#c0392b] text-white' : 'bg-[#1a1a1a] border-[#2a2a2a] text-[#9ca3af]' }}">
                    {{ $player['avatar'] }}
                </div>

                <div class="flex-1 min-w-0">
                    <p class="font-bold text-sm {{ $isMe ? 'text-[#e74c3c]' : 'text-white' }}">
                        {{ $player['name'] }}
                        @if($isMe) <span class="text-[#6b7280] font-normal text-xs">(you)</span> @endif
                    </p>
                    <div class="flex items-center gap-2 mt-0.5">
                        <x-nativeblade-icon name="flame-fill" size="12" class="text-orange-500" />
                        <span class="text-[#6b7280] text-xs">{{ $player['streak'] }} day streak</span>
                    </div>
                </div>

                <div class="text-right">
                    <p class="font-black text-sm {{ $isMe ? 'text-[#e74c3c]' : 'text-[#f1c40f]' }}">
                        {{ number_format($player['xp']) }}
                    </p>
                    <p class="text-[#6b7280] text-[10px]">XP</p>
                </div>
            </div>
        @endforeach
    </div>
</div>

{{-- Bottom Nav --}}
<x-nativeblade-bottom-nav bg="#111111" border-color="#2a2a2a">
    <div style="display:flex;justify-content:space-around;align-items:center;padding:12px 24px 24px;max-width:600px;margin:0 auto">
        <div data-nav="/" style="display:flex;flex-direction:column;align-items:center;gap:4px;color:#6b7280;cursor:pointer">
            <x-nativeblade-icon name="map-trifold" size="24" />
            <span style="font-size:10px;font-weight:700;text-transform:uppercase">Trail</span>
        </div>
        <div style="display:flex;flex-direction:column;align-items:center;gap:4px;color:#c0392b;cursor:pointer">
            <x-nativeblade-icon name="trophy-fill" size="24" />
            <span style="font-size:10px;font-weight:700;text-transform:uppercase;color:#c0392b">Rank</span>
        </div>
        <div data-nav="/profile" style="display:flex;flex-direction:column;align-items:center;gap:4px;color:#6b7280;cursor:pointer">
            <x-nativeblade-icon name="user" size="24" />
            <span style="font-size:10px;font-weight:700;text-transform:uppercase">Profile</span>
        </div>
    </div>
</x-nativeblade-bottom-nav>
</div>
