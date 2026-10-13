<?php

namespace Tests;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Services\Apm\ApmApi;
use App\Services\Flows\Catalog;
use App\Services\Flows\FlowValidator;
use App\Services\Kestra\KestraClient;
use App\Services\SuccessFactors\SuccessFactors;
use App\Services\Warehouse\Warehouse;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Tests\Support\OfflineAnthropic;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Hiçbir test gerçek Claude API'sine istek atmaz.
        $this->app->instance(Client::class, new Client(apiKey: 'test-key', requestOptions: RequestOptions::with(transporter: new OfflineAnthropic, maxRetries: 0)));
    }

    /**
     * Akış testleri için ortam: sahte Kestra adresi, verilen secret listesi ve gerçek ağa gitmeyen DNS.
     *
     * @param  list<string>  $secrets
     * @param  array<string, list<string>>  $dns  Sunucu adı => IP'ler. Listede olmayanlar genel bir IP'ye çözülür.
     */
    protected function useFlowEnvironment(array $secrets = [], array $dns = []): void
    {
        config(['kestra.url' => 'http://kestra.test', 'kestra.secrets' => $secrets, 'kestra.namespace' => 'fortis.chat']);

        $this->app->forgetInstance(Catalog::class);
        $this->app->forgetInstance(KestraClient::class);

        $this->app->bind(FlowValidator::class, fn ($app) => new FlowValidator(
            catalog: $app->make(Catalog::class),
            kestra: $app->make(KestraClient::class),
            blockedHosts: ['kestra.test', 'fortis.test'],
            resolveHost: fn (string $host): array => $dns[$host] ?? ['93.184.215.14'],
            warehouse: $app->make(Warehouse::class),
            successFactors: $app->make(SuccessFactors::class),
            apm: $app->make(ApmApi::class),
        ));
    }

    /**
     * Gerçek IBS veri ambarına bağlanan testler için. Ambara ulaşılamıyorsa (VPN kapalı) test atlanır.
     */
    protected function requireWarehouse(): Warehouse
    {
        try {
            DB::connection(config('warehouse.connection'))->select('SELECT 1');
        } catch (Throwable $e) {
            $this->markTestSkipped('IBS veri ambarına ulaşılamıyor (VPN kapalı olabilir): '.mb_substr($e->getMessage(), 0, 120));
        }

        return $this->app->make(Warehouse::class);
    }
}
