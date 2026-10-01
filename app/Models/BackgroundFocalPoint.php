<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Subject focal point for a rotating background image, as fractions of the
 * displayed image's width/height (e.g. focal_x=0.62, focal_y=0.38). NULL
 * coordinates mean no subject was detected and the frontend should
 * center-crop as before.
 *
 * Black film borders detected on lab scans are cropped into a display-only
 * copy (cleaned_filename, under cleaned/ on the backgrounds disk); the
 * original file is never modified, and border_* record the detected insets
 * as fractions for transparency.
 */
class BackgroundFocalPoint extends Model
{
    protected $primaryKey = 'filename';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'filename',
        'focal_x',
        'focal_y',
        'border_top',
        'border_right',
        'border_bottom',
        'border_left',
        'cleaned_filename',
        'detected_at',
    ];

    protected $casts = [
        'focal_x' => 'float',
        'focal_y' => 'float',
        'border_top' => 'float',
        'border_right' => 'float',
        'border_bottom' => 'float',
        'border_left' => 'float',
        'detected_at' => 'datetime',
    ];
}
