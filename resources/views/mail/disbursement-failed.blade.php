ALERTE TONDO — ÉCHEC DISBURSEMENT — INTERVENTION MANUELLE REQUISE
==================================================================

Un reversement Paynala a échoué APRÈS que le solde de la cagnotte a été
débité. Le fonds est "réservé" mais le transfert opérateur n'est pas confirmé.

NE PAS agir automatiquement. Vérifier manuellement dans Paynala si la
transaction a été traitée avant toute correction.

DÉTAILS
-------
Payout ID        : {{ $payoutId }}
Trans ID Tonji   : {{ $transId }}
Cagnotte         : {{ $cagnotteReference }}
Montant          : {{ number_format($montant, 0, ',', ' ') }} FCFA
Bénéficiaire     : {{ $numeroBeneficiaire }}
Idempotency Key  : {{ $idempotencyKey }}
Horodatage       : {{ now()->format('Y-m-d H:i:s') }} UTC

ERREUR PAYNALA
--------------
{{ $errorMessage }}

ACTIONS À FAIRE
---------------
1. Vérifier dans le tableau de bord Paynala si {{ $idempotencyKey }}
   a été exécuté ou non.
2a. Si OUI (Paynala a envoyé l'argent) :
    → Mettre à jour manuellement tondo_payout SET statut='succes'
      WHERE id='{{ $payoutId }}'
2b. Si NON (Paynala n'a rien envoyé) :
    → Remettre le solde :
      UPDATE tondo_cagnottes SET montant_collecte = montant_collecte + {{ $montant }}
      WHERE reference = '{{ $cagnotteReference }}'
    → Mettre à jour tondo_payout SET statut='echec'
      WHERE id='{{ $payoutId }}'

-- Tonji Alertes automatiques
