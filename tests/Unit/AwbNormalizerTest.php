<?php

namespace Tests\Unit;

use App\Domain\Attention\AwbNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AwbNormalizerTest extends TestCase
{
    public function test_preserves_leading_zero_and_normalizes_ascii_case_and_edge_spaces(): void
    {
        $normalizer = new AwbNormalizer;

        $this->assertSame('0201234567890', $normalizer->normalize(' 0201234567890 '));
        $this->assertSame('ABC12345', $normalizer->normalize(' abc12345 '));
    }

    public function test_rejects_controls_internal_spaces_unicode_and_invalid_length(): void
    {
        $normalizer = new AwbNormalizer;

        foreach (["02012345\n", '020 12345', 'é2012345', '123', str_repeat('1', 31)] as $value) {
            try {
                $normalizer->normalize($value);
                $this->fail('Expected invalid AWB to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
