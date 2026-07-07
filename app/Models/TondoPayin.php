<?php

namespace App\Models;

use App\Models\Concerns\HasProjectTable;
use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;

/**
 * Piste d'audit technique des appels payin (encaissement Mobile Money entrant).
 *
 * Chaque ligne correspond à un appel effectué vers l'API Airtel (ou autre
 * agrégateur) pour initier ou vérifier un encaissement. Les champs `request`
 * et `response` conservent le payload brut JSON pour le débogage et les
 * rapprochements avec l'agrégateur.
 *
 * Distinction avec `tondo_paiements` :
 *  – `tondo_payin` : couche technique/audit (payload API brut).
 *  – `tondo_paiements` : couche métier (paiement validé, visible dans le dashboard).
 *
 * @property string $id
 * @property string $project_id    Isolation multi-tenant.
 * @property string $cagnotte_id   FK → tondo_cagnottes.id
 * @property string $user_id       FK → tondo_users.id (payeur).
 * @property string $trans_id      Identifiant de transaction côté agrégateur.
 * @property int    $montant       Montant de la transaction (FCFA).
 * @property string $statut        'initie'|'succes'|'echec'
 * @property array  $request       Payload envoyé à l'API (stocké en JSON).
 * @property array  $response      Réponse reçue de l'API (stocké en JSON).
 */
class TondoPayin extends Model
{
    use UuidPrimary;
    use HasProjectTable;

    /** Table d'audit des encaissements entrants. */
    protected string $tableSuffix = 'payin';

    /** Toutes les colonnes sont mass-assignables sauf la PK. */
    protected $guarded = ['id'];

    protected $casts = [
        'montant'       => 'integer',
        'request'       => 'array',   // Payload brut envoyé à l'agrégateur.
        'response'      => 'array',   // Réponse brute reçue de l'agrégateur.
        'date_creation' => 'datetime',
    ];
}
