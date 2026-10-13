<?php

namespace Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Testlerde varsayılan Anthropic taşıyıcısı: ağa hiç çıkmaz. Bir test gerçek API'ye istek atmaya kalkarsa hata alır
 * (.env'deki gerçek anahtarla ücretli çağrı yapılmasın). Claude'u kullanan testler FakeAnthropic bağlar.
 */
class OfflineAnthropic implements ClientInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new RuntimeException('Testlerde gerçek Anthropic API çağrısı yapılamaz.');
    }
}
