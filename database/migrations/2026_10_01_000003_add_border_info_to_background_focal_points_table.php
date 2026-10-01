<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('background_focal_points', function (Blueprint $table) {
            $table->float('border_top')->nullable()->after('focal_y');
            $table->float('border_right')->nullable()->after('border_top');
            $table->float('border_bottom')->nullable()->after('border_right');
            $table->float('border_left')->nullable()->after('border_bottom');
            $table->string('cleaned_filename')->nullable()->after('border_left');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('background_focal_points', function (Blueprint $table) {
            $table->dropColumn([
                'border_top', 'border_right', 'border_bottom', 'border_left',
                'cleaned_filename',
            ]);
        });
    }
};
