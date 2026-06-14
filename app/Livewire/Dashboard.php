<?php

namespace App\Livewire;

use App\Enums\OnuStatus;
use App\Models\Olt;
use App\Models\Onu;
use App\Models\SyncLog;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Dashboard'])]
class Dashboard extends Component
{
    public function render()
    {
        $oltStats = Olt::query()
            ->selectRaw('COUNT(*) total')
            ->selectRaw("SUM(status = 'active') active")
            ->selectRaw("SUM(last_sync_status = 'failed') failing")
            ->first();

        $onuStats = Onu::query()
            ->selectRaw('COUNT(*) total')
            ->selectRaw('SUM(status = ?) online', [OnuStatus::Online->value])
            ->selectRaw('SUM(status = ?) los', [OnuStatus::Losi->value])
            ->first();

        $totalOnu = (int) $onuStats->total;
        $onlineOnu = (int) $onuStats->online;

        $byVendor = Olt::query()
            ->selectRaw('vendor, COUNT(*) olts, SUM(onu_count) onus, SUM(onu_online_count) online')
            ->groupBy('vendor')
            ->orderByDesc('onus')
            ->get();

        $recentSyncs = SyncLog::with('olt')
            ->latest('id')
            ->limit(8)
            ->get();

        // OLTs with the worst online ratio (attention list).
        $attention = Olt::query()
            ->where('onu_count', '>', 0)
            ->orderByRaw('(onu_online_count * 1.0 / onu_count) asc')
            ->limit(5)
            ->get();

        return view('livewire.dashboard', [
            'oltTotal' => (int) $oltStats->total,
            'oltActive' => (int) $oltStats->active,
            'oltFailing' => (int) $oltStats->failing,
            'onuTotal' => $totalOnu,
            'onuOnline' => $onlineOnu,
            'onuOffline' => $totalOnu - $onlineOnu,
            'onuLos' => (int) $onuStats->los,
            'onlinePct' => $totalOnu > 0 ? round($onlineOnu / $totalOnu * 100, 1) : 0,
            'byVendor' => $byVendor,
            'recentSyncs' => $recentSyncs,
            'attention' => $attention,
        ]);
    }
}
