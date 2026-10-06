<?php

namespace App\Support;

/**
 * Composition des messages d'un paiement marchand.
 *
 * Classe à part, sans dépendance au framework ni à la base : le texte qu'un
 * marchand garde comme preuve mérite d'être vérifiable par un test, ligne à
 * ligne, sans monter une transaction.
 */
final class MessagePaiementMarchand
{
    /**
     * Montant réellement ENCAISSÉ par l'enseigne, depuis une ligne de payout.
     *
     * `payout.montant` est le montant DÉBITÉ de la collecte ; depuis la
     * répartition des frais, l'enseigne peut recevoir moins — les parts sont
     * retenues et partent vers leurs propres comptes. Le net est tracé dans
     * `request.montant_net`.
     *
     * Repli sur le brut quand la clé est absente : les lignes d'avant la
     * répartition n'en ont pas, et pour elles les deux montants étaient égaux.
     *
     * Un seul calcul, partagé par le reçu et la notification : annoncer deux
     * chiffres différents au même commerçant pour le même paiement serait
     * pire que de se tromper une fois.
     */
    public static function encaisse(object $ligne): int
    {
        $request = is_string($ligne->request ?? null)
            ? json_decode($ligne->request, true)
            : ($ligne->request ?? null);

        $net = is_array($request) ? ($request['montant_net'] ?? null) : null;

        return (int) ($net ?? $ligne->montant);
    }

    /** Constructeur privé : composition pure, pas d'instance. */
    private function __construct() {}

    /**
     * SMS et e-mail reçus par le marchand.
     *
     * @param  array{montant: int, reference: string, cagnotte: string,
     *               payeur: string, date_heure: string, url_portail?: ?string} $d
     */
    public static function pourMarchand(array $d): string
    {
        $lignes = [
            'TONJI - Paiement de ' . self::montant($d['montant']) . ' FCFA reçu.',
            'Ref ' . $d['reference'],
            'Cagnotte : ' . $d['cagnotte'],
            'Payé par : ' . $d['payeur'],
            $d['date_heure'],
        ];

        // L'adresse du portail n'est pas encore arrêtée : tant qu'elle n'est
        // pas configurée, la ligne disparaît plutôt que d'annoncer un lien
        // mort ou, pire, de partir vide dans un SMS facturé.
        $portail = trim((string) ($d['url_portail'] ?? ''));
        if ($portail !== '') {
            $lignes[] = 'Detail: ' . $portail;
        }

        return implode("\n", $lignes);
    }

    /**
     * Notification poussée au payeur. Il a l'application et son historique :
     * pas de SMS pour lui, c'est une dépense que rien ne justifie.
     *
     * @param  array{montant: int, marchand: string} $d
     * @return array{0: string, 1: string} titre, corps
     */
    public static function pourPayeur(array $d): array
    {
        return [
            'Paiement effectué',
            self::montant($d['montant']) . ' FCFA payés à ' . $d['marchand'] . '.',
        ];
    }

    /** Montant séparé par milliers, à l'espace : « 150 000 ». */
    public static function montant(int $montant): string
    {
        return number_format($montant, 0, ',', ' ');
    }

    /**
     * Date et heure **de Libreville**, pas celles du serveur.
     *
     * L'application tourne en UTC. Imprimer l'heure brute dans un message que
     * le marchand garde comme preuve lui donnerait une heure de retard sur ce
     * qu'affiche son téléphone.
     */
    public static function dateHeure(\DateTimeInterface|string $instant): string
    {
        return \Carbon\Carbon::parse($instant)
            ->timezone('Africa/Libreville')
            ->format('d/m/Y à H:i');
    }
}
