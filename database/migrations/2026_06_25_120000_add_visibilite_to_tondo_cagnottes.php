<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute la visibilité PUBLIC/PRIVÉ aux cagnottes + le cycle de modération.
 *
 * - Privée (défaut) : comportement historique, aucune validation requise.
 * - Publique : crowdfunding ouvert à tous, soumis à validation dans le dash.
 *
 * Réservé aux cagnottes ouvertes (les tontines restent toujours privées).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tondo_cagnottes', function (Blueprint $table) {
            // 'prive' (défaut) | 'public'
            $table->string('visibilite', 16)->default('prive');
            // 'non_requis' (privée) | 'en_attente' | 'approuvee' | 'rejetee' | 'suspendue'
            $table->string('statut_validation', 24)->default('non_requis');
            // Histoire / description, affichée sur la page publique.
            $table->text('description')->nullable();
            // Motif renseigné par l'admin en cas de rejet ou de suspension.
            $table->text('motif_rejet')->nullable();
            // Audit de la décision de modération.
            $table->timestamp('validee_at')->nullable();
            $table->string('validee_par')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tondo_cagnottes', function (Blueprint $table) {
            $table->dropColumn([
                'visibilite',
                'statut_validation',
                'description',
                'motif_rejet',
                'validee_at',
                'validee_par',
            ]);
        });
    }
};
