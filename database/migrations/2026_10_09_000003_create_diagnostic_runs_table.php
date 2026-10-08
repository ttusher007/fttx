<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostic_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16);                       // snmp|cli|enrich
            $table->string('title')->nullable();
            $table->json('params')->nullable();
            $table->string('status', 16)->default('pending'); // pending|running|success|failed
            $table->string('summary')->nullable();
            $table->longText('output')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['olt_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostic_runs');
    }
};
