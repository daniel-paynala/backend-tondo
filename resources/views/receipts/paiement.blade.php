<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

html, body {
  font-family: DejaVu Sans, sans-serif;
  font-size: 11px;
  color: #1A1F1E;
  /* Pas de background : évite que DomPDF colorie les pages vierges */
}

.page {
  width: 100%;
  padding: 40px 100px;
  background: #F4ECE0;
  overflow: hidden;
}

/* En-tête */
.header {
  background: #0F4C5C;
  border-radius: 6px;
  padding: 12px 16px;
  margin-bottom: 12px;
}
.brand {
  font-size: 18px;
  font-weight: 700;
  color: #F4ECE0;
  letter-spacing: -0.5px;
}
.brand-icon {
  display: inline-block;
  background: #C97B4A;
  color: #1A1F1E;
  font-size: 12px;
  font-weight: 700;
  width: 19px;
  height: 19px;
  line-height: 19px;
  text-align: center;
  border-radius: 4px;
  margin-right: 5px;
}
.subtitle {
  font-size: 8px;
  color: rgba(244,236,224,0.6);
  letter-spacing: 0.5px;
  margin-top: 3px;
}
.badge {
  display: inline-block;
  background: #6B8E4E;
  color: #fff;
  font-size: 8px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 20px;
  margin-top: 7px;
}

/* Montant */
.amount-block {
  text-align: center;
  margin: 10px 0;
}
.amount-label {
  font-size: 8px;
  color: #0F4C5C;
  text-transform: uppercase;
  letter-spacing: 1px;
  margin-bottom: 3px;
}
.amount-value {
  font-size: 30px;
  font-weight: 700;
  color: #0F4C5C;
}
.amount-currency {
  font-size: 14px;
  color: #C97B4A;
  font-weight: 600;
}
.amount-fees {
  font-size: 8px;
  color: #999;
  margin-top: 2px;
}

.divider {
  border: none;
  border-top: 1px dashed #C97B4A;
  margin: 9px 0;
  opacity: 0.5;
}

/* Cards */
.card {
  background: #fff;
  border-radius: 5px;
  padding: 8px 12px;
  margin-bottom: 8px;
  border: 1px solid rgba(15,76,92,0.08);
}
.card-title {
  font-size: 7px;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: #0F4C5C;
  font-weight: 700;
  margin-bottom: 6px;
  border-bottom: 1px solid #f0ede8;
  padding-bottom: 4px;
}

/*
 * table-layout:fixed  → colonnes fixes, le contenu ne déborde jamais à droite
 * word-break:break-all → coupe les IDs longs (TONDOPAYXXXXXXX) sans espace
 */
table.info {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
}
table.info td {
  padding: 3px 0;
  border-bottom: 1px solid #f5f2ee;
  font-size: 10px;
  vertical-align: middle;
  overflow: hidden;
  word-break: break-all;
}
table.info tr:last-child td { border-bottom: none; }
table.info td.lbl { color: #777; width: 46%; word-break: normal; }
table.info td.val { font-weight: 600; color: #1A1F1E; text-align: right; }
table.info td.val.accent  { color: #C97B4A; }
table.info td.val.primary { color: #0F4C5C; }

.ref-chip {
  display: inline-block;
  background: #0F4C5C;
  color: #F4ECE0;
  font-size: 8px;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 20px;
  max-width: 100%;
  overflow: hidden;
}

/* Pied de page */
.footer {
  text-align: center;
  margin-top: 9px;
  padding-top: 7px;
  border-top: 1px solid rgba(15,76,92,0.1);
}
.footer-brand { font-size: 8px; font-weight: 700; color: #0F4C5C; }
.footer-text  { font-size: 7px; color: #aaa; line-height: 1.5; margin-top: 2px; }
</style>
</head>
<body>
<div class="page">

  <div class="header">
    <div class="brand"><span class="brand-icon">T</span>Tondo</div>
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
