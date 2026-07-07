<?php

namespace App\Models;

use App\Models\Concerns\HasProjectTable;
use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Signalement soumis par un utilisateur concernant une cagnotte ou tontine.
 *
 * Paynala ne tranche pas les litiges entre membres (règle non négociable v1),
 * mais collecte les signalements pour intervention manuelle dans les cas
 * manifestement clairs (ex : arnaque avérée, cagnotte abandonnée).
 *
 * Champ `type` : catégorie du signalement (ex : 'fraude', 'abandon', 'erreur').
 * Champ `statut` : 'ouvert' | 'en_cours' | 'resolu' | 'rejete'.
 * Champ `resolu_le` : date de clôture du signalement par un admin.
 *
 * @property string  $id
 * @property string  $project_id   Isolation multi-tenant.
 * @property string  $cagnotte_id  FK → tondo_cagnottes.id
 * @property string  $user_id      Utilisateur qui a soumis le signalement.
 * @property string  $type         Catégorie du problème signalé.
 * @property string  $message      Description du problème fournie par l'utilisateur.
 * @property string  $statut       État de traitement par l'équipe Tondo.
 * @property ?string $resolution   Notes internes sur la résolution (admin uniquement).
 * @property \Illuminate\Support\Carbon $date_creation Date du signalement.
 * @property ?\Illuminate\Support\Carbon $resolu_le     Date de clôture.
 */
class TondoSignalement extends Model
{
    use UuidPrimary;
    use HasProjectTable;

    protected string $tableSuffix = 'signalements';

    /** Toutes les colonnes sont mass-assignables sauf la PK. */
    protected $guarded = ['id'];

    protected $casts = [
        'date_creation' => 'datetime',
        'resolu_le'     => 'datetime',  // Null tant que le signalement n'est pas clôturé.
    ];

    /**
     * Retourne la cagnotte ou tontine concernée par ce signalement.
     */
    public function cagnotte(): BelongsTo
    {
        return $this->belongsTo(TondoCagnotte::class, 'cagnotte_id');
    }
}
