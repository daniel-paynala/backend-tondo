<?php

namespace App\Models;

use App\Models\Concerns\HasProjectTable;
use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;

/**
 * Journal d'événements applicatifs Tondo (logs métier structurés).
 *
 * Contrairement aux logs Laravel (fichiers texte), cette table stocke des
 * événements métier structurés destinés au dashboard admin : création de
 * cagnotte, paiement, retrait, signalement, etc.
 *
 * Champ `type` : catégorie de l'événement (ex : 'paiement', 'retrait', 'inscription').
 * Champ `metadonnees` : données contextuelles JSON variables selon le type
 * (ex : montant, numéro masqué, référence cagnotte).
 *
 * @property string  $id
 * @property string  $project_id   Isolation multi-tenant.
 * @property ?string $user_id      Utilisateur concerné (null si action système).
 * @property ?string $cagnotte_id  Cagnotte concernée (null si hors contexte).
 * @property string  $type         Catégorie de l'événement.
 * @property ?string $message      Description lisible de l'événement.
 * @property array   $metadonnees  Données contextuelles variables (JSON).
 * @property \Illuminate\Support\Carbon $date Date de l'événement (peut différer de created_at).
 */
class TondoLog extends Model
{
    use UuidPrimary;
    use HasProjectTable;

    protected string $tableSuffix = 'logs';

    /**
     * Pas de colonnes timestamps automatiques — la date métier est le champ `date`,
     * et `created_at` est géré manuellement via le cast ci-dessous.
     */
    public $timestamps = false;

    /** Toutes les colonnes sont mass-assignables sauf la PK. */
    protected $guarded = ['id'];

    protected $casts = [
        'date'        => 'datetime',   // Date effective de l'événement.
        'created_at'  => 'datetime',
        'metadonnees' => 'array',      // Données contextuelles JSON (structure variable).
    ];
}
