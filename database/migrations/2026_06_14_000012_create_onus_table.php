<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('olt_port_id')->nullable()->constrained()->nullOnDelete();

            $table->string('onu_index');                  // vendor ONU index within the OLT
            $table->string('serial_number')->nullable()->index();
            $table->string('mac_address')->nullable()->index();   // client router MAC
            $table->string('name')->nullable();
            $table->string('description')->nullable();

            $table->string('status')->default('unknown'); // online|offline|los|dying_gasp|unknown
            $table->decimal('rx_power', 6, 2)->nullable(); // dBm
            $table->decimal('tx_power', 6, 2)->nullable(); // dBm
            $table->decimal('distance', 10, 2)->nullable(); // meters

            $table->timestamp('online_since')->nullable();   // "live since"
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            $table->unique(['olt_id', 'onu_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onus');
    }
};
