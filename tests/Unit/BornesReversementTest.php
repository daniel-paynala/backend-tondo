<?php

namespace Tests\Unit;

use App\Models\TondoProjectConfig;
use App\Services\TondoConfigService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Les bornes du prélèvement sur un reversement — plafond et franchise — sont
 * servies aux clients par la config projet.
 *
 * L'enjeu n'est pas le calcul (il vit dans les clients) mais le CONTRAT : ces
 * deux clés doivent sortir de la config, avec zéro comme repli, pour qu'aucun
 * client n'ait de raison d'écrire un prix en dur.
 */
class BornesReversementTest extends TestCase
{
    /**
     * La table doit exister ET porter les deux colonnes.
     *
     * Eloquent refuse l'affectation de masse d'une clé qui n'est pas une
     * colonne réelle (`isGuardableColumn`) : sans elles, le modèle les
     * écarterait en silence et le test passerait à côté de ce qu'il vérifie.
     * C'est aussi le rappel que **le SQL 039 doit être joué avant de déployer**
     * le dashboard, sinon son enregistrement échouera sur une colonne absente.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tondo_project_config', function ($table) {
            $table->uuid('id')->primary();
            $table->string('project_id');
            $table->string('operateur');
            $table->string('pays');
            $table->float('commission_paynala')->default(0);
            $table->integer('plafond_par_envoi')->default(0);
            $table->integer('plafond_journalier')->default(0);
            $table->integer('plafond_frais_retrait')->default(0);
            $table->integer('franchise_retrait')->default(0);
        });
    }

    /** La ligne de config sérialisée porte les deux bornes. */
    public function test_la_config_serialisee_porte_plafond_et_franchise(): void
    {
        $ligne = new TondoProjectConfig([
            'operateur'             => 'airtel',
            'pays'                  => 'GA',
            'commission_paynala'    => 0.02,
            'plafond_par_envoi'     => 500000,
            'plafond_journalier'    => 2500000,
            'plafond_frais_retrait' => 5000,
            'franchise_retrait'     => 50000,
        ]);

        $config = $ligne->toConfigArray();

        $this->assertSame(5000, $config['plafond_frais_retrait']);
        $this->assertSame(50000, $config['franchise_retrait']);
    }

    /**
     * Colonne absente ou vide → zéro, et zéro veut dire « aucune borne ».
     *
     * C'est l'état d'avant ces deux champs : le taux de la matrice s'applique
     * sans plafond ni franchise. Un repli à 5 000 changerait un prix affiché
     * sans que personne ne l'ait demandé.
     */
    public function test_sans_valeur_les_bornes_valent_zero(): void
    {
        $ligne = new TondoProjectConfig([
            'operateur'          => 'airtel',
            'pays'               => 'GA',
            'commission_paynala' => 0.02,
            'plafond_par_envoi'  => 500000,
            'plafond_journalier' => 2500000,
        ]);

        $config = $ligne->toConfigArray();

        $this->assertSame(0, $config['plafond_frais_retrait']);
        $this->assertSame(0, $config['franchise_retrait']);
    }

    /** Le repli fichier (projet sans config en base) les expose aussi. */
    public function test_le_repli_fichier_expose_les_deux_bornes(): void
    {
        $config = app(TondoConfigService::class)
            ->getOperatorConfig('projet-sans-config-en-base');

        $this->assertArrayHasKey('plafond_frais_retrait', $config);
        $this->assertArrayHasKey('franchise_retrait', $config);
        $this->assertIsInt($config['plafond_frais_retrait']);
        $this->assertIsInt($config['franchise_retrait']);
    }
}
