<?php

namespace App\Models;

use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;

class TondoLog extends Model
{
    use UuidPrimary;

    protected $table = 'tondo_logs';
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'datetime',
        'created_at' => 'datetime',
        'metadonnees' => 'array',
    ];
}
