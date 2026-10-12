<?php

namespace App\Support;

/**
 * Garde de collecte PURE : décide si une cotisation est autorisée (sans DB).
 *
 * Ferme trois trous que le gate mobile ne couvrait pas :
 *  – une cagnotte PUBLIQUE ne collecte qu'une fois VALIDÉE par la modération ;
 *  – une ASSOCIATION ne collecte que si son dossier est APPROUVÉ (bloque en
 *    attente / rejetée / suspendue → la suspension coupe la collecte) ;
 *  – une cotisation ouverte dont la DATE DE FIN est passée ne collecte plus.
 *    Cette règle n'existait que côté app Flutter, donc uniquement à l'écran :
 *    le bot WhatsApp, l'USSD et tondo-web laissaient tous passer une
 *    cotisation après l'échéance.
 *
 * S'y ajoute le VERROU ADMINISTRATIF, pendant à l'entrée de ce que
 * {@see \App\Services\SortiesAutorisees} fait à la sortie : couper les
 * cotisations d'une cagnotte précise, ou de tout un type de compte, sans avoir
 * à clôturer quoi que ce soit.
 *
 * La lecture DB (gérant, orga, verrous) reste dans les contrôleurs ; ici,
 * uniquement la décision.
 */
class CollecteGuard
{
    /**
     * @return ?string  message de blocage, ou null si la collecte est autorisée.
     */
    public static function bloquee(
        ?string $visibilite,
        ?string $statutValidation,
        ?string $typeCompteGerant,
        ?string $orgStatut,
        ?string $typeCagnotte = null,
        ?string $dateFin = null,
        bool $verrouCagnotte = false,
        bool $verrouGlobal = false,
    ): ?string {
        // ── Verrou administratif ────────────────────────────────────────────
        //
        // En TÊTE, avant les règles automatiques : quand un administrateur a
        // coupé la collecte, c'est cette raison-là qu'il faut rendre, pas
        // « en cours de validation » ni « association inactive ». Le cotisant
        // appellerait le support pour le mauvais motif.
        //
        // Règle OU, comme pour les sorties : bloqué si le verrou de la
        // cagnotte OU celui du type de compte l'interdit. Un ET laisserait un
        // réglage de cagnotte rouvrir ce qu'une décision globale vient de
        // fermer — au moment précis où elle sert le plus.
        if ($verrouCagnotte || $verrouGlobal) {
            return 'Les cotisations ont été suspendues pour cette cagnotte. Veuillez contacter le support.';
        }

        // Cagnotte publique : doit être approuvée par la modération.
        if ($visibilite === 'public' && $statutValidation !== 'approuvee') {
            return 'Cette cagnotte publique est en cours de validation.';
        }

        // Association gérante : dossier approuvé requis (bloque en_attente / rejete / suspendu).
        if ($typeCompteGerant === 'association' && $orgStatut !== 'approuve') {
            return 'Collecte indisponible : l\'association n\'est pas active.';
        }

        // Date de fin dépassée — cotisations ouvertes uniquement : une tontine
        // n'a pas d'échéance de collecte, ses cycles la rythment.
        //
        // La comparaison se fait à la fin du jour indiqué : `date_fin` est une
        // DATE, et une échéance « au 10 » doit rester ouverte tout le 10.
        if ($typeCagnotte === 'cagnotte_ouverte' && $dateFin !== null && $dateFin !== '') {
            $limite = strtotime($dateFin . ' 23:59:59');
            if ($limite !== false && time() > $limite) {
                return 'Cette collecte est terminée : la date de fin est dépassée.';
            }
        }

        return null;
    }
}
