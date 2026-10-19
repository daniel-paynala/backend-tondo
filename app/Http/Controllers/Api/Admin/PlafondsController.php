<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\TondoProjectConfig;
use App\Services\TondoConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plafonds TOTAUX de collecte d'une cagnotte (particulier / association).
 *
 * Stockés sur la config projet (tonji_project_config). La **lecture** est
 * ouverte à tout admin ; la **modification** est réservée aux **super_admin**.
 */
class PlafondsController extends Controller
{
    /** GET /api/admin/plafonds-cagnotte */
    public function show(Request $request): JsonResponse
    {
        $config = app(TondoConfigService::class)->getOperatorConfig(Project::tondoId());

        return response()->json([
            'plafond_cagnotte_particulier' => (int) ($config['plafond_cagnotte_particulier'] ?? 2500000),
            'plafond_cagnotte_association' => (int) ($config['plafond_cagnotte_association'] ?? 10000000),
        ]);
    }

    /** PATCH /api/admin/plafonds-cagnotte — réservé aux super_admin. */
    public function update(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->role === 'super_admin',
            403,
            'Action réservée aux super admins.',
        );

        $data = $request->validate([
            'plafond_cagnotte_particulier' => ['required', 'integer', 'min:1000', 'max:1000000000'],
            'plafond_cagnotte_association' => ['required', 'integer', 'min:1000', 'max:1000000000'],
        ]);

        // Applique à toutes les config du projet (Tonji = airtel/GA) pour rester
        // cohérent quel que soit l'opérateur.
        $maj = TondoProjectConfig::where('project_id', Project::tondoId())->update([
            'plafond_cagnotte_particulier' => $data['plafond_cagnotte_particulier'],
            'plafond_cagnotte_association' => $data['plafond_cagnotte_association'],
            'updated_at' => now(),
        ]);

        if ($maj === 0) {
            return response()->json([
                'message' => 'Aucune configuration projet à mettre à jour (config opérateur manquante).',
            ], 422);
        }

