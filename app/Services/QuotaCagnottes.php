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
     * son argent est sorti. Le `wallet` est exclu plus bas — c'est un solde
     * personnel, pas une collecte, et il est unique par personne de toute
     * façon.
     */
    private const STATUTS_ACTIFS = ['active', 'en_cours'];

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
            ->where('type', '!=', 'wallet')
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
