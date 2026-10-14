<?php

namespace Tests\Feature;

use App\Services\Mail\AdminNotifier;
use Tests\TestCase;

/**
 * Les alertes administrateurs ne quittent pas la recette.
 *
 * `AdminNotifier` passe par l'API Mailgun, pas par le pilote de messagerie de
 * Laravel : `MAIL_MAILER=log` ne l'arrêtait donc pas. Et comme trois de ses
 * appelants se déclenchent au fil des requêtes — transfert refusé, signalement,
 * webhook — il suffisait de tester un transfert sur la recette pour écrire à
 * tous les administrateurs de production.
 */
class AlertesHorsProdTest extends TestCase
{
    public function test_hors_production_rien_ne_part(): void
    {
        // L'environnement de test n'est pas 'production' : aucun destinataire,
        // et surtout aucun appel sortant.
        $envoyes = app(AdminNotifier::class)->notifier(
            'proj-test',
            'problemes',
            'Sujet de test',
            'Titre',
            '<p>corps</p>',
        );

        $this->assertSame(0, $envoyes);
    }

    public function test_le_garde_fou_se_leve_a_la_demande(): void
    {
        // Pour vérifier la chaîne d'envoi depuis la recette, sans avoir à
        // toucher au code.
        config(['services.admin_alertes.hors_prod' => true]);

        // La table des admins n'existe pas ici : si le garde-fou se lève
        // vraiment, la requête échoue — c'est la preuve qu'on est allé plus
        // loin que le retour anticipé.
        $this->expectException(\Illuminate\Database\QueryException::class);

        app(AdminNotifier::class)->notifier(
            'proj-test',
            'problemes',
            'Sujet',
            'Titre',
            '<p>corps</p>',
        );
    }
}
