<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $exists = DB::table('settings')->where('key', 'auto_clean_expired_shares')->exists();

        if (!$exists) {
            DB::table('settings')->insert([
                'key' => 'auto_clean_expired_shares',
                'value' => 'true',
                'previous_value' => null,
                'group' => 'system.shares',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')->where('key', 'auto_clean_expired_shares')->delete();
    }
};