        return response()->json([
            'message'                      => 'Plafonds mis à jour.',
            'plafond_cagnotte_particulier' => $data['plafond_cagnotte_particulier'],
            'plafond_cagnotte_association' => $data['plafond_cagnotte_association'],
        ]);
    }

    /**
     * GET /api/admin/frais-retrait — ce que Tonji prélève, et sur quoi.
     *
     * La matrice des frais de retrait et le taux du paiement marchand vivent
     * sur le même écran : ce sont les deux seuls taux que le dashboard règle,
     * et les séparer obligerait à chercher dans deux pages ce qui se décide
     * d'un seul mouvement.
     */
    public function showFrais(Request $request): JsonResponse
    {
        $config = app(TondoConfigService::class)->getOperatorConfig(Project::tondoId());

        return response()->json([
            'frais_retrait' => $config['frais_retrait'] ?? [
                'cagnotte' => ['particulier' => 0, 'association' => 0],
                'tontine'  => ['particulier' => 0, 'association' => 0],
            ],
            // Taux sur un paiement marchand. Zéro = aucun prélèvement, et le
            // texte des conditions le dira de lui-même.
            'frais_marchand' => (float) ($config['frais_marchand'] ?? 0),
            // Bornes du prélèvement sur un reversement. Elles vivent avec la
            // matrice parce qu'elles n'ont de sens qu'avec elle : un plafond
            // sans taux ne veut rien dire.
            'plafond_frais_retrait' => (int) ($config['plafond_frais_retrait'] ?? 0),
            'franchise_retrait'     => (int) ($config['franchise_retrait'] ?? 0),
            // Verrous globaux des sorties, par type de compte. Ils ferment un
            // canal d'un coup, pour tous les comptes de ce type.
            'sorties_bloquees' => $config['sorties_bloquees']
                ?? TondoProjectConfig::VERROUS_OUVERTS,
            // Pendant à l'entrée : coupe les cotisations pour tout un type de
            // compte, sur tous les canaux à la fois.
            'cotisations_bloquees' => $config['cotisations_bloquees']
                ?? TondoProjectConfig::COLLECTES_OUVERTES,
            // Collectes actives par organisateur.
            // Jamais converti en entier : `null` doit rester `null` jusqu'à
            // l'écran, sans quoi « aucune limite » s'afficherait « 0 jour » —
            // l'exact contraire.
            'duree_max_cagnotte_jours' => $config['duree_max_cagnotte_jours'] ?? null,
            'max_cagnottes_actives' => (int) ($config['max_cagnottes_actives']
                ?? TondoProjectConfig::MAX_CAGNOTTES_ACTIVES),
        ]);
    }

    /** PATCH /api/admin/frais-retrait — réservé aux super_admin. Taux décimaux (0.03 = 3 %). */
    public function updateFrais(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->role === 'super_admin',
            403,
            'Action réservée aux super admins.',
        );

        $data = $request->validate([
            // PATCH veut dire « modifie ce que j'envoie » : chaque bloc est
            // facultatif et seul ce qui arrive est écrit. Sans cela, régler un
            // verrou obligerait à renvoyer toute la matrice des taux, et un
            // écran qui ne connaît que les verrous ne pourrait pas appeler.
            'frais_retrait'                      => ['sometimes', 'array'],
            'frais_retrait.cagnotte.particulier' => ['required_with:frais_retrait', 'numeric', 'min:0', 'max:0.5'],
            'frais_retrait.cagnotte.association' => ['required_with:frais_retrait', 'numeric', 'min:0', 'max:0.5'],
            'frais_retrait.tontine.particulier'  => ['required_with:frais_retrait', 'numeric', 'min:0', 'max:0.5'],
            'frais_retrait.tontine.association'  => ['required_with:frais_retrait', 'numeric', 'min:0', 'max:0.5'],
            // Facultatif : un appel qui ne règle que la matrice reste valide.
            // Borne à 25 %, comme la commission — au-delà, c'est une erreur de
            // saisie, pas une décision commerciale.
            'frais_marchand'                     => ['sometimes', 'numeric', 'min:0', 'max:0.25'],
            // Bornes du prélèvement, en FCFA. Zéro est une valeur légitime :
            // elle dit « aucun plafond » et « aucune franchise ».
            'plafond_frais_retrait'              => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'franchise_retrait'                  => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            // Verrous globaux — facultatifs eux aussi : un appel qui ne règle
            // que les taux ne doit pas rouvrir un canal qu'on vient de fermer.
            'sorties_bloquees'                          => ['sometimes', 'array'],
            'sorties_bloquees.particulier.transfert'    => ['required_with:sorties_bloquees', 'boolean'],
            'sorties_bloquees.particulier.marchand'     => ['required_with:sorties_bloquees', 'boolean'],
            'sorties_bloquees.association.transfert'    => ['required_with:sorties_bloquees', 'boolean'],
            'sorties_bloquees.association.marchand'     => ['required_with:sorties_bloquees', 'boolean'],
            // Verrou global des cotisations. Forme plus simple que les
            // sorties : une seule action à fermer, donc un booléen par type.
            'cotisations_bloquees'              => ['sometimes', 'array'],
            'cotisations_bloquees.particulier'  => ['required_with:cotisations_bloquees', 'boolean'],
            'cotisations_bloquees.association'  => ['required_with:cotisations_bloquees', 'boolean'],
            // Quota de collectes. Minimum 1 : zéro empêcherait tout le monde
            // de créer quoi que ce soit, ce qui n'est pas un réglage mais une
            // panne. Le maximum garde le champ dans le raisonnable.
            'max_cagnottes_actives' => ['sometimes', 'integer', 'min:1', 'max:100'],
            // Durée de vie maximale d'une cagnotte ouverte, en jours.
            //
            // `nullable` est le cœur du réglage : vide vaut AUCUNE limite, et
            // doit pouvoir être reposé pour annuler la mesure. L'exiger
            // interdirait de revenir en arrière.
            //
            // Plancher à 7 jours : en deçà, les cinq rappels n'ont pas le
            // temps de se dérouler et l'organisateur verrait sa cagnotte
            // fermer presque sans préavis. Plafond à dix ans, qui n'est pas
            // une limite mais un garde-fou contre la faute de frappe.
            'duree_max_cagnotte_jours' => ['sometimes', 'nullable', 'integer', 'min:7', 'max:3650'],
        ]);

        $champs  = ['updated_at' => now()];
        $matrice = null;

        // Chaque bloc n'est écrit QUE s'il a été envoyé : un appel qui ne règle
        // que les verrous ne doit pas remettre les taux à leur valeur par
        // défaut, et réciproquement.
        if (array_key_exists('frais_retrait', $data)) {
            $matrice = [
                'cagnotte' => [
                    'particulier' => (float) $data['frais_retrait']['cagnotte']['particulier'],
                    'association' => (float) $data['frais_retrait']['cagnotte']['association'],
                ],
                'tontine' => [
                    'particulier' => (float) $data['frais_retrait']['tontine']['particulier'],
                    'association' => (float) $data['frais_retrait']['tontine']['association'],
                ],
            ];
            $champs['frais_retrait'] = json_encode($matrice);
        }
        if (array_key_exists('frais_marchand', $data)) {
            $champs['frais_marchand'] = (float) $data['frais_marchand'];
        }
        if (array_key_exists('plafond_frais_retrait', $data)) {
            $champs['plafond_frais_retrait'] = (int) $data['plafond_frais_retrait'];
        }
        if (array_key_exists('franchise_retrait', $data)) {
            $champs['franchise_retrait'] = (int) $data['franchise_retrait'];
        }
        // `array_key_exists` et non `??` : la clé absente veut dire « ne touche
        // pas », la clé à null veut dire « retire la limite ». Les confondre
        // rendrait la suppression du réglage impossible.
        if (array_key_exists('duree_max_cagnotte_jours', $data)) {
            $champs['duree_max_cagnotte_jours'] = $data['duree_max_cagnotte_jours'] !== null
                ? (int) $data['duree_max_cagnotte_jours']
                : null;
        }

        if (array_key_exists('max_cagnottes_actives', $data)) {
            $champs['max_cagnottes_actives'] = (int) $data['max_cagnottes_actives'];
        }

        if (array_key_exists('cotisations_bloquees', $data)) {
            // Même normalisation que les sorties : un « false » arrivé en
            // chaîne depuis un formulaire vaudrait vrai, et couperait la
            // collecte sans que personne l'ait demandé.
            $champs['cotisations_bloquees'] = json_encode([
                'particulier' => (bool) $data['cotisations_bloquees']['particulier'],
                'association' => (bool) $data['cotisations_bloquees']['association'],
            ]);
        }

        if (array_key_exists('sorties_bloquees', $data)) {
            // Normalisé en booléens : un « false » arrivé en chaîne depuis un
            // formulaire deviendrait vrai en base, et fermerait un canal que
            // personne n'a demandé de fermer.
            $champs['sorties_bloquees'] = json_encode([
                'particulier' => [
                    'transfert' => (bool) $data['sorties_bloquees']['particulier']['transfert'],
                    'marchand'  => (bool) $data['sorties_bloquees']['particulier']['marchand'],
                ],
                'association' => [
                    'transfert' => (bool) $data['sorties_bloquees']['association']['transfert'],
                    'marchand'  => (bool) $data['sorties_bloquees']['association']['marchand'],
                ],
            ]);
        }

        $maj = TondoProjectConfig::where('project_id', Project::tondoId())->update($champs);

        if ($maj === 0) {
            return response()->json([
                'message' => 'Aucune configuration projet à mettre à jour (config opérateur manquante).',
            ], 422);
        }

        return response()->json([
            'message'       => 'Réglages mis à jour.',
            'frais_retrait' => $matrice,
        ]);
    }
}
