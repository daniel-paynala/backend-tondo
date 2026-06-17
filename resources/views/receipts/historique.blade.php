<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

body {
  font-family: DejaVu Sans, sans-serif;
  font-size: 12px;
  color: #1a1a1a;
  background: #fff;
}

/* ── Filigrane ────────────────────────────────────── */
.watermark {
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 0;
  pointer-events: none;
}
.watermark-text {
  font-size: 60px;
  font-weight: 900;
  color: #0A6847;
  opacity: 0.06;
  letter-spacing: 14px;
  text-transform: uppercase;
  white-space: nowrap;
}

.page { position: relative; z-index: 1; }

/* ── En-tête ─────────────────────────────────────── */
.hdr {
  padding: 14px 14px 10px;
  border-bottom: 3px solid #111;
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
}
.brand {
  font-size: 25px;
  font-weight: 900;
  color: #0A6847;
  letter-spacing: -0.5px;
  line-height: 1;
}
.brand-tagline {
  font-size: 8px;
  text-transform: uppercase;
  letter-spacing: 1.2px;
  color: #888;
  margin-top: 3px;
}
.hdr-right { text-align: right; }
.hdr-label {
  font-size: 8px;
  text-transform: uppercase;
  letter-spacing: 0.8px;
  color: #999;
}
.hdr-ref {
  font-size: 18px;
  font-weight: 900;
  color: #111;
  letter-spacing: 1px;
}

/* ── Identité cagnotte ────────────────────────────── */
.meta {
  padding: 10px 14px 8px;
  border-bottom: 1px dashed #ccc;
  background: #f9f9f9;
}
.meta-titre {
  font-size: 20px;
  font-weight: 700;
  color: #0A6847;
  line-height: 1.2;
}
.meta-type {
  font-size: 9px;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: #888;
  margin-top: 4px;
}

/* ── Bloc total collecté ──────────────────────────── */
.total-bloc {
  background: #0A6847;
  color: #fff;
  padding: 12px 14px 10px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.total-left .total-lbl {
  font-size: 9px;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  opacity: 0.75;
}
.total-left .total-val {
  font-size: 25px;
  font-weight: 900;
  line-height: 1.1;
  margin-top: 2px;
}
.total-right { text-align: right; }
.total-right .count-lbl {
  font-size: 9px;
  text-transform: uppercase;
  letter-spacing: 1px;
  opacity: 0.75;
}
.total-right .count-val {
  font-size: 22px;
  font-weight: 900;
  color: #E8A830;
  line-height: 1.1;
  margin-top: 2px;
}

/* ── Tableau ─────────────────────────────────────── */
.section-lbl {
  font-size: 8px;
  text-transform: uppercase;
  letter-spacing: 1.2px;
  color: #aaa;
  padding: 9px 14px 3px;
}

table.hist {
  width: 100%;
  border-collapse: collapse;
}
table.hist thead th {
  background: #efefef;
  font-size: 9px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.6px;
  color: #666;
  padding: 6px 10px;
  text-align: left;
  border-bottom: 1px solid #ddd;
}
table.hist thead th.r { text-align: right; }

table.hist tbody tr:nth-child(odd)  td { background: #fafafa; }
table.hist tbody tr:nth-child(even) td { background: #fff; }

table.hist tbody td {
  padding: 7px 10px;
  font-size: 12px;
  border-bottom: 1px solid #eee;
  vertical-align: middle;
}
table.hist td.nom {
  font-weight: 700;
  color: #111;
  font-size: 12px;
}
table.hist td.date {
  font-size: 10px;
  color: #888;
  white-space: nowrap;
}
table.hist td.montant {
  font-size: 13px;
  font-weight: 900;
  color: #0A6847;
  text-align: right;
  white-space: nowrap;
}

/* ── Pied de page ─────────────────────────────────── */
.footer {
  text-align: center;
  padding: 10px 14px 12px;
  border-top: 2px solid #111;
  margin-top: 12px;
}
.footer-brand {
  font-size: 14px;
  font-weight: 900;
  color: #0A6847;
}
.footer-info {
  font-size: 8px;
  color: #bbb;
  margin-top: 4px;
  line-height: 1.7;
}
.footer-anti-falsif {
  font-size: 7px;
  color: #ccc;
  margin-top: 6px;
  font-style: italic;
}
</style>
</head>
<body>

{{-- Filigrane --}}
<div class="watermark">
  <div class="watermark-text">TONJI</div>
</div>

<div class="page">

  {{-- En-tête --}}
  <div class="hdr">
    <div>
      <div class="brand">Tonji</div>
      <div class="brand-tagline">Tontines &amp; cotisations · Paynala SAS</div>
    </div>
    <div class="hdr-right">
      <div class="hdr-label">Référence</div>
      <div class="hdr-ref">N°&nbsp;{{ $cagnotte->reference }}</div>
    </div>
  </div>

  {{-- Identité cagnotte --}}
  <div class="meta">
    <div class="meta-titre">{{ $cagnotte->titre }}</div>
    <div class="meta-type">{{ $cagnotte->type === 'tontine_periodique' ? 'Tontine périodique' : 'Cotisation ouverte' }}</div>
  </div>

  {{-- Total --}}
  <div class="total-bloc">
    <div class="total-left">
      <div class="total-lbl">Total collecté</div>
      <div class="total-val">{{ number_format($total, 0, ',', ' ') }}&nbsp;FCFA</div>
    </div>
    <div class="total-right">
      <div class="count-lbl">Transactions</div>
      <div class="count-val">{{ $paiements->count() }}</div>
    </div>
  </div>

  {{-- Transactions --}}
  @if($paiements->isNotEmpty())
  <div class="section-lbl">Détail des paiements</div>
  <table class="hist">
    <thead>
      <tr>
        <th>Cotisant</th>
        <th>Date</th>
        <th class="r">Montant</th>
      </tr>
    </thead>
    <tbody>
      @foreach($paiements as $p)
      <tr>
        <td class="nom">{{ $p->cotisant }}</td>
        <td class="date">{{ \Carbon\Carbon::parse($p->updated_at)->format('d/m/y H:i') }}</td>
        <td class="montant">{{ number_format((int)$p->montant, 0, ',', ' ') }}&nbsp;F</td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @else
  <p style="text-align:center;font-size:11px;color:#ccc;padding:20px">Aucune transaction confirmée.</p>
  @endif

  {{-- Pied --}}
  <div class="footer">
    <div class="footer-brand">Tonji &middot; Paynala SAS</div>
    <div class="footer-info">
      Généré le {{ $date }} &nbsp;·&nbsp; support@tonji.ga &nbsp;·&nbsp; Libreville, Gabon
    </div>
    <div class="footer-anti-falsif">
      Document officiel Tonji — toute falsification est passible de poursuites.
    </div>
  </div>

</div>{{-- .page --}}

</body>
</html>
