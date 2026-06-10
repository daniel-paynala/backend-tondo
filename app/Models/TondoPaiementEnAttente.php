<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TondoPaiementEnAttente extends Model
{
    protected $table      = 'tondo_paiements_en_attente';
    protected $primaryKey = 'trans_id';
    public    $incrementing = false;
    protected $keyType      = 'string';

    const UPDATED_AT = null;

    protected $fillable = [
        'trans_id',
        'numero_wa',
        'project_id',
        'cagnotte_ref',
        'montant',
        'prenom',
        'user_id',
    ];
}
