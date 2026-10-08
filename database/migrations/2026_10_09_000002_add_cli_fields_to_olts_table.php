<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            // CLI (SSH/Telnet) enrichment through the Python collector. Used for
            // data SNMP cannot provide on some firmwares (optical power on
            // Huawei MA5683T V800R018, customer MAC addresses, ...).
            $table->boolean('cli_enabled')->default(false)->after('ssh_password');
            $table->string('cli_protocol', 8)->default('ssh')->after('cli_enabled'); // ssh|telnet
            $table->unsignedInteger('cli_interval')->nullable()->after('cli_protocol'); // minutes
            $table->string('cli_last_status')->nullable()->after('cli_interval');
            $table->text('cli_last_message')->nullable()->after('cli_last_status');
            $table->timestamp('cli_last_synced_at')->nullable()->after('cli_last_message');
        });
    }

    public function down(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            $table->dropColumn([
                'cli_enabled', 'cli_protocol', 'cli_interval',
                'cli_last_status', 'cli_last_message', 'cli_last_synced_at',
            ]);
        });
    }
};
