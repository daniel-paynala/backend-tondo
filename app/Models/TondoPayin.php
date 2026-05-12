<?php

namespace App\Models;

use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;

class TondoPayin extends Model
{
    use UuidPrimary;

    protected $table = 'tondo_payin';

    protected $guarded = ['id'];

    protected $casts = [
        'montant' => 'integer',
        'request' => 'array',
        'response' => 'array',
        'date_creation' => 'datetime',
    ];
}
