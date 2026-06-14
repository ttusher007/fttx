<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olt_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('onu_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type')->default('olt');       // olt|onu
            $table->string('trigger')->default('schedule'); // schedule|manual|api
            $table->string('status')->default('pending');  // pending|running|success|partial|failed
            $table->text('message')->nullable();
            $table->json('stats')->nullable();             // {ports, onus, online, ...}
            $table->unsignedInteger('duration_ms')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['olt_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
