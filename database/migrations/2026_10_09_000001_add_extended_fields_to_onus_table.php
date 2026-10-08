<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onus', function (Blueprint $table) {
            // Upstream power as measured by the OLT for this ONU ("Rx with ONU").
            $table->decimal('olt_rx_power', 6, 2)->nullable()->after('tx_power');
            // The ONU's own MAC (distinct from the customer router MAC in mac_address).
            $table->string('onu_mac')->nullable()->index()->after('mac_address');
            // Number of MACs learned behind the ONU (when the OLT reports it).
            $table->unsignedSmallInteger('mac_count')->nullable()->after('onu_mac');
            // Where mac_address came from: snmp | fdb | cli.
            $table->string('mac_source', 16)->nullable()->after('mac_count');
            // ONU hardware model / equipment id (e.g. "HG8546M", "310M").
            $table->string('model', 64)->nullable()->after('description');
            // Last offline event reported by the OLT.
            $table->timestamp('last_down_at')->nullable()->after('online_since');
            $table->string('last_down_cause', 64)->nullable()->after('last_down_at');
            // Last time CLI (SSH/Telnet) enrichment refreshed this row.
            $table->timestamp('cli_synced_at')->nullable()->after('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('onus', function (Blueprint $table) {
            $table->dropColumn([
                'olt_rx_power', 'onu_mac', 'mac_count', 'mac_source', 'model',
                'last_down_at', 'last_down_cause', 'cli_synced_at',
            ]);
        });
    }
};
