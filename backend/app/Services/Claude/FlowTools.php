<?php

namespace App\Services\Claude;

use App\Agents\AgentActions;
use App\Agents\AgentSession;
use App\Agents\Architect;
use App\Ai\AiDataGate;
use App\Ai\DataQueryTools;
use App\Models\Agent;
use App\Models\Artifact;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Execution;
use App\Models\FeatureRequest;
use App\Models\Flow;
use App\Models\FlowProposal;
use App\Models\Report;
use App\Services\Apm\ApmApi;
use App\Services\Artifacts\ArtifactException;
use App\Services\Artifacts\ArtifactService;
use App\Services\Auth\Audit;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Flows\Catalog;
use App\Services\Flows\ExecutionException;
use App\Services\Flows\ExecutionService;
use App\Services\Flows\FlowService;
use App\Services\Flows\FlowValidator;
use App\Services\Kestra\KestraClient;
use App\Services\Kestra\KestraException;
use App\Services\Reports\ReportException;
use App\Services\Reports\ReportService;
use App\Services\Reports\ReportSources;
use App\Services\SuccessFactors\SuccessFactors;
use App\Services\Warehouse\Warehouse;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Claude'a verilen araçlar. Hiçbiri Kestra'ya yazmaz: yükleme, çalıştırma, silme ve aktif/pasif yapma
 * kullanıcının akış kartındaki butonlarıyla yapılır. Claude'un yapabileceği en ileri adım akışı önermektir.
 */
class FlowTools
{
    /**
     * Dış sistemden veri döndüren araçlar. Sonuçları Claude'a gitmeden önce execute() içinde AiDataGate'ten geçer;
     * bu listeye girmeyen bir araç dış sistem verisi döndürmemelidir (testle korunur).
     */
    public const OWN_EMAIL_TOKEN = 'KULLANICI_EPOSTASI';

    public const EXTERNAL_DATA_TOOLS = ['preview_apm', 'preview_successfactors', ...DataQueryTools::TOOLS];

    /** Bir kullanıcı mesajı içinde başarısız doğrulama sayısı sınırı (kendini düzeltme döngüsü). */
    public const MAX_FAILED_VALIDATIONS = 3;

    private ?Conversation $conversation = null;

    private int $failedValidations = 0;

    public function __construct(
        private readonly Catalog $catalog,
        private readonly FlowValidator $validator,
        private readonly FlowService $flows,
        private readonly KestraClient $kestra,
        private readonly ExecutionService $executions,
        private readonly Warehouse $warehouse,
        private readonly SuccessFactors $successFactors,
        private readonly ApmApi $apm,
        private readonly ReportService $reports,
        private readonly ReportSources $reportSources,
        private readonly ArtifactService $artifacts,
        private readonly DataQueryTools $dataQueries,
        private readonly AiDataGate $gate,
    ) {}

    /** Agent kipi: araç listesi agent'ın araçlarıyla sınırlı, yazma araçları agent_actions üzerinden. */
    private ?AgentSession $agent = null;

    public function useAgent(AgentSession $session): void
    {
        $this->agent = $session;
    }

    /** Architect kipi: agent kuran sohbet; araçlar Architect::TOOLS, propose_agent bu agent'ı kaydeder. */
    private ?Agent $architect = null;

    public function useArchitect(Agent $agent): void
    {
        $this->architect = $agent;
    }

    /** Her kullanıcı mesajının başında çağrılır: sohbeti bağlar ve deneme sayacını sıfırlar. */
    public function startTurn(Conversation $conversation): void
    {
        $this->conversation = $conversation;
        $this->failedValidations = 0;
        $this->dataQueries->startTurn();
    }

    /**
     * Araç tanımları. Girdiler streaming sırasında canlı akar (eagerInputStreaming); bu modda API girdiyi
     * doğrulamadığı için her girdi execute() içinde ayrıca doğrulanır.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        $yaml = ['type' => 'string', 'description' => 'Kestra akışının tam YAML metni.'];

        // Veri sorgulama ayarı kapalıyken query_* araçları hiç sunulmaz.
        $dataTools = $this->dataQueries->enabled() ? $this->dataQueries->definitions() : [];
        $disabled = $this->disabledTools();

        $all = [...$this->flowDefinitions($yaml), self::askUserDefinition(), ...$dataTools];

        if ($this->architect !== null) {
            $all = [...array_filter($all, fn (array $tool) => in_array($tool['name'], Architect::TOOLS, true)), Architect::definition()];
        }

        // Agent: sadece agent'ın araçları (okuma) + yazma araçları (agent_actions üzerinden).
        if ($this->agent !== null) {
            $all = [...array_filter($all, fn (array $tool) => $this->agent->allows($tool['name'])), ...AgentActions::definitions($this->agent->writeTools())];
        }

        return array_values(array_filter($all, fn (array $tool) => ! in_array($tool['name'], $disabled, true)));
    }

    /**
     * Kullanıcının bu sohbette kapattığı kaynakların araçları (kaynak seçici). Claude'a sunulmaz, çağrılırsa reddedilir.
     *
     * @return list<string>
     */
    public function disabledTools(): array
    {
        $sources = config('connectors.sources');

        // Sohbette kullanıcının kapattıkları + yöneticinin şirket genelinde kapattıkları (A4.1).
        return array_values(array_unique(array_merge(app(ConnectorRegistry::class)->disabledTools(), ...array_map(
            fn (string $key) => $sources[$key]['tools'] ?? [],
            $this->conversation?->disabledSources() ?? [],
        ))));
    }

