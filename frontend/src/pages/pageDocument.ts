// Sohbette HTML sayfa (create_page) belgesi. Modelin yazdığı HTML güvenilmeyen koddur; bu kabuk onu yalıtır:
// - iframe sandbox="allow-scripts" + srcdoc (allow-same-origin yok → opak origin: Fortis çerezi, localStorage, üst pencere yok)
// - CSP: ağ kapalı, hiçbir adres yok; script ve stil sadece satır içi (Chart.js Fortis /vendor/'dan okunup gömülür)
// - veri window.FORTIS_DATA: sunucunun bu cevabın sorgularından aldığı maskeli satırlar (model sayı yazmaz)
// - üst pencereyle tek kanal: postMessage {type: "resize", height} ve {type: "ask", text} (onaysız gönderilmez)
// Kabuk modelin içeriğinden önce gelir; sunucu belge etiketlerini (<html>, <head>, <meta>, <base>…) ve /vendor/ dışı
// <script src>/<link> etiketlerini zaten temizler.

export const CHARTJS_PATH = '/vendor/chartjs-4.5.1/chart.umd.min.js'

export type PageData = Record<string, { source: string | null; purpose: string | null; at: string | null; columns: string[]; rows: unknown[][]; total_rows: number; truncated: boolean }>

export interface PageDocumentInput {
  html: string
  data: PageData
  theme: 'light' | 'dark'
  /** Chart.js kaynak kodu (Fortis /vendor/'dan okunur, belgeye satır içi gömülür). */
  chartJs: string
}

/**
 * Sayfa CSP'si: hiçbir adres yok. Kütüphane ve veri satır içi gömüldüğü için ağ tamamen kapalı (IPv6/yerel adres
 * farkı da yok). Model /vendor/ adresi yazsa bile yüklenmez; yardımcılar zaten hazır.
 */
export function pageCsp(): string {
  return [
    "default-src 'none'",
    "script-src 'unsafe-inline'",
    "style-src 'unsafe-inline'",
    'img-src data: blob:',
    "font-src 'none'",
    "connect-src 'none'",
    "form-action 'none'",
    "frame-src 'none'",
    "child-src 'none'",
    "worker-src 'none'",
    "media-src 'none'",
    "manifest-src 'none'",
    "base-uri 'none'",
  ].join('; ')
}

/** <script> içine güvenli JSON: </script> ve satır ayraçları kaçırılır. */
export const safeJson = (value: unknown) =>
  JSON.stringify(value)
    .replace(/</g, '\\u003c')
    .replace(/>/g, '\\u003e')
    .replace(/&/g, '\\u0026')
    .replace(/\u2028/g, '\\u2028')
    .replace(/\u2029/g, '\\u2029')

// Tema: uygulamanın token'larıyla aynı değerler (index.css). Sayfa kendi yazı tipini yükleyemez (ağ kapalı); sistem yazı tipi.
const THEME = `
:root{color-scheme:light;--brand:#b42a3f;--bg:#ffffff;--surface:#ffffff;--surface-raised:#f2f0f0;--border:#ece9e9;--border-strong:#dcd6d7;--text:#150c13;--text-2:#3d3438;--text-muted:#605357;--grid:#f2f0f0;
--series-1:#2b4c7e;--series-2:#c4651f;--series-3:#0f766e;--series-4:#d16ba5;--series-5:#8a5a9e;--series-6:#7c5a3a;--success:#2f6b3f;--warning:#8a5300;--danger:#b42318}
:root[data-theme=dark]{color-scheme:dark;--brand:#d2404f;--bg:#1a1216;--surface:#20171b;--surface-raised:#2a2025;--border:#2f2529;--border-strong:#44383d;--text:#f3edee;--text-2:#d9d0d3;--text-muted:#b5a8ac;--grid:#2f2529;
--series-1:#8db2f0;--series-2:#f0915a;--series-3:#86dcd5;--series-4:#9b7fe0;--series-5:#b9cff5;--series-6:#e3b98a;--success:#6dc48a;--warning:#d9b54a;--danger:#f2797a}
*{box-sizing:border-box}
html,body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
body{padding:16px}
h1,h2,h3{margin:0 0 8px;line-height:1.25}
table.fortis-table{width:100%;border-collapse:collapse;font-size:13px}
table.fortis-table th{text-align:left;font-weight:500;color:var(--text-muted);border-bottom:1px solid var(--border);padding:6px 8px}
table.fortis-table td{border-bottom:1px solid var(--border);padding:6px 8px}
table.fortis-table .num{text-align:right;font-variant-numeric:tabular-nums}
.fortis-chart{position:relative;height:260px;width:100%}
.fortis-chart>canvas{max-height:none!important}
`

