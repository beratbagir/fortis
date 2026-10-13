<?php

namespace App\Providers;

use Anthropic\Client;
use App\Ai\ModelRouter;
use App\Models\User;
use App\Services\Apm\ApmApi;
use App\Services\Claude\ClaudeChat;
use App\Services\Claude\ConversationHistory;
use App\Services\Claude\CostCalculator;
use App\Services\Claude\FlowTools;
use App\Services\Claude\SystemPrompt;
use App\Services\Flows\Blocks\BlockCatalog;
use App\Services\Flows\Catalog;
use App\Services\Flows\FlowValidator;
use App\Services\Kestra\KestraClient;
use App\Services\Reports\ReportSources;
use App\Services\SuccessFactors\SuccessFactors;
use App\Services\Voice\TranscriptFilter;
use App\Services\Voice\WhisperClient;
use App\Services\Warehouse\SqlGuard;
use App\Services\Warehouse\Warehouse;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\MicrosoftExtendSocialite;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(KestraClient::class, fn () => KestraClient::fromConfig());

        $this->app->singleton(Client::class, fn () => new Client(apiKey: (string) config('services.anthropic.api_key')));

        $this->app->singleton(Catalog::class, fn () => Catalog::fromConfig());

        $this->app->bind(WhisperClient::class, fn () => WhisperClient::fromConfig());
        $this->app->bind(TranscriptFilter::class, fn () => TranscriptFilter::fromConfig());

        $this->app->singleton(ApmApi::class, fn () => new ApmApi(
            spec: is_file(config('apm.spec_path')) ? json_decode((string) file_get_contents(config('apm.spec_path')), true) : [],
            kestraBaseUrl: config('apm.kestra_base_url'),
            grantType: config('apm.grant_type'),
            allowWrites: config('apm.allow_writes'),
            readOnlyPosts: config('apm.read_only_posts'),
            directBaseUrl: config('apm.base_url'),
            credentials: config('apm.grant_type') === 'password'
                ? ['username' => config('apm.username'), 'password' => config('apm.password')]
                : ['client_id' => config('apm.client_id'), 'client_secret' => config('apm.client_secret')],
        ));

        $this->app->singleton(SuccessFactors::class, fn () => new SuccessFactors(
            catalog: json_decode((string) file_get_contents(config('successfactors.requests_path')), true),
            baseUrl: config('successfactors.enabled') ? config('successfactors.base_url') : '',
            username: config('successfactors.username'),
            password: config('successfactors.password'),
            allowWrites: config('successfactors.allow_writes'),
            cacheSeconds: config('successfactors.cache_seconds'),
            timeout: config('successfactors.timeout'),
        ));

        $this->app->singleton(Warehouse::class, function () {
            $dictionary = json_decode((string) file_get_contents(config('warehouse.dictionary_path')), true);

            return new Warehouse(
                connection: config('warehouse.enabled') ? fn () => DB::connection(config('warehouse.connection')) : null,
                queries: json_decode((string) file_get_contents(config('warehouse.queries_path')), true),
                dictionary: $dictionary,
                guard: new SqlGuard($dictionary['hidden_columns'], config('warehouse.max_execution_ms'), config('warehouse.max_limit')),
                cacheSeconds: config('warehouse.cache_seconds'),
            );
        });

        $this->app->bind(FlowValidator::class, fn ($app) => new FlowValidator(
            catalog: $app->make(Catalog::class),
            kestra: $app->make(KestraClient::class),
            blockedHosts: array_values(array_filter([
                parse_url((string) config('kestra.url'), PHP_URL_HOST),
                parse_url((string) config('app.url'), PHP_URL_HOST),
                ...config('kestra.blocked_hosts'),
            ])),
            warehouse: $app->make(Warehouse::class),
            successFactors: $app->make(SuccessFactors::class),
            apm: $app->make(ApmApi::class),
        ));

        $this->app->singleton(CostCalculator::class, fn () => new CostCalculator(array_map(fn (array $price) => [
            'input' => $price['input_per_mtok'],
            'output' => $price['output_per_mtok'],
            'cache_write' => $price['cache_write_per_mtok'],
            'cache_read' => $price['cache_read_per_mtok'],
        ], config('ai.models') + config('ai.pricing_only'))));

        $this->app->bind(ReportSources::class, fn ($app) => new ReportSources(
            sources: json_decode((string) file_get_contents(config('reports.sources_path')), true)['sources'],
            warehouse: $app->make(Warehouse::class),
            successFactors: $app->make(SuccessFactors::class),
            apm: $app->make(ApmApi::class),
        ));

        $this->app->bind(BlockCatalog::class, fn ($app) => BlockCatalog::fromConfig(
            $app->make(Catalog::class),
            $app->make(ApmApi::class),
            $app->make(SuccessFactors::class),
        ));

        $this->app->bind(ClaudeChat::class, fn ($app) => new ClaudeChat(
            client: $app->make(Client::class),
            history: $app->make(ConversationHistory::class),
            costs: $app->make(CostCalculator::class),
            tools: $app->make(FlowTools::class),
            model: config('ai.default_model'),
            effort: config('services.anthropic.effort'),
            maxTokens: config('services.anthropic.max_tokens'),
            fallbacks: config('services.anthropic.fallbacks'),
            systemPrompt: $app->make(SystemPrompt::class)->render(),
            router: $app->make(ModelRouter::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('voice', fn (Request $request) => Limit::perMinute((int) config('voice.per_minute'))->by($request->user()?->id ?: $request->ip()));

        // Yetki: ayarlar, bütçe ve kullanıcı yönetimi admin'de; akış oluşturma ve yayınlama builder ve admin'de.
        Gate::define('admin', fn (User $user) => $user->isAdmin());
        Gate::define('build', fn (User $user) => $user->canBuild());

        // Sunucu mesajlarının çevirileri: kodda Türkçe kaynak metin __() ile, İngilizcesi lang/parts/<alan>/en.json'da.
        foreach (glob(lang_path('parts/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $this->app['translator']->addJsonPath($dir);
        }
        Event::listen(SocialiteWasCalled::class, [MicrosoftExtendSocialite::class, 'handle']);
        // Kullanıcı doğrulanınca dil onun tercihine geçer (giriş, Sanctum, testlerde actingAs).
        Event::listen(Authenticated::class, function (Authenticated $event) {
            if ($event->user instanceof User && in_array($event->user->locale, User::LOCALES, true)) {
                app()->setLocale($event->user->locale);
            }
        });
    }
}
