<?php

namespace App\Services;

/**
 * Génère les conditions d'utilisation à partir de la configuration opérateur.
 *
 * Les CGU annonçaient jusqu'ici des chiffres écrits en dur dans cinq écrans
 * (app et web), qui ont divergé de la config : elles affirmaient encore que les
 * frais de retrait étaient à la charge du cotisant alors que la matrice
 * `frais_retrait` vaut 0 depuis le 31 août 2026. Le texte est désormais produit
 * ici, à partir de la config réellement appliquée par le calcul des frais.
 *
 * Une seule source de vérité, et le texte suit l'opérateur : le jour où un
 * second opérateur arrive avec une autre grille, rien à toucher côté client.
 *
 * Deux niveaux, conformément à la RÈGLE 4-bis :
 *  – `resume` : les puces affichées directement à l'écran de création ;
 *  – `blocs`  : le détail déplié par le bouton « + », et le document complet
 *               consultable en permanence dans les paramètres.
 */
class CguService
{
    public function __construct(
        private readonly TondoConfigService $config,
    ) {
    }

    /**
     * Construit les CGU applicables à [$operateur] pour [$projectId].
     *
     * @return array{version: string, operateur: string, resume: list<string>, blocs: list<array{titre: string, corps: string}>}
     */
    public function pour(string $projectId, string $operateur = 'airtel', string $pays = 'GA'): array
    {
        return $this->construire(
            $this->config->getOperatorConfig($projectId, $operateur, $pays),
            $operateur,
        );
    }

    /**
     * Partie pure : construit le texte à partir d'une config déjà chargée.
     *
     * Séparée de {@see pour()} pour être testable sans base de données — c'est
     * ici que vit la logique qui a diverge par le passe.
     *
     * @param  array<string, mixed> $cfg
     * @return array{version: string, operateur: string, resume: list<string>, blocs: list<array{titre: string, corps: string}>}
     */
    public function construire(array $cfg, string $operateur = 'airtel'): array
    {
        $plafondEnvoi       = $this->fcfa((int) ($cfg['plafond_par_envoi'] ?? 0));
        $plafondParticulier = $this->fcfa((int) ($cfg['plafond_cagnotte_particulier'] ?? 0));
        $plafondAssociation = $this->fcfa((int) ($cfg['plafond_cagnotte_association'] ?? 0));

        $resume = $this->resume($plafondEnvoi);
        $blocs  = $this->blocs($cfg, $plafondEnvoi, $plafondParticulier, $plafondAssociation);

        return [
            // Empreinte du texte RENDU, pas de la config : une reformulation la
            // fait changer, et un réglage qui n'apparaît nulle part ne la fait
            // PAS changer — on ne demande pas de réaccepter pour rien.
            'version'   => $this->version($resume, $blocs),
            'operateur' => $operateur,
            'resume'    => $resume,
            'blocs'     => $blocs,
        ];
    }

    /**
     * Puces du résumé court, affichées sans dépliage à la création d'une collecte.
     *
     * @return list<string>
     */
    private function resume(string $plafondEnvoi): array
    {
        return [
            'Le montant collecté est automatiquement reversé sur le numéro de retrait indiqué.',
            'Ce numéro ne peut plus être modifié après la création de la collecte.',
            'Les frais sont appliqués au moment du paiement et sont à la charge du cotisant.',
            "Chaque paiement est plafonné à {$plafondEnvoi}.",
            "Tonji n'arbitre pas les conflits entre membres, sauf cas manifestement clair.",
        ];
    }

