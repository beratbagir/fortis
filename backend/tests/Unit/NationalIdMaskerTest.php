<?php

namespace Tests\Unit;

use App\Services\Privacy\NationalIdMasker;
use PHPUnit\Framework\TestCase;

class NationalIdMaskerTest extends TestCase
{
    public function test_valid_national_ids_are_masked(): void
    {
        $this->assertSame('Ali Veli / ***********', NationalIdMasker::mask('Ali Veli / 10000000146'));
        $this->assertSame('{"nationalId":"***********"}', NationalIdMasker::mask('{"nationalId":"10000000146"}'));
    }

    public function test_other_numbers_are_kept(): void
    {
        foreach (['05321234567', '5321234567', '10000000147', '123456789012', 'Sicil 3882', '+905321234567'] as $text) {
            $this->assertSame($text, NationalIdMasker::mask($text), $text);
        }
    }

    public function test_arrays_are_masked_recursively_including_integers(): void
    {
        $masked = NationalIdMasker::maskArray(['inputs' => ['tc' => 10000000146, 'name' => 'Ayşe'], 'logs' => [['message' => 'TC 10000000146']]]);

        $this->assertSame(['inputs' => ['tc' => NationalIdMasker::MASK, 'name' => 'Ayşe'], 'logs' => [['message' => 'TC ***********']]], $masked);
    }
}
