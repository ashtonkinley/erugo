<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Subject focal points for rotating background images.
     *
     * Backgrounds are rendered with `background-size: cover`, which
     * center-crops by default and can cut portrait subjects out of frame on
     * narrow viewports. Storing one focal point (fractions of width/height)
     * per file lets the frontend use `background-position: x% y%` so the
     * crop stays on the subject on any screen size. NULL = no subject
     * detected, fall back to centered.
     */
    public function up(): void
    {
        Schema::create('background_focal_points', function (Blueprint $table) {
            $table->string('filename')->primary();
            $table->float('focal_x')->nullable();
            $table->float('focal_y')->nullable();
            $table->timestamp('detected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('background_focal_points');
    }
};
