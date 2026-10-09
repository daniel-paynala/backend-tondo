<?php

namespace App\Http\Controllers\Api\Marchand;

use App\Http\Controllers\Controller;
use App\Support\MessagePaiementMarchand;
use App\Support\Registre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Suivi des encaissements d'un numéro marchand.
 *
 * Le périmètre est le **numéro**, pas la fiche : c'est le numéro qui a reçu
 * l'argent, et c'est sur lui que le marchand rapproche son solde Airtel.
 * Quand plusieurs établissements le partagent, chaque ligne dit lequel a été
 * payé — le total, lui, est celui du numéro.
 *
 * **Les échecs et les paiements en cours sont renvoyés avec les succès.** Une
 * liste qui ne montrerait que ce qui a abouti rendrait la réconciliation
 * impossible : c'est justement l'écart entre ce qu'on attendait et ce qui est
 * arrivé qu'on vient chercher ici.
 */
class SuiviController extends Controller
{
    /** Fenêtre par défaut, en jours, quand aucune période n'est demandée. */
    private const FENETRE_DEFAUT = 30;

    /** GET /api/marchand/transactions */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'depuis'  => ['sometimes', 'date'],
            'jusqua'  => ['sometimes', 'date', 'after_or_equal:depuis'],
            'statut'  => ['sometimes', 'in:succes,echec,en_cours,tous'],
            'page'    => ['sometimes', 'integer', 'min:1'],
        ]);

        $numero = (string) $request->attributes->get('marchand_numero');
        $projet = (string) $request->attributes->get('marchand_projet');

        $depuis = isset($data['depuis'])
            ? \Carbon\Carbon::parse($data['depuis'], 'Africa/Libreville')->startOfDay()
            : now()->timezone('Africa/Libreville')->subDays(self::FENETRE_DEFAUT)->startOfDay();
        $jusqua = isset($data['jusqua'])
            ? \Carbon\Carbon::parse($data['jusqua'], 'Africa/Libreville')->endOfDay()
            : now()->timezone('Africa/Libreville')->endOfDay();

        $base = $this->requete($numero, $projet, $depuis, $jusqua);

        if (($data['statut'] ?? 'tous') !== 'tous') {
            $base->where('p.statut', $data['statut']);
        }

        $lignes = (clone $base)
            ->select([
                'p.trans_id', 'p.montant', 'p.statut', 'p.date_creation',
                'm.nom as marchand_nom', 'c.titre as cagnotte_titre',
                'u.nom as payeur_nom', 'u.prenom as payeur_prenom',
            ])
            ->orderByDesc('p.date_creation')
            ->paginate(50, ['*'], 'page', $data['page'] ?? 1);

        // Totaux calculés sur la PÉRIODE entière, pas sur la page affichée :
        // un total qui changerait en tournant les pages ne vaudrait rien pour
        // un rapprochement.
        // Et sur TOUS les statuts, pas seulement celui qu'on filtre : les
        // compteurs disent la répartition de la période, c'est leur rôle. Les
        // vider parce qu'on regarde la liste des échecs n'aurait pas de sens.
        //
        // Ils repartent donc d'une requête neuve, et surtout NUE : `selectRaw`
        // AJOUTE à la liste des colonnes, il ne la remplace pas. Tant que la
        // requête commune portait les huit colonnes du détail, elles se
        // retrouvaient dans un SELECT groupé sur le seul statut — et PostgreSQL
        // refusait (42803).
        $totaux = $this->requete($numero, $projet, $depuis, $jusqua)
            ->selectRaw("p.statut, count(*) as nb, coalesce(sum(p.montant), 0) as total")
            ->groupBy('p.statut')
            ->get()
            ->keyBy('statut');

        return response()->json([
            'periode' => [
                'depuis' => $depuis->toDateString(),
                'jusqua' => $jusqua->toDateString(),
            ],
            'totaux' => [
                'encaisse'  => (int) ($totaux['succes']->total ?? 0),
                'nb_succes' => (int) ($totaux['succes']->nb ?? 0),
                'nb_echec'  => (int) ($totaux['echec']->nb ?? 0),
                'nb_encours'=> (int) ($totaux['en_cours']->nb ?? 0),
            ],
            'transactions' => collect($lignes->items())->map(fn ($l) => [
                'reference'     => Registre::court($l->trans_id) ?? $l->trans_id,
                'trans_id'      => $l->trans_id,
                'montant'       => (int) $l->montant,
                'statut'        => $l->statut,
                'date'          => MessagePaiementMarchand::dateHeure($l->date_creation),
                'etablissement' => $l->marchand_nom,
                'cagnotte'      => $l->cagnotte_titre,
                'payeur'        => $this->nomPayeur($l),
                // Le reçu n'existe que pour un paiement abouti.
                'recu_url'      => $l->statut === 'succes'
                    ? url('/recu-marchand/' . $l->trans_id)
                    : null,
            ])->values(),
            'pagination' => [
                'page'    => $lignes->currentPage(),
                'pages'   => $lignes->lastPage(),
                'total'   => $lignes->total(),
            ],
        ]);
    }

    /**
     * Requête commune à la liste et aux totaux — jointures et filtres, **sans
     * aucune colonne sélectionnée**.
     *
     * Le SELECT appartient à l'appelant : la liste veut le détail, les totaux
     * veulent des agrégats, et `selectRaw` AJOUTE à la liste existante au lieu
     * de la remplacer. Poser les colonnes du détail ici condamnait donc la
     * requête d'agrégat.
     */
    private function requete(string $numero, string $projet, \Carbon\Carbon $depuis, \Carbon\Carbon $jusqua)
    {
        $payout    = project_table('payout');
        $marchands = project_table('marchands');
        $cagnottes = project_table('cagnottes');

        return DB::table("{$payout} as p")
            ->join("{$marchands} as m", 'm.id', '=', 'p.marchand_id')
            ->join("{$cagnottes} as c", 'c.id', '=', 'p.cagnotte_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.user_id')
            // Le numéro vient du jeton : il ne peut pas être choisi par
            // l'appelant, c'est ce qui cloisonne les marchands entre eux.
            ->where('m.numero_tel', $numero)
            ->where('p.project_id', $projet)
            ->whereBetween('p.date_creation', [$depuis, $jusqua]);
    }

    private function nomPayeur(object $l): string
    {
        $nom = trim(mb_strtoupper((string) ($l->payeur_nom ?? '')) . ' '
            . ucfirst(mb_strtolower((string) ($l->payeur_prenom ?? ''))));

        return $nom === '' ? 'Client Tonji' : $nom;
    }
}
