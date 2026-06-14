<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OnuResource;
use App\Models\Onu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OnuLookupController extends Controller
{
    /**
     * Look up an ONU by serial number or client MAC address.
     *
     * POST /api/v1/onu/lookup
     * Body: { "serial_number": "..." }  OR  { "mac_address": "..." }
     */
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'serial_number' => ['nullable', 'string', 'max:64'],
            'mac_address' => ['nullable', 'string', 'max:32'],
        ]);

        if (empty($data['serial_number']) && empty($data['mac_address'])) {
            throw ValidationException::withMessages([
                'serial_number' => 'Provide either serial_number or mac_address.',
            ]);
        }

        $query = Onu::with(['olt', 'port']);

        if (! empty($data['serial_number'])) {
            $query->where('serial_number', strtoupper(trim($data['serial_number'])));
        } else {
            $query->where('mac_address', $this->normaliseMac($data['mac_address']));
        }

        $onu = $query->first();

        if (! $onu) {
            return response()->json([
                'success' => false,
                'message' => 'No ONU found for the provided identifier.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new OnuResource($onu),
        ]);
    }

    private function normaliseMac(string $mac): string
    {
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $mac);

        if (strlen($hex) === 12) {
            return strtoupper(implode(':', str_split($hex, 2)));
        }

        return strtoupper($mac);
    }
}
