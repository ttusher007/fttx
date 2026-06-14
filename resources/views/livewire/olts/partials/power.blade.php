@php
$q = $onu->signalQuality();
$color = match ($q) {
    'good' => 'text-emerald-600',
    'warning' => 'text-amber-600',
    'critical' => 'text-red-600',
    default => 'text-slate-400',
};
@endphp
<span class="font-medium {{ $color }}">{{ $onu->rx_power !== null ? number_format($onu->rx_power, 2) : '—' }}</span>
<span class="text-slate-400">/ {{ $onu->tx_power !== null ? number_format($onu->tx_power, 2) : '—' }}</span>
