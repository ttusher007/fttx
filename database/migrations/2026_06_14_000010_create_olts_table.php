<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('olts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ip_address');
            $table->string('vendor')->index();          // huawei|bdcom|vsol|generic
            $table->string('model')->nullable();
            $table->string('location')->nullable();

            // SNMP credentials
            $table->string('snmp_version')->default('v2c');
            $table->unsignedInteger('snmp_port')->default(161);
            $table->text('snmp_community')->nullable();   // encrypted
            // SNMP v3
            $table->string('snmp_sec_name')->nullable();
            $table->string('snmp_auth_protocol')->nullable(); // MD5|SHA
            $table->text('snmp_auth_password')->nullable();   // encrypted
            $table->string('snmp_priv_protocol')->nullable(); // DES|AES
            $table->text('snmp_priv_password')->nullable();   // encrypted

            // SSH (optional / fallback)
            $table->string('ssh_username')->nullable();
            $table->unsignedInteger('ssh_port')->default(22);
            $table->text('ssh_password')->nullable();         // encrypted

            // Operational
            $table->string('status')->default('active');      // active|inactive|maintenance
            $table->boolean('live_fetch')->default(true);     // include in continuous sync
            $table->boolean('is_simulated')->default(false);  // demo / no real device
            $table->unsignedInteger('sync_interval')->nullable(); // minutes; null = config default

            // Cached rollups (updated each sync)
            $table->unsignedInteger('port_count')->default(0);
            $table->unsignedInteger('onu_count')->default(0);
            $table->unsignedInteger('onu_online_count')->default(0);
            $table->unsignedInteger('onu_offline_count')->default(0);

            // Sync state
            $table->string('last_sync_status')->nullable();   // success|partial|failed
            $table->text('last_sync_message')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedInteger('last_sync_duration_ms')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['ip_address', 'snmp_port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('olts');
    }
};
