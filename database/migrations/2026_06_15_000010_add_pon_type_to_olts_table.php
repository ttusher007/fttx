<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            // PON technology — gpon|epon. Picks the vendor ONU OID map.
            $table->string('pon_type')->nullable()->after('model');
            // True when pon_type was filled in automatically by a connection
            // test rather than chosen by an operator. Lets the UI flag it and
            // lets auto-detection avoid overwriting a manual choice.
            $table->boolean('pon_type_auto_detected')->default(false)->after('pon_type');
        });
    }

    public function down(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            $table->dropColumn(['pon_type', 'pon_type_auto_detected']);
        });
    }
};
