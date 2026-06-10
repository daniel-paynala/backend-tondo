<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

html, body {
  font-family: DejaVu Sans, sans-serif;
  color: #1A1F1E;
  background: #F4ECE0;
  font-size: 10px;
}

.hdr {
  background: #0F4C5C;
  padding: 14px 20px 12px;
}
.brand { font-size: 16px; font-weight: 700; color: #F4ECE0; }
.brand-badge {
  display: inline-block; background: #C97B4A; color: #1A1F1E;
  font-size: 10px; font-weight: 700; width: 18px; height: 18px;
  line-height: 18px; text-align: center; border-radius: 3px;
  margin-right: 5px; vertical-align: middle;
}
.hdr-sub   { font-size: 7px; color: rgba(244,236,224,0.5); letter-spacing: 0.8px; margin-top: 2px; }
.hdr-badge { display: inline-block; background: #6B8E4E; color: #fff; font-size: 7px;
             font-weight: 700; padding: 2px 8px; border-radius: 20px; margin-top: 6px; }

.wrap { padding: 14px 20px 20px; }

.meta { margin-bottom: 14px; }
.meta-titre  { font-size: 14px; font-weight: 700; color: #0F4C5C; margin-bottom: 4px; }
.meta-ref    { font-size: 8px; color: #888; }
.meta-date   { font-size: 8px; color: #888; margin-top: 2px; }

.solde-bloc {
  background: #fff; border-radius: 6px; padding: 10px 14px;
  margin-bottom: 14px; border: 1px solid rgba(15,76,92,0.08);
  display: flex; justify-content: space-between; align-items: center;
}
.solde-lbl { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: #888; }
.solde-val { font-size: 20px; font-weight: 700; color: #0F4C5C; }
.solde-cur { font-size: 10px; font-weight: 600; color: #C97B4A; }

.section-title {
  font-size: 7px; text-transform: uppercase; letter-spacing: 1px;
  color: #0F4C5C; font-weight: 700; margin-bottom: 6px;
}

table.hist {
  width: 100%;
  border-collapse: collapse;
}
table.hist thead th {
  background: #0F4C5C;
  color: #F4ECE0;
  font-size: 7px;
  font-weight: 700;
  padding: 5px 8px;
  text-align: left;
}
table.hist tbody tr:nth-child(even) td { background: #fff; }
table.hist tbody tr:nth-child(odd)  td { background: rgba(15,76,92,0.04); }
table.hist tbody td {
  padding: 4px 8px;
  font-size: 8px;
  border-bottom: 1px solid rgba(15,76,92,0.06);
  vertical-align: middle;
}
table.hist td.montant { font-weight: 700; color: #0F4C5C; text-align: right; white-space: nowrap; }
table.hist td.ref     { font-size: 7px; color: #aaa; word-break: break-all; }

.total-row td { background: rgba(15,76,92,0.08) !important; font-weight: 700; }

.footer {
  text-align: center; margin-top: 16px; padding-top: 8px;
  border-top: 1px solid rgba(15,76,92,0.12);
}
.footer-brand { font-size: 8px; font-weight: 700; color: #0F4C5C; }
.footer-text  { font-size: 7px; color: #bbb; margin-top: 2px; }
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
    <div class="meta-ref">Référence #{{ $cagnotte->reference }} · {{ $cagnotte->type === 'tontine_periodique' ? 'Tontine périodique' : 'Cotisation ouverte' }}</div>
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
      <div style="font-size:18px; font-weight:700; color:#C97B4A">{{ $paiements->count() }}</div>
    </div>
  </div>

  <div class="section-title">Détail des paiements</div>

  @if($paiements->isEmpty())
    <p style="color:#888; font-size:9px; text-align:center; padding:20px 0">Aucune transaction confirmée.</p>
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
        <td colspan="3" style="text-align:right; font-size:8px; color:#0F4C5C">TOTAL</td>
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
