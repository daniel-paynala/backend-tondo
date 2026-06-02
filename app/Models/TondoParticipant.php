<?php

namespace App\Models;

use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;

class TondoParticipant extends Model
{
    use UuidPrimary;

    protected $table = 'tondo_participants';
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'montant_paye'          => 'integer',
        'ordre_passage'         => 'integer',
        'date_dernier_paiement' => 'datetime',
        'created_at'            => 'datetime',
        'est_compte_light'      => 'boolean',
    ];
}
