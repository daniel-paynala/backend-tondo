<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Qui a le droit de faire sortir l'argent d'une collecte, et pour quoi.
 *
 * **Un seul endroit décide.** Le verrou n'existe que parce que le serveur
 * refuse l'opération : masquer un bouton ne protège rien, l'application n'est
 * pas un endroit sûr. Tout chemin qui déplace de l'argent passe donc par ici —
 * app, web, WhatsApp, USSD — plutôt que de recopier la règle, car un canal
 * finirait par l'oublier. C'est exactement ce qui s'était produit avec la
 * vérification du numéro de retrait, que le bot WhatsApp ne faisait pas.
 *
 * **La règle est un OU** : une sortie est bloquée si le verrou global du type
 * de compte OU celui de la collecte l'interdit. Un ET permettrait à un réglage
 * de collecte de rouvrir ce qu'une décision globale vient de fermer, au moment
 * précis où elle sert le plus.
 */
class SortiesAutorisees
{
    public function __construct(private readonly TondoConfigService $config) {}

    /**
     * État des sorties pour une collecte.
     *
     * Trois valeurs et non deux. `marchand_actif` dit si le SERVICE est ouvert
     * pour ce type de compte, indépendamment de cette collecte-ci :
     *
     *   - service fermé   → les interfaces ne CONSTRUISENT pas le bouton
     *     « Payer ». Ce n'est pas « momentanément suspendu », ça n'existe pas.
     *   - service ouvert mais collecte verrouillée → bouton présent et grisé,
     *     avec la raison. Celui qui l'a vu hier doit comprendre pourquoi il ne
     *     marche plus aujourd'hui.
     *
     * C'est ce qui remplace l'ancien drapeau de compilation : l'interrupteur du
     * dashboard ferme le service partout, sans rebuild ni variable d'environnement.
     *
     * @return array{transfert: bool, marchand: bool, marchand_actif: bool}
     */
    public function pour(string $cagnotteId, string $projectId): array
    {
        $cagnotte = DB::table(project_table('cagnottes'))
            ->where('id', $cagnotteId)
            ->first(['user_id', 'transfert_bloque', 'paiement_marchand_bloque']);

        if ($cagnotte === null) {
            // Collecte inconnue : on refuse tout. Autoriser par défaut ferait
            // d'une référence erronée un contournement du verrou.
            return ['transfert' => false, 'marchand' => false, 'marchand_actif' => false];
        }

        $global = $this->global($projectId, $this->typeCompte($cagnotte->user_id));

        return [
            'transfert'      => ! $cagnotte->transfert_bloque && ! $global['transfert'],
            'marchand'       => ! $cagnotte->paiement_marchand_bloque && ! $global['marchand'],
            'marchand_actif' => ! $global['marchand'],
        ];
    }

    /**
     * Refus prêt à renvoyer, ou null quand l'action est permise.
     *
     * Le `code` est stable et lisible par le client : l'app distingue un verrou
     * d'une panne, et peut dire « cette collecte est momentanément bloquée »
     * plutôt qu'« une erreur est survenue ».
     *
     * @param  'transfert'|'marchand' $action
     * @return array{message: string, code: string}|null
     */
    public function refus(string $cagnotteId, string $projectId, string $action): ?array
    {
        if ($this->pour($cagnotteId, $projectId)[$action] ?? false) {
            return null;
        }

        return $action === 'marchand'
            ? [
                'message' => 'Le paiement d\'un commerce est momentanément indisponible pour cette collecte.',
                'code'    => 'paiement_marchand_bloque',
            ]
            : [
                'message' => 'Le transfert est momentanément indisponible pour cette collecte.',
                'code'    => 'transfert_bloque',
            ];
    }

    /**
     * Verrous globaux du type de compte.
     *
     * @return array{transfert: bool, marchand: bool}
     */
    private function global(string $projectId, string $typeCompte): array
    {
        $cfg = $this->config->getOperatorConfig($projectId);
        $par = $cfg['sorties_bloquees'][$typeCompte] ?? [];

        return [
            'transfert' => (bool) ($par['transfert'] ?? false),
            'marchand'  => (bool) ($par['marchand'] ?? false),
        ];
    }

    /**
     * « association » ou « particulier » — le découpage des verrous globaux.
     *
     * Même source que partout ailleurs dans le produit : `users.type_compte`.
     * Tout ce qui n'est pas explicitement une association compte comme un
     * particulier, y compris un propriétaire introuvable — c'est le cas par
     * défaut du produit, et il vaut mieux qu'un verrou s'applique à tort qu'il
     * se lève par accident.
     */
    private function typeCompte(?string $userId): string
    {
        if ($userId === null) {
            return 'particulier';
        }

        $type = DB::table('users')->where('id', $userId)->value('type_compte');

        return $type === 'association' ? 'association' : 'particulier';
    }
}
