<?php

namespace App\Services;

use App\Models\TondoUser;

/**
 * Ce qu'un compte suspendu ne peut plus faire.
 *
 * La suspension vise **la personne**, et pas seulement ses collectes. Elle
 * ferme donc trois choses, qu'il faut distinguer :
 *
 *  1. ses propres collectes n'encaissent ni ne sortent plus — c'est
 *     {@see SortiesAutorisees} et {@see CollecteAutorisee}, qui regardent le
 *     GÉRANT de la collecte visée ;
 *  2. elle ne peut plus **cotiser ailleurs**, chez quelqu'un d'autre ;
 *  3. elle ne peut plus **créer** de collecte — sinon la mesure se contourne
 *     en ouvrant une cagnotte neuve, qui n'est pas encore verrouillée.
 *
 * Ce service porte les points 2 et 3 : ils regardent l'AUTEUR du geste, pas la
 * collecte. Les confondre était le piège — une suspension qui ne ferme que les
 * collectes existantes laisse la porte grande ouverte.
 *
 * Un seul endroit, parce que cinq chemins en dépendent : l'app, le web, le bot
 * WhatsApp, l'USSD et le mouvement interne depuis le solde.
 */
class CompteSuspendu
{
    /** Message de refus si ce compte est suspendu, null sinon. */
    public function refus(?object $user): ?string
    {
        if ($user === null) {
            return null;
        }

        // L'utilisateur authentifié porte déjà la colonne : pas de requête.
        return $user->suspendu_at === null ? null : self::MESSAGE;
    }

    /**
     * Même règle, quand on n'a que l'identifiant.
     *
     * Le mouvement interne ne connaît que le titulaire du solde source, par
     * son `user_id` — aller chercher la ligne est le prix à payer, et c'est
     * une lecture par clé primaire.
     */
    public function refusParId(?string $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        return TondoUser::query()->whereKey($userId)->value('suspendu_at') === null
            ? null
            : self::MESSAGE;
    }

    /**
     * Le message parle de SON compte, à la différence de celui des collectes :
     * ici la personne est concernée directement.
     */
    private const MESSAGE = 'Votre compte est suspendu. Veuillez contacter le support.';
}
