<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    use HasFactory;
 protected $table='images';
    protected $fillable = [
        'path',
        'title',
        'imageable_type',
        'imageable_id',
    ];

    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }
}