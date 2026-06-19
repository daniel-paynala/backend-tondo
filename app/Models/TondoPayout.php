<?php

namespace App\Models;

use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;

/**
 * Piste d'audit technique des appels payout (décaissement Mobile Money sortant).
 *
 * Chaque ligne correspond à un virement émis vers un bénéficiaire : retrait
 * de tontine (cycle terminé), reversement automatique d'une cagnotte ouverte,
 * ou remboursement. Les champs `request` / `response` conservent le payload
 * brut pour le débogage et les rapprochements.
 *
 * Politique en cas d'échec Paynala : le solde en base a déjà été décrémenté
 * lors de la réservation (Phase 1). Si l'appel Paynala échoue (Phase 2),
 * le statut passe à 'echec' mais le solde n'est **pas** restauré
 * automatiquement — un admin doit intervenir manuellement.
 *
 * @property string $id
 * @property string $project_id    Isolation multi-tenant.
 * @property string $cagnotte_id   FK → tondo_cagnottes.id
 * @property ?string $user_id      FK → tondo_users.id (bénéficiaire, null si compte light).
 * @property string $trans_id      Identifiant interne Tondo (ex : 'TONDOPAYOUTXXXXXXX').
 * @property ?string $operateur_id Identifiant de transaction côté Airtel Money.
 * @property string $numero_tel    Numéro E.164 du bénéficiaire.
 * @property int    $montant       Montant décaissé (FCFA).
 * @property string $statut        'initie'|'succes'|'echec'
 * @property array  $request       Payload envoyé à l'API Paynala (JSON).
 * @property array  $response      Réponse reçue de l'API Paynala (JSON).
 */
class TondoPayout extends Model
{
    use UuidPrimary;

    /** Table d'audit des décaissements sortants. */
    protected $table = 'tondo_payout';

    /** Toutes les colonnes sont mass-assignables sauf la PK. */
    protected $guarded = ['id'];

    protected $casts = [
        'montant'       => 'integer',
        'request'       => 'array',   // Payload brut envoyé à Paynala.
        'response'      => 'array',   // Réponse brute reçue de Paynala.
        'date_creation' => 'datetime',
    ];
}
