<?php

namespace App\Support;

/**
 * Décision PURE de suppression d'un compte (sans DB).
 *
 * Deux régimes, selon l'empreinte financière laissée par le compte :
 *  – **purge** : la ligne `users` est réellement supprimée, avec ses
 *    participations et ses device tokens. Réservée aux comptes qui n'ont laissé
 *    aucune trace comptable — sinon la base se remplit de « Compte supprimé ».
 *  – **anonymisation** : la ligne est conservée, ses champs identifiants
 *    neutralisés. Les paiements et payouts référencent `user_id` et
 *    l'historique doit être préservé.
 *
 * La lecture DB (existence des cagnottes, paiements, payin, payout,
 * participations) reste dans le contrôleur ; ici, uniquement la décision.
 */
class SuppressionCompte
{
    /**
     * Les cinq traces qui interdisent la purge.
     *
     * `paiement_sur_participation` mérite une mention : `paiements.participant_id`
     * est NOT NULL et ON DELETE CASCADE. Supprimer une participation référencée
     * par un paiement détruirait la ligne de paiement. Le chemin est aujourd'hui
     * inatteignable — les deux sites d'insertion résolvent le participant par
     * `user_id` du payeur et incrémentent `montant_paye` dans la même
     * transaction — mais la garde tient l'invariant au point de suppression
     * plutôt qu'aux seuls sites d'appel.
     */
    public const TRACES = [
        'cagnottes',
        'paiements',
        'payin',
        'payout',
        'participation_payee',
        'paiement_sur_participation',
    ];

    /**
     * Vrai si le compte peut être réellement supprimé de la base.
     *
     * Une seule trace suffit à imposer l'anonymisation : on ne cherche pas à
     * pondérer, toute écriture comptable rend la ligne nécessaire.
     *
     * @param  array<string, bool> $empreinte  Présence de chaque trace.
     */
    public static function doitPurger(array $empreinte): bool
    {
        foreach ($empreinte as $presente) {
            if ($presente === true) {
                return false;
            }
        }

        return true;
    }

    /**
     * Vrai si un décaissement empêche la suppression.
     *
     * Un payout non résolu signifie que le solde d'une cagnotte a été décrémenté
     * sans que l'argent soit sorti. Supprimer le compte laisserait les fonds en
     * l'air, en perdant l'identité du bénéficiaire à qui les rendre.
     *
     * Les échecs COMPENSÉS sont exclus : depuis que le solde est restauré sur
     * refus explicite, un `echec` portant `solde_restaure` est une situation
     * close. Bloquer dessus interdirait à jamais la suppression d'un compte
     * ayant connu un seul refus, même ancien. Les `echec` sans ce marqueur
     * datent d'avant la compensation et sont de vrais fonds en l'air.
     *
     * @param  ?string $statut          Statut du payout.
     * @param  ?bool   $soldeRestaure   Marqueur porté par `payout.response`.
     */
    public static function payoutNonResolu(?string $statut, ?bool $soldeRestaure): bool
    {
        if ($statut === 'initie' || $statut === 'en_cours') {
            return true;
        }

        return $statut === 'echec' && $soldeRestaure !== true;
    }
}
