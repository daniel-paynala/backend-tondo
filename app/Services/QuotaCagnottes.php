<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Combien de collectes actives un même organisateur peut tenir.
 *
 * **Un seul endroit décide**, comme pour les verrous : l'app et le bot
 * WhatsApp créent tous deux des collectes, et recopier le compte garantirait
 * qu'un canal laisse passer la sixième.
 *
 * Le quota protège d'abord l'organisateur de lui-même — une tontine demande du
 * suivi, et dix collectes ouvertes en parallèle finissent abandonnées avec de
 * l'argent dedans — et accessoirement la plateforme, car multiplier les
 * collectes est le moyen le plus simple de diluer un plafond.
 */
class QuotaCagnottes
{
    /**
     * Statuts qui comptent dans le quota.
     *
     * Une collecte clôturée ne pèse plus : elle ne demande plus de suivi et
     * son argent est sorti.
     */
    private const STATUTS_ACTIFS = ['active', 'en_cours'];

    /**
     * Types qui comptent dans le quota, NOMMÉS plutôt qu'exclus.
     *
     * On écrivait `type != 'wallet'`, ce qui a cassé la production : la
     * colonne est une énumération, et `wallet` n'y existe pas tant que le
     * solde personnel n'est pas déployé. Postgres refuse la comparaison
     * entière (22P02), et c'est toute la liste des collectes qui tombait.
     *
     * Lister ce qu'on veut, plutôt qu'écarter ce qu'on ne veut pas, ne
     * dépend d'aucune valeur qui pourrait manquer — et dit mieux l'intention :
     * le quota porte sur les COLLECTES, pas sur les contenants.
     */
    private const TYPES_COMPTES = ['cagnotte_ouverte', 'tontine_periodique'];

    public function __construct(private readonly TondoConfigService $config) {}

    /** Limite en vigueur pour ce projet. */
    public function limite(string $projectId): int
    {
        return (int) ($this->config->getOperatorConfig($projectId)['max_cagnottes_actives'] ?? 5);
    }

    /** Collectes actives déjà tenues par cet organisateur. */
    public function actives(string $userId, string $projectId): int
    {
        return DB::table(project_table('cagnottes'))
            ->where('project_id', $projectId)
            ->where('user_id', $userId)
            ->whereIn('statut', self::STATUTS_ACTIFS)
            // Le solde personnel ne compte pas : ce n'est pas une collecte,
            // et il est unique par personne de toute façon. Il est écarté en
            // ne le nommant pas, voir TYPES_COMPTES.
            ->whereIn('type', self::TYPES_COMPTES)
            ->count();
    }

    /**
     * Message de refus si le quota est atteint, null sinon.
     *
     * Le message DIT LA LIMITE. « Vous avez atteint le maximum » laisse
     * chercher ; donner le chiffre et la sortie — clôturer une collecte —
     * évite un appel au support.
     */
    public function refus(string $userId, string $projectId): ?string
    {
        $limite = $this->limite($projectId);

        if ($this->actives($userId, $projectId) < $limite) {
            return null;
        }

        return "Vous tenez déjà {$limite} collectes actives, le maximum. "
            . 'Clôturez-en une pour pouvoir en créer une nouvelle.';
    }
}
