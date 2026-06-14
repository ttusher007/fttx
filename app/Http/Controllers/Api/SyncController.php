<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncOltJob;
use App\Jobs\SyncOnuJob;
use App\Models\Olt;
use App\Models\Onu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SyncController extends Controller
{
    /**
     * Request a sync of a whole OLT.
     * POST /api/v1/sync/olt  Body: { "olt_id": 1 }
     */
    public function olt(Request $request): JsonResponse
    {
        $data = $request->validate(['olt_id' => ['required', 'integer']]);

        $olt = Olt::find($data['olt_id']);

        if (! $olt) {
            return response()->json(['success' => false, 'message' => 'OLT not found.'], 404);
        }

        SyncOltJob::dispatch($olt->id, 'api');

        return response()->json([
            'success' => true,
            'message' => "Sync queued for OLT '{$olt->name}'.",
        ], 202);
    }

    /**
     * Request a sync of a single ONU, identified by id, serial, or MAC.
     * POST /api/v1/sync/onu  Body: { "serial_number": "..." } | { "mac_address": "..." } | { "onu_id": 1 }
     */
    public function onu(Request $request): JsonResponse
    {
        $data = $request->validate([
            'onu_id' => ['nullable', 'integer'],
            'serial_number' => ['nullable', 'string', 'max:64'],
            'mac_address' => ['nullable', 'string', 'max:32'],
        ]);

        $query = Onu::query();

        if (! empty($data['onu_id'])) {
            $query->whereKey($data['onu_id']);
        } elseif (! empty($data['serial_number'])) {
            $query->where('serial_number', strtoupper(trim($data['serial_number'])));
        } elseif (! empty($data['mac_address'])) {
            $query->where('mac_address', strtoupper(trim($data['mac_address'])));
        } else {
            throw ValidationException::withMessages([
                'onu_id' => 'Provide onu_id, serial_number, or mac_address.',
            ]);
        }

        $onu = $query->first();

        if (! $onu) {
            return response()->json(['success' => false, 'message' => 'ONU not found.'], 404);
        }

        SyncOnuJob::dispatch($onu->id, 'api');

        return response()->json([
            'success' => true,
            'message' => 'Sync queued for ONU.',
            'onu_id' => $onu->id,
        ], 202);
    }
}
