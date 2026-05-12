<?php

namespace App\Models;

use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;

class TondoPayoutPaynala extends Model
{
    use UuidPrimary;

    protected $table = 'tondo_payout_paynala';

    protected $guarded = ['id'];

    protected $casts = [
        'montant' => 'integer',
        'request' => 'array',
        'response' => 'array',
        'date_creation' => 'datetime',
    ];
}
