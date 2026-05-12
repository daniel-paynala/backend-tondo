<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MODE TEST UNIQUEMENT.
 *
 * La table `public.users` est créée par 001_fondation.sql avec une FK
 * vers `auth.users(id)` (Supabase Auth). Quand on branchera vraiment
 * Supabase Auth phone OTP, chaque nouvel user passera par auth.users
 * et le trigger `on_auth_user_created` créera la row public.users.
 *
 * Mais en mode test on veut que Laravel puisse créer des users mobiles
 * directement (verify-otp avec OTP statique 123456). On drop donc la
 * contrainte ; elle sera recréée le jour où on intègre Supabase Auth.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ON_USER_CREATED trigger côté Supabase reste actif :
        // si un user signup via Supabase Auth, la row sera créée
        // dans public.users automatiquement. La FK enlevée signifie
        // juste qu'on tolère des rows dans public.users sans miroir
        // dans auth.users — c'est exactement ce qu'on veut en test.
        DB::statement('alter table public.users drop constraint if exists users_id_fkey');
    }

    public function down(): void
    {
        // Réactivation NOT VALID : ne vérifie pas les rows existantes,
        // mais contraint les inserts futurs.
        DB::statement(<<<'SQL'
            alter table public.users
                add constraint users_id_fkey
                foreign key (id) references auth.users(id) on delete cascade
                not valid
        SQL);
    }
};
