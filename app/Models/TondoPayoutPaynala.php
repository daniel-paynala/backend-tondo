<?php

namespace App\Models;

use App\Models\Concerns\HasProjectTable;
use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;

/**
 * Piste d'audit des commissions prélevées par Paynala sur les décaissements.
 *
 * Distinct de `tondo_payout` (décaissement vers le bénéficiaire final) :
 * cette table enregistre le mouvement comptable correspondant à la part
 * que Paynala conserve (commission + frais opérateur), pour la réconciliation
 * financière et le reporting back-office.
 *
 * Les champs `request` / `response` contiennent le payload brut échangé avec
 * l'API interne Paynala lors de l'écriture de cette commission.
 *
 * @property string  $id
 * @property string  $project_id    Isolation multi-tenant.
 * @property string  $payout_id     FK → tondo_payout.id (décaissement parent).
 * @property int     $montant       Montant de la commission Paynala (FCFA).
 * @property string  $statut        'initie'|'succes'|'echec'
 * @property array   $request       Payload envoyé à l'API de commission (JSON).
 * @property array   $response      Réponse reçue de l'API de commission (JSON).
 */
class TondoPayoutPaynala extends Model
{
    use UuidPrimary;
    use HasProjectTable;

    /** Table d'audit des commissions Paynala sur les décaissements. */
    protected string $tableSuffix = 'payout_paynala';

    /** Toutes les colonnes sont mass-assignables sauf la PK. */
    protected $guarded = ['id'];

    protected $casts = [
        'montant'       => 'integer',
        'request'       => 'array',   // Payload envoyé pour enregistrer la commission.
        'response'      => 'array',   // Réponse de l'API de commission Paynala.
        'date_creation' => 'datetime',
    ];
}
