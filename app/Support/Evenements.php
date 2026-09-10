<?php

namespace App\Support;

/**
 * Vocabulaire FERMÉ de la télémétrie produit (sans DB).
 *
 * Une liste blanche plutôt qu'un champ libre : sans elle, une faute de frappe
 * dans un nom d'événement crée silencieusement une nouvelle série, et les
 * entonnoirs du dashboard se trouent sans que personne ne le voie. Le serveur
 * rejette tout nom inconnu.
 *
 * Convention : verbe au passé, minuscules, underscores. On décrit ce qui EST
 * ARRIVÉ, pas ce que l'utilisateur « fait ».
 */
class Evenements
{
    /**
     * Entonnoir de la cotisation — celui de l'argent, le plus important.
     *
     * `cotisation_abandonnee` mérite une mention : elle mesure les personnes qui
     * lâchent pendant les trois minutes d'attente de confirmation Airtel, une
     * perte invisible aujourd'hui parce qu'elle ne laisse aucune trace en base.
     */
    public const COTISATION = [
        'cotisation_ouverte',      // écran de cotisation affiché
        'montant_saisi',           // le champ montant a été rempli (pas sa valeur)
        'cotisation_initiee',      // paiement envoyé à l'opérateur
        'cotisation_confirmee',    // opérateur a confirmé
        'cotisation_echouee',      // opérateur a refusé
        'cotisation_abandonnee',   // écran quitté avant l'issue
    ];

    /** Transverses : rétention et diffusion. */
    public const TRANSVERSES = [
        'app_ouverte',
        'partage',
    ];

    /** Tous les événements acceptés. */
    public static function connus(): array
    {
        return array_merge(self::COTISATION, self::TRANSVERSES);
    }

    /** Vrai si [$nom] fait partie du vocabulaire. */
    public static function estConnu(?string $nom): bool
    {
        return $nom !== null && in_array($nom, self::connus(), true);
    }

    /**
     * Clés de contexte autorisées, par événement.
     *
     * Tout le reste est ÉCARTÉ silencieusement plutôt que refusé : un client
     * plus récent que le serveur ne doit pas voir ses lots rejetés en bloc à
     * cause d'une clé qu'il vient d'ajouter. Mais rien d'inattendu n'entre en
     * base — c'est ce qui garantit qu'aucun contenu saisi ne s'y glisse.
     */
    public const CONTEXTE_AUTORISE = [
        'cotisation_ouverte'    => ['type_cagnotte', 'origine'],
        'montant_saisi'         => ['tranche'],
        'cotisation_initiee'    => ['tranche', 'operateur'],
        'cotisation_confirmee'  => ['tranche', 'secondes_attente'],
        'cotisation_echouee'    => ['motif', 'secondes_attente'],
        'cotisation_abandonnee' => ['secondes_attente', 'etape'],
        'app_ouverte'           => ['premier_lancement'],
        'partage'               => ['canal_partage'],
    ];

    /**
     * Ne conserve du contexte que les clés prévues pour cet événement.
     *
     * @param  array<string, mixed> $contexte
     * @return array<string, mixed>
     */
    public static function filtrerContexte(string $nom, array $contexte): array
    {
        $autorisees = self::CONTEXTE_AUTORISE[$nom] ?? [];

        return array_intersect_key($contexte, array_flip($autorisees));
    }

    /**
     * Range un montant dans une tranche, pour ne jamais stocker sa valeur.
     *
     * Les bornes suivent l'usage réel : le minimum est de 100 FCFA et le
     * plafond par transaction de 500 000. Une tranche suffit à toute analyse
     * d'entonnoir, et sort la donnée financière de la table.
     */
    public static function tranche(int $montant): string
    {
        return match (true) {
            $montant <   1000 => '0-1k',
            $montant <   5000 => '1k-5k',
            $montant <  25000 => '5k-25k',
            $montant < 100000 => '25k-100k',
            default           => '100k+',
        };
    }
}