    /**
     * Sections détaillées.
     *
     * @return list<array{titre: string, corps: string}>
     */
    private function blocs(
        array  $cfg,
        string $plafondEnvoi,
        string $plafondParticulier,
        string $plafondAssociation,
    ): array {
        return [
            [
                'titre' => 'Modèle économique',
                'corps' => $this->modeleEconomique($cfg),
            ],
            [
                'titre' => 'Plafonds',
                'corps' => "Chaque paiement est limité à {$plafondEnvoi}. Le total collecté par une "
                    . "collecte est plafonné à {$plafondParticulier} pour un compte particulier et à "
                    . "{$plafondAssociation} pour une association. Un plafond supérieur peut être "
                    . 'accordé au cas par cas, sur justificatif.',
            ],
            [
                'titre' => 'Numéro de retrait',
                'corps' => 'Le numéro de retrait ne pourra plus être changé après la création de la '
                    . 'collecte. Cette règle protège tous les membres contre la fraude.',
            ],
            [
                'titre' => 'Cagnottes publiques',
                'corps' => 'Une collecte est privée par défaut : seuls les membres invités y '
                    . 'participent. Une collecte publique, ouverte à tous, est réservée aux '
                    . 'associations dont le dossier a été validé, et passe en modération avant '
                    . "d'être publiée.",
            ],
            [
                'titre' => 'Différends entre membres',
                'corps' => "Tonji facilite la collecte mais n'arbitre pas les conflits entre cotisants "
                    . 'et bénéficiaires, sauf cas manifestement clair (ex : usurpation d\'identité). '
                    . 'Vous restez responsable du choix de vos co-membres.',
            ],
        ];
    }

    /**
     * Phrase du modèle économique — le cœur du problème que ce service résout.
     *
     * Le TAUX de commission n'est volontairement pas cité : la RÈGLE 4-bis
     * interdit d'exposer le détail du calcul des frais. Le texte dit qui les
     * supporte, pas combien ils valent.
     *
     * La mention des frais de retrait n'apparaît QUE s'ils sont effectivement
     * répercutés sur le cotisant quelque part dans la matrice. À 0 partout, le
     * texte dit qu'ils sont à la charge du bénéficiaire, ce qui est le cas.
     */
    private function modeleEconomique(array $cfg): string
    {
        $phrase = 'Tonji prélève une commission sur chaque cotisation, à la charge du cotisant. '
            . 'Elle est appliquée au moment du paiement et le montant exact vous est indiqué '
            . 'avant validation. ';

        if ($this->fraisRetraitRepercutes($cfg)) {
            $phrase .= 'Les frais de retrait de l\'opérateur sont également répercutés sur le '
                . 'cotisant, selon le barème en vigueur. ';
        } else {
            $phrase .= "Les frais de retrait de l'opérateur ne sont pas répercutés sur le cotisant : "
                . 'ils restent à la charge du bénéficiaire. ';
        }

        return $phrase . 'Les frais éventuellement prélevés par votre opérateur Mobile Money au '
            . 'moment du paiement lui sont propres et ne reviennent pas à Tonji. Le bénéficiaire '
            . 'reçoit le montant net annoncé.';
    }

    /** Vrai si un seul couple (type de collecte × type de compte) répercute des frais de retrait. */
    private function fraisRetraitRepercutes(array $cfg): bool
    {
        foreach (($cfg['frais_retrait'] ?? []) as $parTypeCompte) {
            foreach ((array) $parTypeCompte as $taux) {
                if ((float) $taux > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Empreinte du texte effectivement affiché.
     *
     * Hacher le TEXTE plutôt que la config a deux conséquences voulues : une
     * reformulation fait changer la version — les conditions ont bien changé
     * pour l'utilisateur —, et un réglage qui n'apparaît nulle part, comme le
     * taux de commission, ne la fait pas changer.
     *
     * @param  list<string> $resume
     * @param  list<array{titre: string, corps: string}> $blocs
     */
    private function version(array $resume, array $blocs): string
    {
        return substr(md5(json_encode([$resume, $blocs], JSON_UNESCAPED_UNICODE)), 0, 12);
    }

    /** 0.02 → « 2 % ». Deux décimales au plus, sans zéros inutiles. */
    private function pourcentage(float $taux): string
    {
        $valeur = rtrim(rtrim(number_format($taux * 100, 2, ',', ' '), '0'), ',');

        return $valeur . ' %';
    }

    /** 500000 → « 500 000 FCFA ». */
    private function fcfa(int $montant): string
    {
        return number_format($montant, 0, ',', ' ') . ' FCFA';
    }
}