// Sayfanın içindeki yardımcılar (modelin kullandığı API). textContent kullanılır; veri HTML olarak yorumlanmaz.
const HELPERS = `
(function(){
  var root = document.documentElement;
  var css = getComputedStyle(root);
  var v = function(n){ return css.getPropertyValue(n).trim(); };
  var colors = [1,2,3,4,5,6].map(function(i){ return v('--series-' + i); });
  var data = window.FORTIS_DATA || {};
  function formatNumber(n, digits){
    if (n === null || n === undefined || n === '') return '—';
    var x = Number(n); if (isNaN(x)) return String(n);
    return new Intl.NumberFormat('tr-TR', { maximumFractionDigits: digits === undefined ? 1 : digits }).format(x);
  }
  function formatDate(s){
    var m = String(s || '').match(/^(\\d{4})-(\\d{2})-(\\d{2})(?:[T ](\\d{2}:\\d{2}))?/);
    if (!m) return String(s === undefined || s === null ? '' : s);
    var d = new Date(+m[1], +m[2] - 1, +m[3]).toLocaleDateString('tr-TR', { day: 'numeric', month: 'short' });
    return m[4] ? d + ' ' + m[4] : d;
  }
  function el(x){ return typeof x === 'string' ? document.querySelector(x) : x; }
  window.fortis = Object.freeze({
    colors: colors,
    data: function(id){
      var q = data[id]; if (!q) return [];
      return q.rows.map(function(r){ var o = {}; q.columns.forEach(function(c, i){ o[c] = r[i]; }); return o; });
    },
    meta: function(id){ var q = data[id]; return q ? { source: q.source, purpose: q.purpose, at: q.at, total_rows: q.total_rows, truncated: q.truncated } : null; },
    formatNumber: formatNumber,
    formatDate: formatDate,
    chart: function(target, config){
      var host = el(target); if (!host || !window.Chart) return null;
      // Aynı kaba yeniden çizim (filtre): mevcut canvas ve grafik yeniden kullanılır.
      var canvas = host.tagName === 'CANVAS' ? host : (host.querySelector(':scope > canvas') || host.appendChild(document.createElement('canvas')));
      var previous = window.Chart.getChart(canvas); if (previous) previous.destroy();
      // Grafik kabın yüksekliğine göre boyutlanır: yüksekliği olmayan kap 0 px kalmasın. Tek başına <canvas> sabit
      // yükseklikli bir kaba sarılır; kendi yüksekliği olmayan kap .fortis-chart (260 px) alır.
      var box = canvas.parentElement;
      if (host.tagName === 'CANVAS' && !(box && box.classList.contains('fortis-chart'))) {
        box = document.createElement('div'); box.className = 'fortis-chart';
        canvas.parentNode.insertBefore(box, canvas); box.appendChild(canvas);
      } else if (host.tagName !== 'CANVAS' && !host.style.height && !host.classList.contains('fortis-chart')) {
        host.classList.add('fortis-chart');
      }
      var Chart = window.Chart;
      Chart.defaults.color = v('--text-muted');
      Chart.defaults.borderColor = v('--grid');
      Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
      Chart.defaults.animation = false;
      Chart.defaults.maintainAspectRatio = false;
      (config.data && config.data.datasets || []).forEach(function(ds, i){
        var c = colors[i % colors.length];
        if (ds.borderColor === undefined) ds.borderColor = c;
        if (ds.backgroundColor === undefined) ds.backgroundColor = config.type === 'line' ? c : c;
        if (config.type === 'line' && ds.borderWidth === undefined) ds.borderWidth = 2;
        if (config.type === 'line' && ds.pointRadius === undefined) ds.pointRadius = 0;
      });
      return new Chart(canvas, config);
    },
    table: function(target, rows, columns){
      var host = el(target); if (!host) return;
      columns = columns || Object.keys(rows[0] || {}).map(function(k){ return { key: k, label: k }; });
      var t = document.createElement('table'); t.className = 'fortis-table';
      var h = t.createTHead().insertRow();
      columns.forEach(function(c){ var th = document.createElement('th'); th.textContent = c.label || c.key; if (c.align === 'right' || c.format === 'number' || c.format === 'percent') th.className = 'num'; h.appendChild(th); });
      var b = t.createTBody();
      rows.forEach(function(r){
        var tr = b.insertRow();
        columns.forEach(function(c){
          var td = tr.insertCell(); var x = r[c.key];
          td.textContent = c.format === 'number' ? formatNumber(x) : c.format === 'percent' ? '%' + formatNumber(x) : c.format === 'date' ? formatDate(x) : (x === null || x === undefined ? '—' : String(x));
          if (c.align === 'right' || c.format === 'number' || c.format === 'percent') td.className = 'num';
        });
      });
      host.textContent = ''; host.appendChild(t);
    },
    ask: function(text){ parent.postMessage({ type: 'ask', text: String(text).slice(0, 500) }, '*'); }
  });
  var last = 0;
  function report(){ var h = Math.ceil(document.documentElement.scrollHeight); if (Math.abs(h - last) > 2) { last = h; parent.postMessage({ type: 'resize', height: h }, '*'); } }
  if (window.ResizeObserver) new ResizeObserver(report).observe(document.documentElement);
  window.addEventListener('load', report);
})();
`

/** Chart.js kaynak kodu (bir kez okunur). */
let chartJsSource: Promise<string> | null = null
export function loadChartJs(): Promise<string> {
  chartJsSource ??= fetch(CHARTJS_PATH).then((r) => {
    if (!r.ok) throw new Error('Grafik kütüphanesi yüklenemedi.')
    return r.text()
  })
  chartJsSource.catch(() => (chartJsSource = null))
  return chartJsSource
}

/** Tam belge (iframe srcdoc ve indirilen tek dosya aynı). */
export function buildPageDocument({ html, data, theme, chartJs }: PageDocumentInput): string {
  return [
    '<!doctype html>',
    `<html lang="tr" data-theme="${theme === 'dark' ? 'dark' : 'light'}">`,
    '<head>',
    `<meta http-equiv="Content-Security-Policy" content="${pageCsp()}">`,
    '<meta charset="utf-8">',
    '<meta name="viewport" content="width=device-width, initial-scale=1">',
    '<meta name="referrer" content="no-referrer">',
    `<style>${THEME}</style>`,
    `<script>${chartJs.replace(/<\/script/gi, '<\\/script')}</script>`,
    `<script>window.FORTIS_DATA = Object.freeze(${safeJson(data)});</script>`,
    `<script>${HELPERS}</script>`,
    '</head>',
    '<body>',
    html,
    '</body>',
    '</html>',
  ].join('\n')
}