    /**
     * Claude'un kullanıcıya çoktan seçmeli soru sorması. Bu araç çalıştırılmaz: ClaudeChat turu durdurur, kullanıcının
     * cevabı bu aracın sonucu olarak gider.
     *
     * @return array<string, mixed>
     */
    public static function askUserDefinition(): array
    {
        return [
            'name' => 'ask_user',
            'description' => 'Kullanıcıdan bir karar gerektiğinde çoktan seçmeli soru sor. Sadece birden fazla makul yorum varsa ve yanlış seçim cevabı önemli ölçüde değiştirecekse kullan; bir cevapta en fazla bir kez. Tur durur, kullanıcının seçimi bu aracın sonucu olarak döner. En fazla 3 soru, her soruda en fazla 5 seçenek.',
            'eagerInputStreaming' => true,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'questions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'question' => ['type' => 'string', 'description' => 'Kısa soru, ör. "Kötü performans" nasıl tanımlansın?'],
                                'context' => ['type' => 'string', 'description' => 'Neden sorduğun, bir iki cümle (ör. "Dün 212 uçuş buldum; sıralamadan önce tanımı netleştirmem gerekiyor.").'],
                                'multi_select' => ['type' => 'boolean'],
                                'options' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'label' => ['type' => 'string'],
                                            'source' => ['type' => 'string', 'description' => 'Seçeneğin dayanağı, kısa (ör. APM, Tanım).'],
                                        ],
                                        'required' => ['label'],
                                    ],
                                ],
                                'allow_other' => ['type' => 'boolean', 'description' => 'Serbest metin cevabına izin ver.'],
                            ],
                            'required' => ['question', 'options'],
                        ],
                    ],
                ],
                'required' => ['questions'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * ask_user girdisini doğrular ve temizler.
     *
     * @param  array<string, mixed>  $input
     * @return array{questions: list<array{question: string, context: string|null, multi_select: bool, allow_other: bool, options: list<array{label: string, source: string|null}>}>}|string Hata varsa mesaj
     */
    public static function normalizeQuestions(array $input): array|string
    {
        $questions = $input['questions'] ?? null;

        if (! is_array($questions) || $questions === [] || ! array_is_list($questions)) {
            return 'questions en az bir soru içeren bir liste olmalı.';
        }
        if (count($questions) > 3) {
            return 'En fazla 3 soru sorulabilir.';
        }

        $clean = [];
        foreach ($questions as $q) {
            $options = is_array($q['options'] ?? null) ? $q['options'] : [];
            if (! is_string($q['question'] ?? null) || trim($q['question']) === '' || $options === []) {
                return 'Her soruda question (metin) ve en az bir seçenek olmalı.';
            }
            if (count($options) > 5) {
                return 'Bir soruda en fazla 5 seçenek olabilir.';
            }

            $clean[] = [
                'question' => mb_substr(trim($q['question']), 0, 300),
                'context' => is_string($q['context'] ?? null) && trim($q['context']) !== '' ? mb_substr(trim($q['context']), 0, 500) : null,
                'multi_select' => (bool) ($q['multi_select'] ?? false),
                'allow_other' => (bool) ($q['allow_other'] ?? true),
                'options' => array_values(array_map(fn ($o) => [
                    'label' => mb_substr(trim((string) (is_array($o) ? ($o['label'] ?? '') : $o)), 0, 200),
                    'source' => is_array($o) && is_string($o['source'] ?? null) ? mb_substr($o['source'], 0, 40) : null,
                ], $options)),
            ];
        }

        return ['questions' => $clean];
    }

    /**
     * @param  array<string, string>  $yaml
     * @return list<array<string, mixed>>
     */
    private function flowDefinitions(array $yaml): array
    {
        return [
            [
                'name' => 'get_catalog',
                'description' => 'İzinli görev ve tetikleyici tiplerinin açıklamalarını, örnek YAML parçalarını ve Kestra\'da tanımlı secret isimlerini getirir. Akış yazmadan önce kullanacağın tiplerin ayrıntısını buradan al.',
                'eagerInputStreaming' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'types' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Ayrıntısı istenen tipler. Boş bırakılırsa tüm katalog döner.',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'validate_flow',
                'description' => 'Bir akış YAML\'ını doğrular: YAML yapısı, zorunlu alanlar, namespace, izinli tipler, tanımlı secret\'lar ve Kestra\'nın kendi kontrolü. Hata varsa düzeltip tekrar doğrula.',
                'eagerInputStreaming' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['yaml' => $yaml],
                    'required' => ['yaml'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'propose_flow',
                'description' => 'Doğrulanmış akışı kullanıcıya kart olarak gösterir: başlık, sade dille özet, tetikleyici, uyarılı adımlar ve YAML. Kullanıcı kartı görmeden hiçbir şey Kestra\'ya gönderilmez. Akış doğrulamadan geçmezse kart gösterilmez ve hatalar döner.',
                'eagerInputStreaming' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Kısa Türkçe başlık. Örnek: "İstanbul rüzgar uyarısı".'],
                        'summary' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Akışın ne yaptığını teknik olmayan dille anlatan 2-6 madde.',
                        ],
                        'yaml' => $yaml,
                    ],
                    'required' => ['title', 'summary', 'yaml'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'list_flows',
                'description' => 'Kestra\'daki mevcut akışları (sadece chat namespace\'i) listeler.',
                'eagerInputStreaming' => true,
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false],
            ],
            [
                'name' => 'get_execution',
                'description' => 'Bir çalışmanın durumunu, adımlarını ve loglarını getirir. execution_id verilirse o çalışma, flow_id verilirse o akışın en son çalışması döner. Kullanıcı bir çalışmanın sonucunu veya neden hata verdiğini sorduğunda kullan.',
                'eagerInputStreaming' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'execution_id' => ['type' => 'string', 'description' => 'Kestra çalışma id\'si.'],
                        'flow_id' => ['type' => 'string', 'description' => 'Akışın id\'si; en son çalışması getirilir.'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'get_queries',
                'description' => 'IBS veri ambarı için onaylı hazır sorguları getirir: ad, açıklama, parametreler, dönen sütunlar ve SQL. Veri ambarından veri gereken her akışta önce buna bak; uygun sorgu varsa SQL\'ini birebir kopyala.',
                'eagerInputStreaming' => true,
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false],
            ],
            [
                'name' => 'describe_warehouse',
                'description' => 'IBS veri ambarının yapısını getirir. tables verilmezse genel kurallar ve tüm tabloların listesi (açıklama, yaklaşık satır sayısı); tables verilirse o tabloların sütunları ve indeksleri. Serbest SQL yazmadan önce kullanacağın tabloların sütunlarını ve indekslerini buradan kontrol et; sütun adı uydurma.',
                'eagerInputStreaming' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'tables' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Ayrıntısı istenen tablo adları (en fazla 8).'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'describe_successfactors',
                'description' => 'SAP SuccessFactors (İK sistemi: çalışanlar, departmanlar, şirketler, lokasyonlar, pozisyonlar) için bilgi getirir. entity verilmezse bağlantı, OData kuralları ve hazır istekler (önce bunlara bak); entity verilirse o varlığın alanları (SAP etiketleriyle) ve $expand ile getirilebilen ilişkileri. Alan adı uydurma.',
                'eagerInputStreaming' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'entity' => ['type' => 'string', 'description' => 'Varlık adı, ör. EmpJob, FODepartment, PerPerson, User.'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'preview_successfactors',
                'description' => 'Bir SuccessFactors okuma isteğini gerçek sistemde dener ve toplam kayıt sayısını, ilk 5 kaydı (akıştaki yanıtla aynı yapıda) veya SuccessFactors\'un hata mesajını döndürür. Serbest bir istek yazdığında propose_flow\'dan önce mutlaka dene: alan adları, filtre, $expand ve jq\'da kullanacağın yol doğru mu gör. Şablon ({{ }}) kullanma, tarihleri somut yaz.',
                'eagerInputStreaming' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => "Taban adresten sonraki kısım, ör. EmpJob?\$filter=company%20eq%20'GDA'&\$select=userId,startDate veya EmpJob/\$count?\$filter=..."],
                    ],
                    'required' => ['path'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'describe_apm',
                'description' => 'APM (uçuş operasyonları: uçuş bacakları/legs, uçaklar, ekip, havalimanları, gecikmeler, uçuş logları, raporlar) REST API için bilgi getirir. path verilmezse taban adres, token alma kalıbı, gereken secretlar ve kullanılabilir işlemlerin listesi; method ve path verilirse o işlemin parametreleri ve yanıt alanları. Uç veya alan adı uydurma.',
                'eagerInputStreaming' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'description' => 'Varsayılan GET.'],
                        'path' => ['type' => 'string', 'description' => 'Spec\'teki yol, ör. /api/legs veya /api/legs/{legIdentifier}.'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'preview_apm',
                'description' => 'Bir APM okuma isteğini (GET veya zamanında kalkış/varış raporu gibi okuma POST\'u, JSON gövdeyle) gerçek sistemde dener ve yanıtın yapısını (listelerin ilk 3 elemanıyla) veya APM\'nin hata mesajını döndürür. propose_flow\'dan önce mutlaka dene: parametre biçimi (tarihler), alan yolları ve jq\'da kullanacağın yol doğru mu gör. Token\'ı araç kendisi alır. Şablon ({{ }}) kullanma, değerleri somut yaz.',
                'eagerInputStreaming' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'Taban adresten sonraki yol ve sorgu, ör. /api/legs?dateFrom=2026-10-04T00:00:00Z&dateTo=2026-10-05T00:00:00Z'],
                        'method' => ['type' => 'string', 'enum' => ['GET', 'POST'], 'description' => 'Varsayılan GET. POST sadece okuma raporları için.'],
                        'body' => ['type' => 'object', 'description' => 'POST için JSON gövde (describe_apm request_body alanlarına göre).'],
                    ],
                    'required' => ['path'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'record_unsupported_request',
                'description' => 'Kullanıcının istediği ama katalogdaki görevlerle yapılamayan bir şeyi istek listesine kaydeder (katalog genişletme kararları için). Bir isteği katalogda olmadığı için reddettiğinde veya sadece kısmen yapabildiğinde bir kez çağır. Kullanıcıya kaydettiğini söyle.',
                'eagerInputStreaming' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'request' => ['type' => 'string', 'description' => 'Kullanıcının ne istediği, tek cümle.'],
                        'missing_capability' => ['type' => 'string', 'description' => 'Eksik yetenek, kısa (ör. "Python script", "Google Sheets okuma", "SFTP").'],
                        'reason' => ['type' => 'string', 'description' => 'Neden yapılamadığı, tek cümle.'],
                    ],
                    'required' => ['request', 'missing_capability'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'list_report_sources',
                'description' => 'Excel, CSV veya PDF rapor üretilebilecek veri kaynaklarını getirir: her kaynağın parametreleri ve rapora eklenebilecek alanları (Türkçe başlık, tip, toplanabilir mi). Veri döndürmez. Kullanıcı bir listeyi dosya olarak isterse (ör. "Excel olarak ver", "PDF hazırla") önce buna bak.',
                'eagerInputStreaming' => true,
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false],
            ],
            [
                'name' => 'create_report',
                'description' => 'Bir rapor dosyası üretir. Veriyi Fortis kendisi çeker ve dosyayı kendisi üretir; sen sadece ayarları seçersin. Dosya kullanıcıya sohbette indirme kartı olarak gösterilir. Sana satırlar gösterilmez: sadece report_id, durum, satır sayısı ve toplanabilir alanların sayımları (aggregates) döner. Kullanıcıya bunlarla kısa bir özet ver (ör. "Raporun hazır: 3 uçuş iptal edilmiş, 2\'si AYT çıkışlı.").',
                'eagerInputStreaming' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'source' => ['type' => 'string', 'description' => 'list_report_sources\'taki kaynak adı.'],
                        'params' => ['type' => 'object', 'description' => 'Kaynağın parametreleri. Tarihler somut YYYY-MM-DD (bugün dahil göreli tarihleri sen hesapla).'],
                        'format' => ['type' => 'string', 'enum' => ['xlsx', 'csv', 'pdf']],
                        'title' => ['type' => 'string', 'description' => 'Kısa Türkçe rapor başlığı.'],
                        'columns' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Sütunlar, sırayla (kaynağın alan anahtarları).'],
                        'sort_by' => ['type' => 'string', 'description' => 'Sıralama alanı (isteğe bağlı).'],
                        'sort_dir' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                        'group_by' => ['type' => 'string', 'description' => 'PDF\'te özet kutusu için gruplama alanı (isteğe bağlı).'],
                    ],
                    'required' => ['source', 'params', 'format', 'title', 'columns'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'create_artifact',
                'description' => 'Sohbetin yanında canlı önizlemesi açılan bir içerik üretir veya günceller: arayüz/sayfa/küçük uygulama (html), görsel (svg), diyagram (mermaid) veya uzun belge (markdown). Kullanıcı önizlemeyi görür, kodu inceler, indirir. Değişiklik isteğinde aynı artifact_id ile içeriğin TAMAMINI yeniden gönder (yeni sürüm olur). Sana sadece artifact_id ve sürüm numarası döner.',
                'eagerInputStreaming' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Kısa Türkçe başlık (ör. "Rötar paneli").'],
                        'kind' => ['type' => 'string', 'enum' => Artifact::KINDS],
                        'content' => ['type' => 'string', 'description' => 'Tam içerik. html: tek dosya, kendi içinde çalışan HTML (CSS/JS satır içi). svg: tek <svg> öğesi. mermaid: mermaid diyagram kodu. markdown: belge metni.'],
                        'artifact_id' => ['type' => 'string', 'description' => 'Var olan bir artifact\'ı güncellerken onun id\'si. Yeni içerik için verme.'],
                    ],
                    'required' => ['title', 'kind', 'content'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'get_flow',
                'description' => 'Mevcut bir akışın YAML\'ını getirir. Var olan bir akışı değiştirmeden önce kullan.',
                'eagerInputStreaming' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['flow_id' => ['type' => 'string', 'description' => 'Akışın id\'si.']],
                    'required' => ['flow_id'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(string $name, array $input): ToolResult
    {
        $result = $this->run($name, $input);

        return in_array($name, self::EXTERNAL_DATA_TOOLS, true) ? $this->gated($name, $result) : $result;
    }

    /** Dış sistem verisi içeren sonuç Claude'a gitmeden önce maskelenir ve sınırlanır. */
    private function gated(string $name, ToolResult $result): ToolResult
    {
        $data = json_decode($result->content, true);
        $source = match ($name) {
            'preview_apm', 'query_apm' => 'APM',
            'preview_successfactors', 'query_successfactors' => AiDataGate::SOURCE_SUCCESSFACTORS,
            default => 'IBS',
        };

        return $result->withData($this->gate->structure($source, is_array($data) ? $data : ['result' => $result->content])['data']);
    }

    /** @param  array<string, mixed>  $input */
    private function run(string $name, array $input): ToolResult
    {
        // Şirket genelinde kapatılan bağlantının aracı: agent'ta da sohbette de reddedilir.
        if (($source = app(ConnectorRegistry::class)->disabledSourceOf($name)) !== null) {
            $label = config('connectors.sources')[$source]['label'] ?? $source;
            Audit::record('connector.tool_blocked', $this->agent?->run, AuditLog::RESULT_BLOCKED, ['tool' => $name, 'connector' => $source],
                actorType: $this->agent !== null ? AuditLog::ACTOR_AGENT : null, actorLabel: $this->agent?->agent->name, user: $this->agent?->run->ownerUser());

            return ToolResult::error("{$label} bağlantısı yönetici tarafından şirket genelinde kapatıldı; bu araç kullanılamaz. Kullanıcıya {$label} verisinin şu an kullanılamadığını söyle.");
        }

        if ($this->agent !== null) {
            if (! $this->agent->allows($name)) {
                Audit::record('agent.tool_blocked', $this->agent->run, AuditLog::RESULT_BLOCKED, ['tool' => $name], actorType: AuditLog::ACTOR_AGENT, actorLabel: $this->agent->agent->name, user: $this->agent->run->ownerUser());

                return ToolResult::error("'{$name}' bu agent için izinli bir araç değil. Sadece sana verilen araçları kullan.");
            }

            if (in_array($name, AgentActions::TOOLS, true)) {
                return app(AgentActions::class)->request($this->agent, $name, $input);
            }
        }

        if ($this->architect !== null) {
            if (! in_array($name, Architect::TOOLS, true)) {
                return ToolResult::error("'{$name}' Architect'te kullanılamaz. Agent'ı propose_agent ile kaydet.");
            }
            if ($name === 'propose_agent') {
                $outcome = app(Architect::class)->propose($this->architect, $input, $this->conversation?->user ?? $this->architect->owner);
                $this->architect->refresh();

                return ToolResult::json($outcome['result'], isError: ! $outcome['ok']);
            }
        }

        if (in_array($name, $this->disabledTools(), true)) {
            return ToolResult::error('Bu kaynak kullanıcı tarafından bu sohbette kapatıldı. Başka kaynakla devam et veya kullanıcıya kaynağı açmasını söyle.');
        }

        if (in_array($name, DataQueryTools::TOOLS, true)) {
            return $this->dataQueries->execute($name, $input);
        }

        return match ($name) {
            'get_catalog' => $this->getCatalog($input),
            'validate_flow' => $this->validateFlow($input),
            'propose_flow' => $this->proposeFlow($input),
            'list_flows' => $this->listFlows(),
            'get_flow' => $this->getFlow($input),
            'list_report_sources' => ToolResult::json(['sources' => $this->reportSources->describe(), 'note' => 'Rapora sadece bu alanlar eklenebilir. TC kimlik, telefon ve doğum tarihi gibi alanlar kasıtlı olarak yok.']),
            'create_report' => $this->createReport($input),
            'create_artifact' => $this->createArtifact($input),
            'get_execution' => $this->getExecution($input),
            'record_unsupported_request' => $this->recordUnsupportedRequest($input),
            'get_queries' => $this->getQueries(),
            'describe_warehouse' => $this->describeWarehouse($input),
            'describe_successfactors' => $this->describeSuccessFactors($input),
            'describe_apm' => $this->describeApm($input),
            'preview_apm' => $this->previewApm($input),
            'preview_successfactors' => $this->previewSuccessFactors($input),
            default => ToolResult::error("Bilinmeyen araç: {$name}"),
        };
    }

    /** @param  array<string, mixed>  $input */
    private function createArtifact(array $input): ToolResult
    {
        if ($this->conversation === null) {
            return ToolResult::error('Sohbet bağlamı yok.');
        }

        try {
            ['artifact' => $artifact, 'version' => $version] = $this->artifacts->save($this->conversation, $input);
        } catch (ArtifactException $e) {
            return ToolResult::invalidInput(array_diff_key($input, ['content' => true]), $e->getMessage());
        }

        return ToolResult::json(
            ['artifact_id' => $artifact->id, 'version' => $version, 'note' => 'Önizleme kullanıcıya sohbetin yanında gösteriliyor. İçeriği mesajda tekrar yazma; ne yaptığını kısaca anlat.'],
            card: ['type' => 'artifact', 'artifact_id' => $artifact->id, 'version' => $version, 'title' => $artifact->title, 'kind' => $artifact->kind],
        );
    }

    /** @param  array<string, mixed>  $input */
    private function getCatalog(array $input): ToolResult
    {
        $types = $input['types'] ?? null;

        if ($types !== null && (! is_array($types) || array_filter($types, fn ($t) => ! is_string($t)) !== [])) {
            return ToolResult::invalidInput($input, 'types bir metin listesi olmalı.');
        }

        return ToolResult::json($this->catalog->forModel($types));
    }

    /** @param  array<string, mixed>  $input */
    private function validateFlow(array $input): ToolResult
    {
        if (! is_string($input['yaml'] ?? null) || trim($input['yaml']) === '') {
            return ToolResult::invalidInput($input, 'yaml zorunlu bir metin.');
        }

        if ($limit = $this->attemptLimitReached()) {
            return $limit;
        }

        $check = $this->checkCounted($input['yaml']);

        return ToolResult::json(array_intersect_key($check, array_flip(['valid', 'errors', 'warnings', 'missing_secrets'])));
    }

    /** @param  array<string, mixed>  $input */
    private function proposeFlow(array $input): ToolResult
    {
        $summary = $input['summary'] ?? null;

        if (! is_string($input['title'] ?? null) || trim($input['title']) === ''
            || ! is_array($summary) || $summary === [] || array_filter($summary, fn ($s) => ! is_string($s)) !== []
            || ! is_string($input['yaml'] ?? null) || trim($input['yaml']) === '') {
            return ToolResult::invalidInput($input, 'title (metin), summary (metin listesi) ve yaml (metin) zorunlu.');
        }

        if ($limit = $this->attemptLimitReached()) {
            return $limit;
        }

        $check = $this->checkCounted($this->withOwnEmail($input['yaml']));

        if (! $check['valid']) {
            return ToolResult::json([
                'shown' => false,
                'reason' => 'Akış doğrulamadan geçmedi; kart gösterilmedi. Hataları düzeltip tekrar dene.',
                'errors' => $check['errors'],
                'remaining_attempts' => self::MAX_FAILED_VALIDATIONS - $this->failedValidations,
            ], isError: true);
        }

        if ($this->conversation?->isEditor()) {
            return $this->proposeChange(trim($input['title']), array_map('trim', $summary), $check);
        }

        $version = $this->flows->recordProposal($this->conversation, trim($input['title']), array_map('trim', $summary), $check);

        return ToolResult::json([
            'shown' => true,
            'note' => 'Kart kullanıcıya gösterildi. Kullanıcı Yükle butonuna basmadan hiçbir şey Kestra\'ya gönderilmez. Kartta gösterilen YAML doğrulayıcının yeniden yazdığı haldir.',
            'flow_version' => $version->number,
            'missing_secrets' => $check['missing_secrets'],
            'warnings' => $check['warnings'],
        ], card: $version->card);
    }

    /**
     * Akış editöründeki sohbet: öneri sürüm olarak kaydedilmez. Kullanıcı tuvalde farkı görür; Uygula derse
     * yeni taslak sürüm olur (yine doğrulayıcıdan geçerek), Geri al derse reddedilir.
     *
     * @param  list<string>  $summary
     * @param  array<string, mixed>  $check
     */
    private function proposeChange(string $title, array $summary, array $check): ToolResult
    {
        $flow = $this->conversation->contextFlow;

        if ($flow === null || $flow->trashed() || $check['flow']['id'] !== $flow->flow_id) {
            return ToolResult::error("Bu sohbette sadece '".($flow->flow_id ?? '?')."' akışı değiştirilebilir. id'yi değiştirmeden tekrar öner.");
        }

        $base = $flow->latestVersion();

        if ($base !== null && $base->yaml_hash === hash('sha256', (string) $check['canonical_yaml'])) {
            return ToolResult::error('Önerdiğin YAML akışın güncel sürümüyle aynı; değişiklik yok.');
        }

        // Aynı sohbetteki eski, uygulanmamış öneriler geçersiz olur.
        $flow->proposals()->where('conversation_id', $this->conversation->id)->where('status', FlowProposal::STATUS_PENDING)
            ->update(['status' => FlowProposal::STATUS_REJECTED]);

        $proposal = $flow->proposals()->create([
            'conversation_id' => $this->conversation->id,
            'base_version' => $base->number ?? 0,
            'title' => $title,
            'summary' => $summary,
            'yaml' => $check['canonical_yaml'],
            'validation' => ['warnings' => $check['warnings'], 'missing_secrets' => $check['missing_secrets']],
            'status' => FlowProposal::STATUS_PENDING,
        ]);

        return ToolResult::json([
            'shown' => true,
            'note' => 'Öneri editörde tuvalde fark olarak gösterildi (eklenen yeşil, silinen kırmızı, değişen sarı). Kullanıcı Uygula derse yeni taslak sürüm olur; Kestra\'ya gitmesi için ayrıca Yayınla gerekir.',
            'base_version' => $proposal->base_version,
            'missing_secrets' => $check['missing_secrets'],
            'warnings' => $check['warnings'],
        ], card: [
            'type' => 'proposal',
            'proposal_id' => $proposal->id,
            'flow_record_id' => $flow->id,
            'base_version' => $proposal->base_version,
            'title' => $title,
            'summary' => $summary,
        ]);
    }

    /**
     * @return array{valid: bool, errors: list<string>, warnings: list<string>, missing_secrets: list<string>, secrets: list<string>, canonical_yaml: string|null, flow: array<string, mixed>|null, plugins: list<array<string, mixed>>}
     */
    private function checkCounted(string $yaml): array
    {
        $check = $this->validator->validate($yaml, FlowValidator::MODE_PROPOSAL);

        if (! $check['valid']) {
            $this->failedValidations++;
        }

        return $check;
    }

    private function attemptLimitReached(): ?ToolResult
    {
        if ($this->failedValidations < self::MAX_FAILED_VALIDATIONS) {
            return null;
        }

        return ToolResult::json([
            'error' => 'Doğrulama deneme hakkı doldu ('.self::MAX_FAILED_VALIDATIONS.'). Tekrar deneme; kullanıcıya akışın neden hazırlanamadığını sade dille anlat.',
        ], isError: true);
    }

    private function listFlows(): ToolResult
    {
        try {
            $result = $this->kestra->searchFlows($this->catalog->namespace(), size: 100);
        } catch (KestraException $e) {
            return ToolResult::error($e->getMessage());
        }

        return ToolResult::json([
            'total' => $result['total'] ?? 0,
            'flows' => array_map(fn (array $flow) => [
                'id' => $flow['id'],
                'namespace' => $flow['namespace'],
                'description' => $flow['description'] ?? null,
                'revision' => $flow['revision'] ?? null,
                'disabled' => $flow['disabled'] ?? false,
            ], array_values(array_filter(
                $result['results'] ?? [],
                fn (array $flow) => ($flow['namespace'] ?? null) === $this->catalog->namespace(),
            ))),
        ]);
    }

    /** @param  array<string, mixed>  $input */
    private function getExecution(array $input): ToolResult
    {
        $executionId = is_string($input['execution_id'] ?? null) ? trim($input['execution_id']) : '';
        $flowId = is_string($input['flow_id'] ?? null) ? trim($input['flow_id']) : '';

        if ($executionId === '' && $flowId === '') {
            return ToolResult::invalidInput($input, 'execution_id veya flow_id verilmeli.');
        }

        try {
            if ($executionId === '') {
                $latest = $this->kestra->searchExecutions($this->catalog->namespace(), $flowId, size: 1)['results'][0] ?? null;

                if ($latest === null) {
                    return ToolResult::error("'{$flowId}' akışının hiç çalışması yok.");
                }

                $executionId = $latest['id'];
            }

            $execution = $this->kestra->getExecution($executionId);
        } catch (KestraException $e) {
            return ToolResult::error($e->status === 404 ? 'Çalışma bulunamadı.' : $e->getMessage());
        }

        // Claude sadece chat namespace'indeki çalışmaları okuyabilir.
        if (($execution['namespace'] ?? null) !== $this->catalog->namespace()) {
            return ToolResult::error('Çalışma bulunamadı.');
        }

        try {
            $logs = $this->kestra->getExecutionLogs($executionId);
        } catch (KestraException) {
            $logs = [];
        }

        $local = Execution::query()->where('kestra_execution_id', $executionId)->first();
        if ($local !== null) {
            try {
                $this->executions->refresh($local);
            } catch (ExecutionException) {
                // Kayıt güncellenemese de Kestra'dan okunan sonuç döner.
            }
        }

        // Satır verisi (çıktılar, loglar, girdiler, döngü değerleri) Claude'a gitmez; sadece özet.
        return ToolResult::json(ExecutionSummaryForAi::fromKestra($execution, $logs) + [
            'note' => 'Satır verisi ve loglar sana gösterilmez. Kullanıcı ayrıntıyı Çalışmalar ekranında görebilir.',
        ]);
    }

    private function getQueries(): ToolResult
    {
        return ToolResult::json([
            'required_secrets' => $this->warehouse->requiredSecrets(),
            'available_secrets' => $this->catalog->secrets(),
            'usage' => 'Görev tipi io.kestra.plugin.jdbc.mysql.Query. sql alanına hazır sorgunun SQL\'ini birebir yaz, her parametreyi parameters altında ver (tarihler YYYY-MM-DD; ör. dün: "{{ now() | dateAdd(-1, \'DAYS\') | date(\'yyyy-MM-dd\') }}"). fetchType: FETCH. Sonuç: outputs.<id>.rows.',
            'queries' => array_map(fn (array $query) => array_intersect_key($query, array_flip(['name', 'title', 'description', 'parameters', 'columns', 'sql'])), $this->warehouse->queries()),
        ]);
    }

    /** @param  array<string, mixed>  $input */
    private function describeWarehouse(array $input): ToolResult
    {
        $tables = $input['tables'] ?? [];

        if (! is_array($tables) || array_filter($tables, fn ($t) => ! is_string($t)) !== []) {
            return ToolResult::invalidInput($input, 'tables bir metin listesi olmalı.');
        }

        if (count($tables) > 8) {
            return ToolResult::error('Bir seferde en fazla 8 tablo sorulabilir.');
        }

        if ($tables === []) {
            $list = $this->warehouse->tables();

            return $list === null
                ? ToolResult::error('Veri ambarına şu an ulaşılamıyor.')
                : ToolResult::json(['notes' => $this->warehouse->generalNotes(), 'tables' => $list]);
        }

        $details = $this->warehouse->describe(array_values($tables));

        return $details === null ? ToolResult::error('Veri ambarına şu an ulaşılamıyor.') : ToolResult::json(['tables' => $details]);
    }

    /** @param  array<string, mixed>  $input */
    private function describeSuccessFactors(array $input): ToolResult
    {
        if (! $this->successFactors->enabled()) {
            return ToolResult::error('SuccessFactors bu ortamda tanımlı değil.');
        }

        $entity = $input['entity'] ?? null;

        if ($entity !== null && (! is_string($entity) || trim($entity) === '')) {
            return ToolResult::invalidInput($input, 'entity bir metin olmalı.');
        }

        if ($entity === null) {
            return ToolResult::json([
                'base_url' => $this->successFactors->baseUrl(),
                'required_secrets' => $this->successFactors->requiredSecrets(),
                'available_secrets' => $this->catalog->secrets(),
                'writes_allowed' => $this->successFactors->allowWrites(),
                'notes' => $this->successFactors->notes(),
                'requests' => array_map(fn (array $r) => array_intersect_key($r, array_flip(['name', 'title', 'description', 'entity', 'personal_data', 'parameters', 'url'])), $this->successFactors->requests()),
            ]);
        }

        $described = $this->successFactors->describe(trim($entity));

        return match (true) {
            $described === null => ToolResult::error('SuccessFactors şemasına şu an ulaşılamıyor.'),
            isset($described['error']) => ToolResult::error($described['error']),
            default => ToolResult::json($described),
        };
    }

    /** @param  array<string, mixed>  $input */
    private function previewSuccessFactors(array $input): ToolResult
    {
        if (! $this->successFactors->enabled()) {
            return ToolResult::error('SuccessFactors bu ortamda tanımlı değil.');
        }

        $path = $input['path'] ?? null;
        if (! is_string($path) || trim($path) === '') {
            return ToolResult::invalidInput($input, 'path zorunlu bir metin.');
        }

        $preview = $this->successFactors->preview($path);

        return $preview['ok'] ? ToolResult::json($preview) : ToolResult::error('SuccessFactors hatası: '.$preview['error']);
    }

    /** @param  array<string, mixed>  $input */
    private function previewApm(array $input): ToolResult
    {
        $path = $input['path'] ?? null;
        if (! is_string($path) || trim($path) === '') {
            return ToolResult::invalidInput($input, 'path zorunlu bir metin.');
        }

        $method = is_string($input['method'] ?? null) ? $input['method'] : 'GET';
        $body = is_array($input['body'] ?? null) ? $input['body'] : null;

        $preview = $this->apm->preview($path, $method, $body);

        return $preview['ok'] ? ToolResult::json($preview) : ToolResult::error('APM hatası: '.$preview['error']);
    }

    /** @param  array<string, mixed>  $input */
    private function describeApm(array $input): ToolResult
    {
        if (! $this->apm->enabled()) {
            return ToolResult::error('APM bu ortamda tanımlı değil.');
        }

        $path = $input['path'] ?? null;
        $method = $input['method'] ?? 'GET';

        if ($path !== null && (! is_string($path) || ! str_starts_with(trim($path), '/'))) {
            return ToolResult::invalidInput($input, "path '/' ile başlayan bir metin olmalı, ör. /api/legs.");
        }

        if (! is_string($method)) {
            return ToolResult::invalidInput($input, 'method bir metin olmalı.');
        }

        if ($path === null) {
            $secrets = $this->apm->requiredSecrets();
            $tokenFields = $this->apm->grantType() === 'password'
                ? ['username' => "{{ secret('{$secrets[0]}') }}", 'password' => "{{ secret('{$secrets[1]}') }}"]
                : ['client_id' => "{{ secret('{$secrets[0]}') }}", 'client_secret' => "{{ secret('{$secrets[1]}') }}"];

            return ToolResult::json([
                'base_url' => $this->apm->baseUrl(),
                'required_secrets' => $secrets,
                'available_secrets' => $this->catalog->secrets(),
                'writes_allowed' => $this->apm->allowWrites(),
                'auth' => [
                    'token_task' => [
                        'type' => 'io.kestra.plugin.core.http.Request',
                        'uri' => $this->apm->baseUrl().'/oauth/token',
                        'method' => 'POST',
                        'contentType' => 'application/x-www-form-urlencoded',
                        'formData' => ['grant_type' => $this->apm->grantType()] + $tokenFields,
                    ],
                    'other_requests' => [
                        'options' => ['auth' => ['type' => 'BEARER', 'token' => "{{ outputs.<token_görevi_id>.body | jq('.access_token') | first }}"]],
                    ],
                    'rule' => 'Token görevinin çıktısı (outputs.<token_görevi_id>) başka hiçbir yerde (log, bildirim, başka istek) kullanılamaz.',
                ],
                'notes' => [
                    'Yanıt gövdesi metindir; alanlara jq ile eriş: {{ outputs.x.body | jq(\'.alan\') | first }}.',
                    'Sayfalı yanıtlarda (page parametresi) listenin ve toplamın yeri işleme göre değişir (ör. /api/legs: .legs.content[] ve .totalNumberOfItems); describe_apm ile işlemin yanıt alanlarına bak.',
                    'Parametrelerdeki * zorunlu demektir. Sorgu parametrelerini uri\'ye ?a=..&b=.. olarak ekle.',
                ],
                'operations' => $this->apm->operations(),
            ]);
        }

        $described = $this->apm->describe(strtoupper($method), trim($path));

        return $described === null
            ? ToolResult::error("APM'de {$method} {$path} işlemi yok. İşlem listesi için describe_apm'i path vermeden çağır.")
            : ToolResult::json($described);
    }

    /** @param  array<string, mixed>  $input */
    private function recordUnsupportedRequest(array $input): ToolResult
    {
        $request = is_string($input['request'] ?? null) ? trim($input['request']) : '';
        $capability = is_string($input['missing_capability'] ?? null) ? trim($input['missing_capability']) : '';

        if ($request === '' || $capability === '') {
            return ToolResult::invalidInput($input, 'request ve missing_capability zorunlu metinler.');
        }

        $saved = FeatureRequest::create([
            'conversation_id' => $this->conversation?->id,
            'request' => mb_substr($request, 0, 1000),
            'missing_capability' => mb_substr($capability, 0, 255),
            'reason' => is_string($input['reason'] ?? null) ? mb_substr(trim($input['reason']), 0, 1000) : null,
        ]);

        return ToolResult::json(['recorded' => true, 'id' => $saved->id]);
    }

    /**
     * Rapor üretir. Dosyayı ve veriyi Laravel üretir; Claude'a sadece özet döner (ExecutionSummaryForAi).
     *
     * @param  array<string, mixed>  $input
     */
    private function createReport(array $input): ToolResult
    {
        if (! is_string($input['source'] ?? null) || ! is_array($input['columns'] ?? null) || ! is_string($input['format'] ?? null)) {
            return ToolResult::invalidInput($input, 'source, params, format, title ve columns zorunlu.');
        }

        try {
            $report = $this->reports->create($input, $this->conversation);
        } catch (ReportException $e) {
            return ToolResult::error($e->getMessage());
        }

        $summary = ExecutionSummaryForAi::forReport($report);

        return ToolResult::json($summary + [
            'note' => match ($report->status) {
                Report::STATUS_READY => 'Dosya hazır; kullanıcı sohbetteki karttan indirebilir.',
                Report::STATUS_QUEUED => 'Çok satırlı rapor arka planda hazırlanıyor; kart hazır olunca İndir butonunu gösterir.',
                default => 'Rapor üretilemedi; hatayı kullanıcıya sade dille açıkla ve gerekiyorsa filtreyi daraltmayı öner.',
            },
        ], isError: $report->status === Report::STATUS_FAILED, card: ['type' => 'report', 'report_id' => $report->id]);
    }

    /** @param  array<string, mixed>  $input */
    /**
     * "Bana gönder": Claude e-posta görevinin alıcı alanlarına (to/cc/bcc) KULLANICI_EPOSTASI yazar; kart oluşurken
     * sohbet sahibinin adresi konur. Konu, gövde ve diğer alanlara dokunulmaz. Böylece adres Claude'a gönderilmez.
     */
    private function withOwnEmail(string $yaml): string
    {
        $email = $this->conversation?->user?->email;

        return $email ? $this->swapRecipients($yaml, self::OWN_EMAIL_TOKEN, $email) : $yaml;
    }

    /** Claude'a geri giden YAML'da sohbet sahibinin adresi alıcı alanlarında yine yer tutucuya döner. */
    private function withoutOwnEmail(string $yaml): string
    {
        $email = $this->conversation?->user?->email;

        return $email ? $this->swapRecipients($yaml, $email, self::OWN_EMAIL_TOKEN) : $yaml;
    }

    /** Sadece MailSend görevlerinin to/cc/bcc alanlarında değiştirir; YAML okunamazsa olduğu gibi bırakır. */
    private function swapRecipients(string $yaml, string $from, string $to): string
    {
        try {
            $flow = Yaml::parse($yaml);
        } catch (ParseException) {
            return $yaml;
        }
        if (! is_array($flow)) {
            return $yaml;
        }

        $changed = false;
        $walk = function (array $node) use (&$walk, $from, $to, &$changed): array {
            if (($node['type'] ?? null) === 'io.kestra.plugin.email.MailSend') {
                foreach (['to', 'cc', 'bcc'] as $field) {
                    if (is_string($node[$field] ?? null) && str_contains($node[$field], $from)) {
                        $node[$field] = implode(',', array_map(fn (string $part) => trim($part) === $from ? $to : $part, explode(',', $node[$field])));
                        $changed = true;
                    } elseif (is_array($node[$field] ?? null)) {
                        $node[$field] = array_map(function ($v) use ($from, $to, &$changed) {
                            if (is_string($v) && trim($v) === $from) {
                                $changed = true;

                                return $to;
                            }

                            return $v;
                        }, $node[$field]);
                    }
                }
            }
            foreach ($node as $key => $child) {
                if (is_array($child)) {
                    $node[$key] = $walk($child);
                }
            }

            return $node;
        };
        $result = $walk($flow);

        return $changed ? Yaml::dump($result, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK) : $yaml;
    }

    private function getFlow(array $input): ToolResult
    {
        if (! is_string($input['flow_id'] ?? null) || trim($input['flow_id']) === '') {
            return ToolResult::invalidInput($input, 'flow_id zorunlu bir metin.');
        }

        // Fortis'te kaydı olan akışın güncel hali son sürümüdür (henüz yayınlanmamış taslak olabilir).
        $record = Flow::query()->where('namespace', $this->catalog->namespace())->where('flow_id', trim($input['flow_id']))->first();
        $latest = $record?->latestVersion();

        if ($latest !== null) {
            return ToolResult::json([
                'id' => $record->flow_id,
                'namespace' => $record->namespace,
                'version' => $latest->number,
                'published_version' => $record->currentVersion?->isUploaded() ? $record->currentVersion->number : null,
                'note' => 'Bu YAML akışın Fortis\'teki son sürümü; yayındaki sürümden yeni olabilir. Değişikliği bunun üzerine yap.',
                'yaml' => $this->withoutOwnEmail($latest->yaml),
            ]);
        }

        try {
            $flow = $this->kestra->getFlow($this->catalog->namespace(), trim($input['flow_id']));
        } catch (KestraException $e) {
            return ToolResult::error($e->getMessage());
        }

        if ($flow === null) {
            return ToolResult::error("'{$input['flow_id']}' adında bir akış bulunamadı.");
        }

        return ToolResult::json([
            'id' => $flow['id'],
            'namespace' => $flow['namespace'],
            'revision' => $flow['revision'] ?? null,
            'yaml' => $flow['source'] ?? null,
        ]);
    }
}
