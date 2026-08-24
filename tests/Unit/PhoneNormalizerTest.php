<?php

namespace Tests\Unit;

use App\Services\PhoneNormalizer;
use Tests\TestCase;

class PhoneNormalizerTest extends TestCase
{
    public function test_it_normalizes_common_indonesian_numbers(): void
    {
        $this->assertSame('+6281234567890', PhoneNormalizer::normalize('0812-3456-7890'));
        $this->assertSame('+6281234567890', PhoneNormalizer::normalize('81234567890'));
    }
}
