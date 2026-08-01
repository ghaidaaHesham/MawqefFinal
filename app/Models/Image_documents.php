<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Model;

class Image_documents extends Model
{
    use HasFactory;
 protected $table='image_documents';
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
