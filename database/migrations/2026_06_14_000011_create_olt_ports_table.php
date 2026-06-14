<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('olt_ports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olt_id')->constrained()->cascadeOnDelete();
            $table->string('port_index');                 // ifIndex / PON port identifier
            $table->string('name')->nullable();           // e.g. GPON 0/1/1
            $table->string('admin_status')->nullable();   // up|down
            $table->string('oper_status')->nullable();    // up|down
            $table->unsignedInteger('onu_count')->default(0);
            $table->unsignedInteger('onu_online_count')->default(0);
            $table->timestamps();

            $table->unique(['olt_id', 'port_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('olt_ports');
    }
};
