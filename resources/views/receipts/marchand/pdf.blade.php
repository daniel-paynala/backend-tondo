{{--
  Reçu PDF d'un paiement marchand.

  DomPDF ne connaît ni flexbox ni grid : la mise en page passe par des tables
  et des styles en ligne. Ce n'est pas de la négligence, c'est la contrainte
  du moteur — toute tentative de faire moderne finit en colonnes écrasées.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Reçu {{ $reference }}</title>
  <style>
    @page { margin: 28px 32px; }
    body  { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #14202E; }
    .entete     { border-bottom: 2px solid #0A6847; padding-bottom: 10px; }
    .titre      { font-size: 15px; font-weight: bold; color: #0A6847; }
    .ref        { font-family: DejaVu Sans Mono, monospace; font-size: 13px;
                  font-weight: bold; letter-spacing: 1px; }
    .montant    { font-size: 26px; font-weight: bold; color: #0A6847; }
    .bloc       { border: 1px solid #E8EDE9; border-radius: 6px; padding: 10px 12px; margin-top: 12px; }
    .bloc h2    { font-size: 10px; text-transform: uppercase; letter-spacing: 1px;
                  color: #4A5568; margin: 0 0 6px; }
    table.kv    { width: 100%; border-collapse: collapse; }
    table.kv td { padding: 3px 0; vertical-align: top; }
    td.k        { color: #4A5568; width: 38%; }
    td.v        { font-weight: bold; }
    .pied       { margin-top: 16px; border-top: 1px solid #E8EDE9; padding-top: 8px;
                  font-size: 9px; color: #8A94A0; }
  </style>
</head>
<body>

<table class="entete" width="100%">
  <tr>
    <td>
      @if($logo_data_uri)<img src="{{ $logo_data_uri }}" height="26" alt="Tonji">@endif
      <div class="titre">Reçu de paiement</div>
    </td>
    <td align="right">
      <div style="color:#4A5568">Référence</div>
      <div class="ref">{{ $reference }}</div>
    </td>
  </tr>
</table>

<table width="100%" style="margin-top:14px">
  <tr>
    <td>
      <div style="color:#4A5568">Montant payé</div>
      <div class="montant">{{ $montant_affiche }} <span style="font-size:13px">FCFA</span></div>
      <div style="color:#4A5568;margin-top:2px">{{ $date_heure }}</div>
    </td>
    <td align="right" width="110">
      {{-- Le QR pointe vers la page publique : c'est ce qui rend le reçu
           contrôlable sans nous appeler. --}}
      <img src="{{ $qr_data_uri }}" width="96" alt="Vérifier ce reçu">
      <div style="font-size:8px;color:#8A94A0;text-align:center">Scannez pour vérifier</div>
    </td>
  </tr>
</table>

<div class="bloc">
  <h2>Payé à</h2>
  <table class="kv">
    <tr><td class="k">Commerce</td><td class="v">{{ $marchand_nom }}</td></tr>
    @if($marchand_code)
    <tr><td class="k">Code marchand</td><td class="v">{{ $marchand_code }}</td></tr>
    @endif
    @if($marchand_ville)
    <tr><td class="k">Ville</td><td class="v">{{ $marchand_ville }}</td></tr>
    @endif
    <tr><td class="k">Numéro</td><td class="v">{{ $marchand_numero }}</td></tr>
    @if($marchand_titulaire)
    {{-- Titulaire vérifié à l'enregistrement de la fiche : c'est le nom chez
         qui l'argent arrive réellement, et la pièce qui tranche une
         contestation. --}}
    <tr><td class="k">Compte au nom de</td><td class="v">{{ $marchand_titulaire }}</td></tr>
    @endif
  </table>
</div>

<div class="bloc">
  <h2>Payé par</h2>
  <table class="kv">
    <tr><td class="k">Client</td><td class="v">{{ $payeur }}</td></tr>
    @if($payeur_numero)
    <tr><td class="k">Numéro</td><td class="v">{{ $payeur_numero }}</td></tr>
    @endif
    <tr><td class="k">Cagnotte</td><td class="v">{{ $cagnotte_titre }}</td></tr>
    <tr><td class="k">N° de cagnotte</td><td class="v">{{ $cagnotte_reference }}</td></tr>
  </table>
</div>

<div class="pied">
  Référence complète : {{ $trans_id }}@if($operateur_id) · Identifiant opérateur : {{ $operateur_id }}@endif<br>
  Vérifiable à tout moment sur {{ $qr_url }}<br>
  Tonji — service édité par Paynala. Ce reçu atteste d'un transfert exécuté ; il ne vaut pas facture.
</div>

</body>
</html>
