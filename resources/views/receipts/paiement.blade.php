<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

body {
  font-family: DejaVu Sans, sans-serif;
  font-size: 11px;
  color: #1A1F1E;
  background: #F4ECE0;
}

.page {
  width: 100%;
  padding: 24px 28px;
  background: #F4ECE0;
}

/* En-tête */
.header {
  background: #0F4C5C;
  border-radius: 8px;
  padding: 16px 20px;
  margin-bottom: 16px;
}
.brand {
  font-size: 20px;
  font-weight: 700;
  color: #F4ECE0;
  letter-spacing: -0.5px;
}
.brand span {
  display: inline-block;
  background: #C97B4A;
  color: #1A1F1E;
  font-size: 14px;
  font-weight: 700;
  width: 22px;
  height: 22px;
  line-height: 22px;
  text-align: center;
  border-radius: 5px;
  margin-right: 6px;
}
.subtitle {
  font-size: 9px;
  color: rgba(244,236,224,0.6);
  letter-spacing: 0.5px;
  margin-top: 3px;
}
.badge {
  display: inline-block;
  background: #6B8E4E;
  color: #fff;
  font-size: 9px;
  font-weight: 700;
  padding: 3px 10px;
  border-radius: 20px;
  margin-top: 10px;
}

/* Montant */
.amount-block {
  text-align: center;
  margin: 14px 0;
}
.amount-label {
  font-size: 9px;
  color: #0F4C5C;
  text-transform: uppercase;
  letter-spacing: 1px;
  margin-bottom: 4px;
}
.amount-value {
  font-size: 34px;
  font-weight: 700;
  color: #0F4C5C;
}
.amount-currency {
  font-size: 16px;
  color: #C97B4A;
  font-weight: 600;
}
.amount-fees {
  font-size: 9px;
  color: #999;
  margin-top: 2px;
}

.divider {
  border: none;
  border-top: 1px dashed #C97B4A;
  margin: 12px 0;
  opacity: 0.5;
}

/* Cards */
.card {
  background: #fff;
  border-radius: 6px;
  padding: 10px 14px;
  margin-bottom: 10px;
  border: 1px solid rgba(15,76,92,0.08);
}
.card-title {
  font-size: 8px;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: #0F4C5C;
  font-weight: 700;
  margin-bottom: 8px;
  border-bottom: 1px solid #f0ede8;
  padding-bottom: 5px;
}

/* Lignes d'info — table pour éviter overflow flex */
table.info {
  width: 100%;
  border-collapse: collapse;
}
table.info td {
  padding: 4px 0;
  border-bottom: 1px solid #f5f2ee;
  font-size: 10px;
  vertical-align: middle;
}
table.info tr:last-child td { border-bottom: none; }
table.info td.lbl { color: #777; width: 48%; }
table.info td.val { font-weight: 600; color: #1A1F1E; text-align: right; }
table.info td.val.accent { color: #C97B4A; }
table.info td.val.primary { color: #0F4C5C; }

.ref-chip {
  background: #0F4C5C;
  color: #F4ECE0;
  font-size: 9px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 20px;
}

/* Pied de page */
.footer {
  text-align: center;
  margin-top: 12px;
  padding-top: 10px;
  border-top: 1px solid rgba(15,76,92,0.1);
}
.footer-brand { font-size: 9px; font-weight: 700; color: #0F4C5C; }
.footer-text  { font-size: 8px; color: #aaa; line-height: 1.5; margin-top: 2px; }
</style>
</head>
<body>
<div class="page">

  <div class="header">
    <div class="brand"><span>T</span>Tondo</div>
    <div class="subtitle">REÇU DE PAIEMENT · PAYNALA SAS</div>
    <div class="badge">✓ PAIEMENT CONFIRMÉ</div>
  </div>

  <div class="amount-block">
    <div class="amount-label">Montant cotisé</div>
    <span class="amount-value">{{ number_format($montant_net, 0, ',', ' ') }}</span>
    <span class="amount-currency"> FCFA</span>
    <div class="amount-fees">Frais à la charge du cotisant</div>
  </div>

  <hr class="divider">

  <div class="card">
    <div class="card-title">Transaction</div>
    <table class="info">
      <tr>
        <td class="lbl">Référence</td>
        <td class="val accent">{{ $trans_id }}</td>
      </tr>
      <tr>
        <td class="lbl">Date &amp; heure</td>
        <td class="val">{{ $date_heure }}</td>
      </tr>
      <tr>
        <td class="lbl">Canal</td>
        <td class="val">{{ $canal }}</td>
      </tr>
      @if($montant_brut !== $montant_net)
      <tr>
        <td class="lbl">Montant débité</td>
        <td class="val">{{ number_format($montant_brut, 0, ',', ' ') }} FCFA</td>
      </tr>
      @endif
    </table>
  </div>

  <div class="card">
    <div class="card-title">Cagnotte</div>
    <table class="info">
      <tr>
        <td class="lbl">Nom</td>
        <td class="val primary">{{ $cagnotte_titre }}</td>
      </tr>
      <tr>
        <td class="lbl">Référence</td>
        <td class="val"><span class="ref-chip"># {{ $cagnotte_reference }}</span></td>
      </tr>
      <tr>
        <td class="lbl">Type</td>
        <td class="val">{{ $type_cagnotte }}</td>
      </tr>
    </table>
  </div>

  <div class="card">
    <div class="card-title">Cotisant</div>
    <table class="info">
      <tr>
        <td class="lbl">Nom complet</td>
        <td class="val">{{ $nom_cotisant }}</td>
      </tr>
      <tr>
        <td class="lbl">Mobile Money</td>
        <td class="val">{{ $numero_masque }}</td>
      </tr>
    </table>
  </div>

  <div class="footer">
    <div class="footer-brand">Tondo · Paynala SAS</div>
    <div class="footer-text">
      Reçu généré automatiquement · support@tondo.ga · tondo.ga
    </div>
  </div>

</div>
</body>
</html>
