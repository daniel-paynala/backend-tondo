<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Paiement WhatsApp initié mais pas encore confirmé par l'opérateur Mobile Money.
 *
 * Cycle de vie :
 *  1. Ligne insérée dès que l'utilisateur WhatsApp valide son paiement Airtel.
 *  2. La commande `tondo:verifier-paiements` (cron 1 min) et le job
 *     `VerifierPaiementJob` (queue) interrogent l'API jusqu'à confirmation.
 *  3. En cas de succès ou d'échec → ligne supprimée, message envoyé à l'utilisateur.
 *  4. Timeout après 4 minutes → ligne supprimée, message de dépassement envoyé.
 *
 * La PK est `trans_id` (identifiant Airtel) et non un UUID auto-généré,
 * ce qui garantit l'unicité par transaction et facilite les lookups.
 *
 * @property string  $trans_id     Identifiant de transaction Airtel (PK).
 * @property string  $numero_wa    Numéro WhatsApp E.164 de l'utilisateur.
 * @property string  $project_id   Isolation multi-tenant.
 * @property string  $cagnotte_ref Référence numérique courte de la cagnotte.
 * @property int     $montant      Montant déclaré par l'utilisateur (FCFA).
 * @property string  $prenom       Prénom affiché dans les messages de confirmation.
 * @property ?string $user_id      FK → tondo_users.id (null si compte light).
 */
class TondoPaiementEnAttente extends Model
{
    protected $table      = 'tondo_paiements_en_attente';

    /** La PK est le trans_id Airtel — pas d'UUID ni d'auto-incrément. */
    protected $primaryKey = 'trans_id';
    public    $incrementing = false;
    protected $keyType      = 'string';

    /** Pas de colonne updated_at sur cette table — les lignes sont courte durée. */
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
