<?php

namespace App\Services;

use App\Models\TondoProjectConfig;

/**
 * Détecte l'opérateur Mobile Money d'un numéro téléphonique E.164.
 *
 * Logique :
 *  1. Parcourt toutes les configs du projet ayant indicatif + prefixes définis.
 *  2. Si le numéro E.164 commence par +{indicatif}, extrait la partie locale.
 *  3. Normalise la partie locale en ajoutant le zéro initial.
 *  4. Teste si cette partie locale commence par l'un des préfixes configurés.
 *  5. Premier match = résultat.
 *
 * Exemple : numéro = "+24177123456", config Airtel GA indicatif="241"
 *   → partie E.164 sans "+" : "24177123456"
 *   → strip "241" → "77123456"  (= local sans zéro)
 *   → normalise → "077123456"   (= local avec zéro)
 *   → teste "077" ∈ prefixes → match → retourne ['operateur'=>'airtel','pays'=>'GA','indicatif'=>'241']
 */
class OperateurDetectorService
{
    /**
     * Retourne ['operateur', 'pays', 'indicatif'] si un opérateur est détecté,
     * null sinon.
     */
    public function detect(string $projectId, string $phoneE164): ?array
    {
        // Retire le "+" initial pour comparer digit à digit (ex : "+241…" → "241…").
        $phone = ltrim($phoneE164, '+');

        // On charge uniquement les configs qui ont à la fois un indicatif et des préfixes définis.
        $configs = TondoProjectConfig::where('project_id', $projectId)
            ->whereNotNull('indicatif')
            ->whereNotNull('prefixes')
            ->get();

        foreach ($configs as $config) {
            // Normalise l'indicatif stocké en DB (peut contenir un "+" : "+241" → "241").
            $indicatif = ltrim((string) $config->indicatif, '+');

            // Si le numéro ne commence pas par cet indicatif, passer à la config suivante.
            if (! str_starts_with($phone, $indicatif)) {
                continue;
            }

            // Partie locale sans le zéro initial (ex : "241" → "77123456").
            $localSansZero = substr($phone, strlen($indicatif));
            // Normalise : ajoute le zéro initial pour correspondre au format "077123456"
            // utilisé dans la table des préfixes Airtel Gabon.
            $localAvecZero = '0' . $localSansZero;

            // prefixes est stocké en JSON en DB ; on s'assure d'avoir un tableau.
            $prefixes = is_array($config->prefixes) ? $config->prefixes : [];
            foreach ($prefixes as $prefix) {
                $prefix = (string) $prefix;
                // La partie locale avec zéro commence-t-elle par ce préfixe ?
                if (str_starts_with($localAvecZero, $prefix)) {
                    return [
                        'operateur' => $config->operateur, // Ex : 'airtel'.
                        'pays'      => $config->pays,      // Ex : 'GA'.
                        'indicatif' => $indicatif,         // Ex : '241'.
                    ];
                }
            }
        }

        // Aucune config ne correspond à ce numéro.
        return null;
    }
}
