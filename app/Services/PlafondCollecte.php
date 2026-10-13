<?php

namespace App\Services;

use App\Models\TondoOrganisation;
use App\Models\TondoUser;
use App\Support\PlafondResolver;

/**
 * Jusqu'où une collecte peut collecter.
 *
 * **Un seul endroit décide** — le troisième service de cette famille, après
 * {@see SortiesAutorisees} et {@see CollecteAutorisee}, et pour la même
 * raison : le contrôleur mobile et le service WhatsApp portaient chacun leur
 * copie du contrôle ET leur copie de la résolution du plafond, avec deux
 * messages écrits séparément. Corriger l'un laissait l'autre mentir.
 *
 * **Deux assiettes, selon le type de collecte** (décision de Daniel,
 * 2026-10-13) :
 *
 *  – **cagnotte ouverte → le CUMUL** (`cumul_cotisations`), qui ne redescend
 *    jamais. Le solde ne pouvait pas servir : il baisse à chaque reversement,
 *    si bien qu'il suffisait de collecter 2,5 M, de transférer, et de
 *    recommencer — autant de fois qu'on voulait. Le plafond était décoratif
 *    pour quiconque retire son argent.
 *
 *  – **tontine périodique ET solde personnel → le SOLDE**
 *    (`montant_collecte`), comme avant. Ni l'une ni l'autre n'accumule : les
 *    deux font circuler, l'argent entre et ressort. Un plafond à vie
 *    bloquerait définitivement une tontine de 500 000 par cycle au bout de
 *    cinq mois, et interdirait de recharger un solde personnel passé 2,5 M
 *    cumulés — même vide. Ce qu'on borne pour eux, c'est ce qu'ils
 *    détiennent.
 */
class PlafondCollecte
{
    /**
     * Plafond applicable à cette collecte, selon le compte de son gérant.
     */
    public function plafond(mixed $cagnotte, array $config): int
    {
        $gerant = TondoUser::find($cagnotte->user_id);

        if ($gerant && $gerant->type_compte === 'association') {
            $org = TondoOrganisation::query()->where('user_id', $gerant->id)->first();

            return PlafondResolver::resoudre(
                'association',
                $org?->plafond_fcfa !== null ? (int) $org->plafond_fcfa : null,
                null,
                $config,
            );
        }

        return PlafondResolver::resoudre(
            $gerant?->type_compte,
            null,
            $gerant?->plafond_personnalise !== null ? (int) $gerant->plafond_personnalise : null,
            $config,
        );
    }

    /**
     * Montant déjà collecté qui compte pour le plafond.
     *
     * Public parce que l'API le renvoie aux clients : la barre de progression
     * doit se baser sur la même valeur que le contrôle, sinon elle promet ce
     * que le serveur refusera.
     */
    public function assiette(mixed $cagnotte): int
    {
        // Tontine et solde personnel : on borne ce qu'ils DÉTIENNENT.
        //
        // Ni l'un ni l'autre n'accumule, les deux font circuler. Un plafond à
        // vie sur un solde personnel interdirait de le recharger passé 2,5 M
        // cumulés — même vide. Sur une tontine, il la bloquerait au cinquième
        // cycle. Seule la cagnotte ouverte accumule vraiment, et c'est la
        // seule que son cumul borne.
        $circulant = in_array($cagnotte->type ?? null, ['tontine_periodique', 'wallet'], true);

        return $circulant
            ? (int) ($cagnotte->montant_collecte ?? 0)
            : (int) ($cagnotte->cumul_cotisations ?? 0);
    }

    /**
     * Message de refus si ce montant ferait dépasser, null sinon.
     *
     * Le message dit **ce qu'il reste**, et non le plafond.
     * « Cette cagnotte a atteint son plafond de 2 500 000 FCFA » était faux
     * quand elle était à 2 450 000, et ne disait pas quel montant passerait :
     * le cotisant réessayait au hasard ou appelait le support.
     */
    public function refus(mixed $cagnotte, int $montant, array $config): ?string
    {
        $plafond  = $this->plafond($cagnotte, $config);
        $assiette = $this->assiette($cagnotte);

        if ($assiette + $montant <= $plafond) {
            return null;
        }

        $reste = max(0, $plafond - $assiette);

        if ($reste === 0) {
            return 'Cette collecte a atteint son plafond de '
                . number_format($plafond, 0, ',', ' ') . ' FCFA.';
        }

        return 'Montant trop élevé : il reste ' . number_format($reste, 0, ',', ' ')
            . ' FCFA à collecter sur cette cagnotte.';
    }
}
