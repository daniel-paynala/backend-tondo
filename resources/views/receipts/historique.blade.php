<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

html, body {
  font-family: DejaVu Sans, sans-serif;
  font-size: 30px;
  color: #1A1F1E;
  background: #fff;
}

.hdr {
  background: #0F4C5C;
  padding: 28px 36px 24px;
}
.brand {
  font-size: 42px;
  font-weight: 700;
  color: #F4ECE0;
}
.brand-badge {
  display: inline-block;
  background: #C97B4A;
  color: #1A1F1E;
  font-size: 28px;
  font-weight: 700;
  width: 44px;
  height: 44px;
  line-height: 44px;
  text-align: center;
  border-radius: 5px;
  margin-right: 10px;
  vertical-align: middle;
}
.hdr-sub {
  font-size: 20px;
  color: rgba(244,236,224,0.75);
  letter-spacing: 0.5px;
  margin-top: 8px;
}
.hdr-badge {
  display: inline-block;
  background: #6B8E4E;
  color: #fff;
  font-size: 20px;
  font-weight: 700;
  padding: 5px 18px;
  border-radius: 24px;
  margin-top: 12px;
}

.wrap { padding: 32px 36px 40px; }

.meta { margin-bottom: 28px; }
.meta-titre  { font-size: 36px; font-weight: 700; color: #0F4C5C; margin-bottom: 8px; }
.meta-ref    { font-size: 26px; color: #555; }
.meta-date   { font-size: 24px; color: #777; margin-top: 6px; }

.solde-bloc {
  background: rgba(15,76,92,0.06);
  border-radius: 8px;
  padding: 22px 28px;
  margin-bottom: 28px;
  border: 2px solid rgba(15,76,92,0.12);
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.solde-lbl { font-size: 22px; text-transform: uppercase; letter-spacing: 0.8px; color: #777; }
.solde-val { font-size: 48px; font-weight: 700; color: #0F4C5C; }
.solde-cur { font-size: 28px; font-weight: 600; color: #C97B4A; }

.section-title {
  font-size: 24px;
  text-transform: uppercase;
  letter-spacing: 0.8px;
  color: #0F4C5C;
  font-weight: 700;
  margin-bottom: 14px;
}

table.hist {
  width: 100%;
  border-collapse: collapse;
}
table.hist thead th {
  background: #0F4C5C;
  color: #F4ECE0;
  font-size: 24px;
  font-weight: 700;
  padding: 12px 16px;
  text-align: left;
}
table.hist tbody tr:nth-child(even) td { background: #fff; }
table.hist tbody tr:nth-child(odd)  td { background: rgba(15,76,92,0.04); }
table.hist tbody td {
  padding: 12px 16px;
  font-size: 28px;
  border-bottom: 1px solid rgba(15,76,92,0.10);
  vertical-align: middle;
}
table.hist td.montant {
  font-weight: 700;
  color: #0F4C5C;
  text-align: right;
  white-space: nowrap;
}
table.hist td.ref {
  font-size: 22px;
  color: #888;
  word-break: break-all;
}

.total-row td {
  background: rgba(15,76,92,0.10) !important;
  font-weight: 700;
  font-size: 30px;
}

.footer {
  text-align: center;
  margin-top: 36px;
  padding-top: 16px;
  border-top: 2px solid rgba(15,76,92,0.15);
}
.footer-brand { font-size: 28px; font-weight: 700; color: #0F4C5C; }
.footer-text  { font-size: 24px; color: #888; margin-top: 6px; line-height: 1.5; }
</style>
</head>
<body>

<div class="hdr">
  <div class="brand"><span class="brand-badge">T</span>Tonji</div>
  <div class="hdr-sub">HISTORIQUE DES TRANSACTIONS · PAYNALA SAS</div>
  <div class="hdr-badge">📊 RAPPORT GÉRANT</div>
</div>

<div class="wrap">

  <div class="meta">
    <div class="meta-titre">{{ $cagnotte->titre }}</div>
    <div class="meta-ref">Référence N°{{ $cagnotte->reference }} · {{ $cagnotte->type === 'tontine_periodique' ? 'Tontine périodique' : 'Cotisation ouverte' }}</div>
    <div class="meta-date">Généré le {{ $date }}</div>
  </div>

  <div class="solde-bloc">
    <div>
      <div class="solde-lbl">Total collecté</div>
      <span class="solde-val">{{ number_format($total, 0, ',', ' ') }}</span>
      <span class="solde-cur"> FCFA</span>
    </div>
    <div style="text-align:right">
      <div class="solde-lbl">Transactions</div>
      <div style="font-size:46px; font-weight:700; color:#C97B4A">{{ $paiements->count() }}</div>
    </div>
  </div>

  <div class="section-title">Détail des paiements</div>

  @if($paiements->isEmpty())
    <p style="color:#888; font-size:28px; text-align:center; padding:40px 0">Aucune transaction confirmée.</p>
  @else
  <table class="hist">
    <thead>
      <tr>
        <th>Date</th>
        <th>Cotisant</th>
        <th>Référence</th>
        <th style="text-align:right">Montant</th>
      </tr>
    </thead>
    <tbody>
      @foreach($paiements as $p)
      <tr>
        <td style="white-space:nowrap">{{ \Carbon\Carbon::parse($p->updated_at)->format('d/m/Y H:i') }}</td>
        <td>{{ $p->cotisant }}</td>
        <td class="ref">{{ $p->trans_id }}</td>
        <td class="montant">{{ number_format((int)$p->montant, 0, ',', ' ') }} FCFA</td>
      </tr>
      @endforeach
      <tr class="total-row">
        <td colspan="3" style="text-align:right; color:#0F4C5C">TOTAL</td>
        <td class="montant" style="color:#0F4C5C">{{ number_format($total, 0, ',', ' ') }} FCFA</td>
      </tr>
    </tbody>
  </table>
  @endif

  <div class="footer">
    <div class="footer-brand">Tonji · Paynala SAS</div>
    <div class="footer-text">
      Document généré automatiquement — usage interne gérant.<br>
      support@tonji.ga &nbsp;·&nbsp; www.tonji.ga &nbsp;·&nbsp; Libreville, Gabon
    </div>
  </div>

</div>
</body>
</html>
