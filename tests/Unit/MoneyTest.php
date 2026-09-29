<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_normalises_and_rounds_half_away_from_zero()
    {
        $this->assertSame('10.00', Money::of('10'));
        $this->assertSame('0.00', Money::of(null));
        $this->assertSame('1.01', Money::of('1.005'));
        $this->assertSame('-1.01', Money::of('-1.005'));
        $this->assertSame('1.00', Money::of('1.004'));
    }

    public function test_arithmetic_is_exact()
    {
        // 0.1 + 0.2 is 0.30000000000000004 in floating point.
        $this->assertSame('0.30', Money::add('0.10', '0.20'));
        $this->assertSame('-0.10', Money::sub('0.10', '0.20'));
        $this->assertSame('33.33', Money::mul('3.333', 10));
        $this->assertSame('3333.33', Money::proportion('10000.00', '1', '3'));
        $this->assertSame('99999999990.00', Money::mul('99999999.99', 1000));
    }

    public function test_comparisons()
    {
        $this->assertTrue(Money::isZero('0.00'));
        $this->assertTrue(Money::isPositive('0.01'));
        $this->assertTrue(Money::isNegative('-0.01'));
        $this->assertSame('5.00', Money::min('5.00', '7.00'));
        $this->assertSame('7.00', Money::max('5.00', '7.00'));
        $this->assertSame('0.00', Money::proportion('10.00', '1', '0'));
    }

    public function test_allocate_splits_exactly_by_largest_remainder()
    {
        $this->assertSame(['33.34', '33.33', '33.33'], Money::allocate('100.00', ['1.00', '1.00', '1.00']));
        $this->assertSame(['0.01', '0.01', '0.00', '0.00'], Money::allocate('0.02', ['1.00', '1.00', '1.00', '1.00']));
        $this->assertSame(['10.00', '0.00'], Money::allocate('10.00', ['10.00', '0.00']));
        $this->assertSame(['7.50', '2.50'], Money::allocate('10.00', ['300.00', '100.00']));
        $this->assertSame(['0.00', '0.00'], Money::allocate('0.00', ['0.00', '0.00']));

        // Never negative and never above its weight, for many random splits.
        mt_srand(42);
        for ($i = 0; $i < 200; $i++) {
            $weights = array_map(fn () => number_format(mt_rand(0, 100000) / 100, 2, '.', ''), range(1, mt_rand(1, 6)));
            $total = array_reduce($weights, fn ($c, $w) => bcadd($c, $w, 2), '0.00');
            if (bccomp($total, '0', 2) === 0) {
                continue;
            }
            $amount = bcdiv(bcmul($total, (string) mt_rand(0, 100), 2), '100', 2);
            $shares = Money::allocate($amount, $weights);

            $this->assertSame($amount, array_reduce($shares, fn ($c, $s) => bcadd($c, $s, 2), '0.00'));
            foreach ($shares as $index => $share) {
                $this->assertGreaterThanOrEqual(0, bccomp($share, '0', 2));
                $this->assertLessThanOrEqual(0, bccomp($share, $weights[$index], 2));
            }
        }
    }
}
