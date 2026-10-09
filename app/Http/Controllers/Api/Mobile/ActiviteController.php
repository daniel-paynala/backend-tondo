<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Support\Registre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tout ce qui a bougé pour un utilisateur, collectes confondues.
 *
 * Jusqu'ici chaque historique était attaché à UNE collecte : pour savoir où
 * était passé son argent, il fallait les ouvrir une à une. L'écran du solde est
 * précisément celui où l'on vient poser cette question, et la réponse doit être
 * entière — d'où cette vue d'ensemble.
 *
 * Trois sources, réunies et triées par date :
 *
 *   − ce que j'ai versé    mes cotisations, où qu'elles soient allées
 *   + ce que j'ai reçu     les cotisations arrivées dans mes collectes
 *   − ce qui est sorti     les reversements et paiements de mes collectes
 *
 * Le signe est celui de l'utilisateur, pas celui d'une table : une cotisation
 * que je verse est une sortie pour moi, alors qu'elle est une entrée pour la
 * collecte qui la reçoit.
 */
class ActiviteController extends Controller
{
    /** Nombre de lignes rendues — un relevé complet vivra dans son propre écran. */
    private const LIMITE = 60;

    /** GET /api/mobile/activite */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Titre ET type : le type dit lequel de ces contenants est le solde,
        // et un mouvement parti du solde n'a pas à l'annoncer — c'est le cas
        // ordinaire. C'est l'autre qui mérite d'être signalé.
        $miennes = DB::table(project_table('cagnottes'))
            ->where('user_id', $user->id)
            ->get(['id', 'titre', 'type'])
            ->keyBy('id');

        $mouvements = collect()
            ->merge($this->cotisationsVersees($user, $miennes))
            ->merge($this->cotisationsRecues($user, $miennes))
            ->merge($this->sorties($miennes))
            ->sortByDesc('date')
            ->take(self::LIMITE)
            ->values();

