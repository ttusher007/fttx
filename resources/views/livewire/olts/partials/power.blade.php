@php
$q = $onu->signalQuality();
$color = match ($q) {
    'good' => 'text-emerald-600',
    'warning' => 'text-amber-600',
    'critical' => 'text-red-600',
    default => 'text-slate-400',
};
$oltRx = $onu->olt_rx_power;
$oltColor = match (true) {
    $oltRx === null => 'text-slate-400',
    $oltRx >= -28 && $oltRx <= -8 => 'text-emerald-600',
    $oltRx < -30 || $oltRx > -8 => 'text-red-600',
    default => 'text-amber-600',
};
@endphp
<span class="whitespace-nowrap">
    <span title="ONU Rx — downstream received at the ONU (dBm)" class="font-medium {{ $color }}">↓{{ $onu->rx_power !== null ? number_format($onu->rx_power, 2) : '—' }}</span>
    <span title="ONU Tx — upstream transmitted by the ONU (dBm)" class="text-slate-400">/ ↑{{ $onu->tx_power !== null ? number_format($onu->tx_power, 2) : '—' }}</span>
    <span title="OLT Rx — upstream as received by the OLT from this ONU (dBm)" class="{{ $oltColor }}">· OLT {{ $oltRx !== null ? number_format($oltRx, 2) : '—' }}</span>
    @if ($onu->cli_synced_at)
        <span class="ml-1 rounded bg-slate-100 px-1 text-[10px] font-medium text-slate-500" title="Refreshed over CLI {{ $onu->cli_synced_at->diffForHumans() }}">CLI</span>
    @endif
</span>
