<?php

namespace App\Models;

use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TondoCagnotte extends Model
{
    use UuidPrimary;

    protected $table = 'tondo_cagnottes';

    protected $guarded = ['id'];

    protected $casts = [
        'montant_collecte' => 'integer',
        'montant_beneficiaire' => 'integer',
        'montant_avec_frais' => 'integer',
        'total_a_envoyer' => 'integer',
        'montant_cible' => 'integer',
        'montant_par_cycle' => 'integer',
        'nombre_participants' => 'integer',
        'nombre_splits' => 'integer',
        'nombre_envois' => 'integer',
        'intervalle' => 'integer',
        'jour_mois' => 'integer',
        'date_creation'  => 'datetime',
        'date_fin'       => 'datetime',
        'date_demarrage' => 'datetime',
        'reversement_auto' => 'boolean',
        'reversement_auto_frequence_mois' => 'integer',
    ];

    public function gerant(): BelongsTo
    {
        return $this->belongsTo(TondoUser::class, 'user_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(TondoParticipant::class, 'cagnotte_id');
    }
}
