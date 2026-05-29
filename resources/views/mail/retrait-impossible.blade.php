TONDO — RETRAIT SUSPENDU : COTISATIONS INCOMPLÈTES
===================================================

Le retrait automatique du {{ $dateRetrait }} pour la tontine
« {{ $cagnotteTitre }} » (réf. {{ $cagnotteReference }}) a été SUSPENDU.

Cycle concerné : {{ $cycle }}
Cotisations reçues : {{ $nombrePayes }} / {{ $nombreTotal }}

PARTICIPANTS N'AYANT PAS ENCORE COTISÉ
---------------------------------------
@forelse($nonPayes as $p)
  - {{ $p['prenom'] }} {{ $p['nom'] }} ({{ $p['numero_masque'] }})
@empty
  (liste indisponible)
@endforelse

ACTIONS À FAIRE
---------------
1. Relancer les participants en retard.
2. Une fois tous cotisés, le prochain retrait automatique s'effectuera
   à la prochaine échéance prévue.
3. Si vous souhaitez débloquer manuellement, utilisez le tableau de bord
   administrateur ou contactez le support Tondo.

-- Tondo Alertes automatiques
