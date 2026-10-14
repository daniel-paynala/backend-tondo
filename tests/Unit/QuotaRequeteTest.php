<?php

namespace Tests\Unit;

use App\Services\QuotaCagnottes;
use App\Services\TondoConfigService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La requête du quota ne doit JAMAIS nommer une valeur d'énumération qui peut
 * manquer.
 *
 * Elle écrivait `type != 'wallet'`. En production, `wallet` n'existe pas dans
 * `tonji_type_cagnotte` tant que le solde personnel n'est pas déployé :
 * Postgres a refusé la comparaison entière (SQLSTATE 22P02) et toute la liste
 * des collectes est tombée.
 *
 * Le test porte sur le SQL ÉMIS, et non sur le résultat : la base de test est
 * SQLite, qui ignore les énumérations et accepterait n'importe quelle valeur.
 * Un test fonctionnel serait donc passé au vert pendant que la production
 * tombait — c'est exactement ce qui s'est produit.
 */
class QuotaRequeteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tondo_cagnottes', function ($t) {
            $t->uuid('id')->primary();
            $t->string('project_id');
            $t->uuid('user_id');
            $t->string('type');
            $t->string('statut');
        });
    }

    /** Le SQL que le service produirait, sans l'exécuter. */
    private function sqlDuComptage(): string
    {
        $requetes = [];
        DB::listen(function ($q) use (&$requetes) {
            $requetes[] = $q->sql . ' | ' . implode(',', $q->bindings);
        });

        app(QuotaCagnottes::class)->actives('user-1', 'proj-1');

        return implode("\n", $requetes);
    }

    public function test_la_requete_ne_compare_pas_le_type_a_wallet(): void
    {
        $sql = $this->sqlDuComptage();

        // Le cœur du sujet : nommer une valeur absente de l'énumération fait
        // échouer la requête ENTIÈRE, pas seulement la condition.
        $this->assertStringNotContainsString(
            'wallet',
            $sql,
            "La requête nomme « wallet » : si la valeur n'existe pas dans "
            . "l'énumération de la base, Postgres rejette tout (22P02).",
        );
    }

    public function test_la_requete_nomme_les_types_qu_elle_compte(): void
    {
        // L'inverse de l'exclusion : on liste ce qu'on veut compter, et ces
        // valeurs-là existent depuis toujours.
        $sql = $this->sqlDuComptage();

        $this->assertStringContainsString('cagnotte_ouverte', $sql);
        $this->assertStringContainsString('tontine_periodique', $sql);
    }
}