        return response()->json(['mouvements' => $mouvements]);
    }

    /** Ce que l'utilisateur a versé — à ses collectes comme à celles des autres. */
    private function cotisationsVersees(object $user, $miennes): array
    {
        $paiements = project_table('paiements');
        $cagnottes = project_table('cagnottes');

        return DB::table("{$paiements} as p")
            ->leftJoin("{$cagnottes} as c", 'c.id', '=', 'p.cagnotte_id')
            ->where('p.user_id', $user->id)
            ->where(fn ($q) => $q->where('p.actif', true)->orWhereNull('p.actif'))
            ->orderByDesc('p.date')
            ->limit(self::LIMITE)
            ->get(['p.id', 'p.montant', 'p.date', 'p.trans_id', 'c.titre', 'c.type'])
            ->map(fn ($l) => [
                'id'      => (string) $l->id,
                'action'  => ($l->type ?? '') === 'wallet' ? 'recharge' : 'cotisation',
                'libelle' => ($l->type ?? '') === 'wallet'
                    ? 'Recharge du solde'
                    : 'Cotisation — ' . ($l->titre ?? 'collecte'),
                'depuis'  => null,
                'montant' => (int) $l->montant,
                'sens'    => 'sortie',
                'date'    => $l->date,
                'reference' => $l->trans_id ? (Registre::court($l->trans_id) ?? $l->trans_id) : null,
            ])
            ->all();
    }

    /**
     * Ce que ses collectes ont reçu des AUTRES.
     *
     * Ses propres versements en sont exclus : ils sont déjà comptés au-dessus,
     * et les montrer deux fois ferait croire à un aller-retour.
     */
    private function cotisationsRecues(object $user, $miennes): array
    {
        if ($miennes->isEmpty()) {
            return [];
        }

        $paiements = project_table('paiements');

        return DB::table("{$paiements} as p")
            ->whereIn('p.cagnotte_id', $miennes->keys())
            ->where(fn ($q) => $q->where('p.user_id', '<>', $user->id)
                                 ->orWhereNull('p.user_id'))
            ->where(fn ($q) => $q->where('p.actif', true)->orWhereNull('p.actif'))
            ->orderByDesc('p.date')
            ->limit(self::LIMITE)
            ->get(['p.id', 'p.montant', 'p.date', 'p.trans_id', 'p.cagnotte_id'])
            ->map(fn ($l) => [
                'id'      => (string) $l->id,
                'action'  => 'cotisation',
                'libelle' => 'Cotisation reçue — '
                    . ($miennes[$l->cagnotte_id]->titre ?? 'collecte'),
                'depuis'  => null,
                'montant' => (int) $l->montant,
                'sens'    => 'entree',
                'date'    => $l->date,
                'reference' => $l->trans_id ? (Registre::court($l->trans_id) ?? $l->trans_id) : null,
            ])
            ->all();
    }

    /** Nom lisible du bénéficiaire d'un transfert, à défaut « un numéro ». */
    private function nomBeneficiaire(object $ligne): string
    {
        $nom = trim(mb_strtoupper((string) ($ligne->benef_nom ?? '')) . ' '
            . ucfirst(mb_strtolower((string) ($ligne->benef_prenom ?? ''))));

        return $nom === '' ? 'un numéro' : $nom;
    }

    /** Ce qui est sorti de ses collectes — transfert, paiement, retrait. */
    private function sorties($miennes): array
    {
        if ($miennes->isEmpty()) {
            return [];
        }

        $payout    = project_table('payout');
        $marchands = project_table('marchands');

        return DB::table("{$payout} as o")
            // Le bénéficiaire d'un transfert, quand il a un compte Tonji : un
            // nom se reconnaît, un numéro masqué beaucoup moins.
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            // Le nom de l'enseigne réglée. Un paiement marchand annoncé au nom
            // de la collecte débitée — « Paiement — Anniversaire maman » — ne
            // dit pas À QUI l'argent est allé, et c'est précisément ce qu'on
            // vient chercher dans un relevé.
            ->leftJoin("{$marchands} as m", 'm.id', '=', 'o.marchand_id')
            ->whereIn('o.cagnotte_id', $miennes->keys())
            // Une sortie refusée n'a rien déplacé : elle n'a pas sa place dans
            // un relevé, où elle se lirait comme de l'argent parti.
            ->whereIn('o.statut', ['initie', 'en_cours', 'succes'])
            ->orderByDesc('o.date_creation')
            ->limit(self::LIMITE)
            ->get(['o.id', 'o.montant', 'o.date_creation', 'o.trans_id',
                   'o.cagnotte_id', 'o.type_beneficiaire', 'o.statut',
                   'm.nom as marchand_nom', 'u.nom as benef_nom',
                   'u.prenom as benef_prenom'])
            ->map(fn ($l) => [
                'id'      => (string) $l->id,
                'action'  => ($l->type_beneficiaire ?? '') === 'marchand'
                    ? 'paiement'
                    : 'transfert',
                'libelle' => ($l->type_beneficiaire ?? '') === 'marchand'
                    ? 'Paiement · ' . ($l->marchand_nom ?? 'commerce')
                    : 'Transfert · ' . $this->nomBeneficiaire($l),
                // D'où l'argent est parti, et seulement quand ce n'est PAS le
                // solde : payer un commerce depuis une collecte engage l'argent
                // des cotisants, pas le sien. C'est ce qu'il faut voir.
                'depuis'  => ($miennes[$l->cagnotte_id]->type ?? '') === 'wallet'
                    ? null
                    : ($miennes[$l->cagnotte_id]->titre ?? null),
                'montant' => (int) $l->montant,
                'sens'    => 'sortie',
                'date'    => $l->date_creation,
                'reference' => $l->trans_id ? (Registre::court($l->trans_id) ?? $l->trans_id) : null,
                // En cours : le relevé le dit, sinon l'utilisateur croit son
                // argent arrivé alors qu'il est encore en vol.
                'en_cours' => $l->statut !== 'succes',
            ])
            ->all();
    }
}
