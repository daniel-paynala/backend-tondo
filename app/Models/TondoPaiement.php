<?php

namespace App\Models;

use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;

class TondoPaiement extends Model
{
    use UuidPrimary;

    protected $table = 'tondo_paiements';
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'montant' => 'integer',
        'date' => 'datetime',
        'created_at' => 'datetime',
    ];
}
