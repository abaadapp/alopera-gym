<?php

namespace Tests\Unit;

use App\Core\Support\Money;
use App\Core\Support\Phone;
use PHPUnit\Framework\TestCase;

class MoneyAndPhoneTest extends TestCase
{
    public function test_money_uses_three_decimals_without_float_drift(): void
    {
        $this->assertSame('0.300', Money::add('0.1', '0.2'));
        $this->assertSame('24.999', Money::sub('25', '0.001'));
        $this->assertSame('2.500', Money::percent('25', 10));
        $this->assertSame(0, Money::cmp('10.0001', '10.000'));
        $this->assertTrue(Money::isZero('0.0004'));
        $this->assertSame(25000, Money::toMinor('25'));
    }

    public function test_omani_numbers_are_normalized_to_international_digits(): void
    {
        $this->assertSame('96891234567', Phone::normalize('9123 4567'));
        $this->assertSame('96891234567', Phone::normalize('+968 9123-4567'));
        $this->assertSame('96891234567', Phone::normalize('0096891234567'));
        $this->assertSame('96891234567', Phone::normalize('٩١٢٣٤٥٦٧'));
        $this->assertSame('966501234567', Phone::normalize('+966 50 123 4567'));
        $this->assertSame('+968 9123 4567', Phone::display('96891234567'));
    }
}
