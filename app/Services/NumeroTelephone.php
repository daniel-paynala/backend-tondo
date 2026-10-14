<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les formats de numéro, déduits de la configuration plutôt que gravés.
 *
 * « +241 » apparaissait en dur dans quatre endroits — dont deux copies de la
 * même fonction — et la validation d'un bénéficiaire imposait neuf chiffres.
 * Tant que ces formes restent figées, aucun numéro étranger ne franchit la
 * validation, même avec toutes les capacités déclarées pour son pays.
 *
 * Les indicatifs viennent de `project_config`, où ils sont déjà déclarés avec
 * leurs préfixes d'opérateur. Rien à maintenir en double : ouvrir un pays,
 * c'est ajouter une ligne.
 */
class NumeroTelephone
{
    /** @var array<string, string>|null indicatif (sans +) => pays */
    private ?array $indicatifs = null;

    /**
     * Forme canonique en base : `+` suivi de l'indicatif et du numéro national
     * sans son zéro de tête.
     *
     * Le `$indicatifDefaut` sert quand la saisie n'en porte pas — le dashboard
     * laisse taper « 07 60 77 52 ». Il n'est PAS une constante : c'est celui du
     * pays auquel la saisie se rattache.
     */
    public function versE164(string $saisie, string $indicatifDefaut): string
    {
        $chiffres = preg_replace('/\D/', '', $saisie) ?? '';
        $defaut   = ltrim($indicatifDefaut, '+');

        // La saisie porte déjà un indicatif connu : on la respecte, même s'il
        // diffère du défaut. Forcer le défaut réécrirait un numéro étranger en
        // numéro local, et l'argent partirait au mauvais endroit.
        foreach ($this->indicatifs() as $indicatif => $pays) {
            if (str_starts_with($chiffres, $indicatif)) {
                return '+' . $chiffres;
            }
        }

        return '+' . $defaut . ltrim($chiffres, '0');
    }

    /** Pays d'un numéro E.164, ou null si son indicatif n'est pas déclaré. */
    public function paysDe(?string $e164): ?string
    {
        if ($e164 === null) {
            return null;
        }

        $chiffres = ltrim($e164, '+');

        foreach ($this->indicatifs() as $indicatif => $pays) {
            if (str_starts_with($chiffres, $indicatif)) {
                return $pays;
            }
        }

        return null;
    }

    /**
     * Forme nationale attendue par les API d'opérateur : `0` suivi du numéro
     * sans son indicatif.
     *
     * Un numéro dont l'indicatif n'est pas déclaré est rendu tel quel, sans le
     * `+` : le deviner reviendrait à fabriquer un numéro qui n'existe pas.
     */
    public function versLocal(?string $e164): string
    {
        $chiffres = ltrim((string) $e164, '+');

        foreach ($this->indicatifs() as $indicatif => $pays) {
            if (str_starts_with($chiffres, $indicatif)) {
                return '0' . substr($chiffres, strlen($indicatif));
            }
        }

        return $chiffres;
    }

    /** Vrai si l'indicatif de ce numéro est déclaré quelque part. */
    public function estConnu(?string $e164): bool
    {
        return $this->paysDe($e164) !== null;
    }

    /**
     * Les indicatifs déclarés, du plus long au plus court.
     *
     * L'ordre compte : un indicatif court en préfixe parfois un plus long, et
     * tester « 22 » avant « 221 » rattacherait un numéro au mauvais pays.
     *
     * @return array<string, string>
     */
    private function indicatifs(): array
    {
        if ($this->indicatifs !== null) {
            return $this->indicatifs;
        }

        // Table absente — un contexte de test, une installation neuve : on rend
        // une liste vide plutôt que de lever. Le numéro retombe alors sur
        // l'indicatif par défaut que l'appelant fournit, ce qui est exactement
        // le comportement d'avant.
        if (! Schema::hasTable(project_table('project_config'))) {
            return $this->indicatifs = [];
        }

        $map = [];
        foreach (DB::table(project_table('project_config'))->get(['indicatif', 'pays']) as $c) {
            if ($c->indicatif !== null && $c->pays !== null) {
                $map[ltrim((string) $c->indicatif, '+')] = (string) $c->pays;
            }
        }

        uksort($map, fn ($a, $b) => strlen($b) <=> strlen($a));

        return $this->indicatifs = $map;
    }
}
