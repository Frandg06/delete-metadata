<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImageBatchResult extends Model
{
    protected $fillable = [
        'batch_id',
        'original_name',
        'path',
        'url',
    ];
}
