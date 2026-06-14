<?php

namespace App\Http\Resources;

use App\Models\Onu;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Onu
 */
class OnuResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'serial_number' => $this->serial_number,
            'mac_address' => $this->mac_address,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'optical' => [
                'rx_power_dbm' => $this->rx_power !== null ? (float) $this->rx_power : null,
                'tx_power_dbm' => $this->tx_power !== null ? (float) $this->tx_power : null,
                'signal_quality' => $this->signalQuality(),
            ],
            'distance_m' => $this->distance !== null ? (float) $this->distance : null,
            'online_since' => $this->online_since?->toIso8601String(),
            'uptime_human' => $this->online_since?->diffForHumans(null, true),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'olt' => [
                'id' => $this->olt_id,
                'name' => $this->whenLoaded('olt', fn () => $this->olt->name),
                'vendor' => $this->whenLoaded('olt', fn () => $this->olt->vendor),
            ],
            'port' => [
                'index' => $this->onu_index,
                'name' => $this->whenLoaded('port', fn () => $this->port?->name),
            ],
        ];
    }
}
