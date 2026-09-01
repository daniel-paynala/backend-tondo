<?php

namespace App\Models;

use App\Models\Concerns\HasProjectTable;
use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande de DÉBLOCAGE de plafond (self-service, associations).
 *
 * Une association qui veut collecter au-delà de son plafond (10 M par défaut,
 * loi n°35/62) dépose un justificatif depuis son profil. La demande est
 * examinée dans le dashboard : l'admin fixe le **plafond accordé** (met à jour
 * `tonji_organisations.plafond_fcfa`) ou la refuse avec un motif.
 *
 * @property string  $id
 * @property string  $project_id
 * @property string  $user_id             Demandeur (représentant de l'asso).
 * @property ?int    $montant_demande     Montant souhaité (indicatif).
 * @property string  $justificatif_chemin Chemin du fichier dans le bucket Supabase.
 * @property string  $statut              'en_attente' | 'approuve' | 'rejete'.
 * @property ?string $motif               Raison affichée si rejetée.
 * @property ?int    $plafond_accorde     Plafond fixé par l'admin si approuvée.
 */
class TondoPlafondDemande extends Model
{
    use UuidPrimary;
    use HasProjectTable;

    /** Table des demandes de déblocage (préfixe résolu : tondo_ / tonji_). */
    protected string $tableSuffix = 'plafond_demandes';

    /** Toutes les colonnes sont mass-assignables sauf la PK. */
    protected $guarded = ['id'];

    /** Conversions de types. */
    protected $casts = [
        'montant_demande'     => 'integer',
        'plafond_accorde'     => 'integer',
        'justificatif_taille' => 'integer',
    ];

    /** Le compte demandeur (représentant de l'association). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(TondoUser::class, 'user_id');
    }
}
