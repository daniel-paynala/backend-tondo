<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Le canal « retrait en espèces » est ABSENT, pas seulement gardé.
 *
 * Le chantier est expérimental, ses contours ne sont pas arrêtés, et il remet
 * du LIQUIDE — qui ne se conteste pas, contrairement à un virement. Il part
 * pourtant en production avec le chantier marchand, dont il est inséparable :
 * les deux se sont succédé sur la même ligne d'historique, et quatorze
 * fichiers du chemin de l'argent leur sont communs. Les isoler reviendrait à
 * écrire du code neuf, jamais exécuté, là où l'argent passe.
 *
 * D'où ce test : il vérifie que le drapeau ferme vraiment, et qu'une
 * réouverture ne laisse pas de trou derrière elle.
 */
class RetraitEspecesFermeTest extends TestCase
{
    /** Chemins qui ne doivent pas exister quand le canal est fermé. */
    private const CHEMINS_AGENT = [
        'api/agent/connexion',
        'api/agent/retraits',
        'api/admin/supports-retrait',
        'api/admin/partenaires-retrait',
        'api/admin/agents',
    ];

    /**
     * Le DÉFAUT du drapeau est « fermé », quelle que soit la variable d'env.
     *
     * Lu dans le fichier de configuration et non via `config()` : ce qui doit
     * être garanti, c'est qu'une variable d'environnement absente ou mal
     * orthographiée en production laisse le canal FERMÉ. `config()` renverrait
     * la valeur de l'environnement courant, et ne dirait rien du défaut.
     */
    public function test_le_defaut_du_drapeau_est_ferme(): void
    {
        $this->assertMatchesRegularExpression(
            "/'retrait_especes_actif'\s*=>\s*env\('TONJI_RETRAIT_ESPECES_ACTIF',\s*false\)/",
            file_get_contents(config_path('tondo.php')) ?: '',
            'Le canal doit être fermé en l\'absence de variable d\'environnement.',
        );
    }

    public function test_les_routes_du_canal_sont_absentes_quand_il_est_ferme(): void
    {
        $this->sauterSiOuvert();

        $enregistres = collect(Route::getRoutes())->map(fn ($r) => $r->uri())->all();

        foreach (self::CHEMINS_AGENT as $chemin) {
            $this->assertNotContains(
                $chemin,
                $enregistres,
                "La route {$chemin} ne doit pas être enregistrée : le canal doit répondre 404, pas 401.",
            );
        }
    }

    /**
     * 404 et non 401 : la nuance est le cœur de la décision.
     *
     * Une route gardée annonce qu'elle existe et invite à chercher la clé. Une
     * route absente ne dit rien.
     */
    public function test_un_appel_au_canal_repond_introuvable(): void
    {
        $this->sauterSiOuvert();

        $this->postJson('/api/agent/connexion', ['identifiant' => 'x', 'pin' => '0000'])
            ->assertNotFound();

        $this->getJson('/api/admin/agents')->assertNotFound();
    }

    /**
     * Le verrou des sorties est posé aux DEUX moments du parcours agent.
     *
     * Vérification structurelle : le jour où le drapeau s'ouvrira, personne ne
     * relira ce service. Un retrait en espèces débite une collecte — c'est une
     * sortie d'argent comme les autres, et un verrou qui laisserait un guichet
     * ouvert ne verrouillerait rien.
     */
    public function test_le_retrait_en_especes_consulte_le_verrou(): void
    {
        $service = file_get_contents(base_path('app/Services/RetraitEspecesService.php')) ?: '';

        $this->assertSame(
            2,
            substr_count($service, 'SortiesAutorisees::class'),
            'Le verrou doit être consulté à la demande (contrôle indicatif) ET à la '
                . 'validation (contrôle qui fait foi, juste avant le débit).',
        );
    }

    /**
     * Ces deux tests décrivent l'état FERMÉ.
     *
     * Le jour où le chantier reprendra, le drapeau s'ouvrira et ils n'auront
     * plus d'objet : ils se sautent alors au lieu d'échouer, pour ne pas faire
     * chercher une régression à qui travaille légitimement dessus. Le test du
     * défaut, lui, continue de garantir que la production reste fermée.
     */
    private function sauterSiOuvert(): void
    {
        if (config('tondo.retrait_especes_actif')) {
            $this->markTestSkipped(
                'Canal ouvert dans cet environnement : ce test décrit l\'état fermé.',
            );
        }
    }

    /** Le drapeau du dashboard doit dire la même chose que celui du serveur. */
    public function test_le_dashboard_ferme_le_canal_lui_aussi(): void
    {
        $chemin = base_path('../admin/src/lib/featureFlags.ts');

        if (! is_file($chemin)) {
            $this->markTestSkipped('Dépôt admin absent de ce poste.');
        }

        $this->sauterSiOuvert();

        $this->assertMatchesRegularExpression(
            '/RETRAIT_ESPECES_ACTIF\s*=\s*false/',
            file_get_contents($chemin) ?: '',
            'Le dashboard doit masquer le canal tant que le serveur le ferme — sinon il '
                . 'propose de créer un partenaire que l\'API refusera en 404.',
        );
    }
}
