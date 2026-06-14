@props(['color' => 'slate'])

@php
$classes = match ($color) {
    'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'red'     => 'bg-red-50 text-red-700 ring-red-600/20',
    'amber'   => 'bg-amber-50 text-amber-700 ring-amber-600/20',
    'orange'  => 'bg-orange-50 text-orange-700 ring-orange-600/20',
    'blue'    => 'bg-blue-50 text-blue-700 ring-blue-600/20',
    'indigo'  => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
    'zinc'    => 'bg-zinc-100 text-zinc-700 ring-zinc-600/20',
    default   => 'bg-slate-100 text-slate-700 ring-slate-600/20',
};
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset $classes"]) }}>
    {{ $slot }}
</span>
