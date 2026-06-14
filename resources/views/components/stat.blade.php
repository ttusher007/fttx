@props(['label', 'value', 'icon' => 'chart', 'tone' => 'indigo', 'sub' => null])

@php
$tones = match ($tone) {
    'emerald' => 'from-emerald-500 to-emerald-600',
    'red'     => 'from-red-500 to-red-600',
    'amber'   => 'from-amber-500 to-amber-600',
    'blue'    => 'from-blue-500 to-blue-600',
    'slate'   => 'from-slate-600 to-slate-700',
    default   => 'from-indigo-500 to-indigo-600',
};
$paths = [
    'olt'    => 'M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2',
    'onu'    => 'M9 17v-2a4 4 0 00-4-4H3m18 0h-2a4 4 0 00-4 4v2M9 7a3 3 0 116 0 3 3 0 01-6 0z',
    'check'  => 'M5 13l4 4L19 7',
    'alert'  => 'M12 9v2m0 4h.01M5.07 19H19a2 2 0 001.75-2.96l-6.93-12a2 2 0 00-3.5 0l-6.93 12A2 2 0 005.07 19z',
    'key'    => 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z',
    'users'  => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
    'chart'  => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
];
$path = $paths[$icon] ?? $paths['chart'];
@endphp

<div class="card p-5">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm font-medium text-slate-500">{{ $label }}</p>
            <p class="mt-1 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $value }}</p>
            @if ($sub)
                <p class="mt-1 text-xs text-slate-400">{{ $sub }}</p>
            @endif
        </div>
        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br {{ $tones }} text-white shadow-sm">
            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $path }}"/>
            </svg>
        </div>
    </div>
</div>
