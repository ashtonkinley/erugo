<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Subject focal point for a rotating background image, as fractions of the
 * image width/height (e.g. focal_x=0.62, focal_y=0.38). NULL coordinates mean
 * no subject was detected and the frontend should center-crop as before.
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
        'detected_at',
    ];

    protected $casts = [
        'focal_x' => 'float',
        'focal_y' => 'float',
        'detected_at' => 'datetime',
    ];
}
