<?php

namespace Tests\Feature\Foundation;

use App\Services\DocumentNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class DocumentNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_numbers_are_sequential_per_prefix_and_month(): void
    {
        $numbers = app(DocumentNumberGenerator::class);

        DB::transaction(function () use ($numbers) {
            $this->assertSame('CUS-202610-000001', $numbers->next('CUS', Carbon::parse('2026-10-05')));
            $this->assertSame('CUS-202610-000002', $numbers->next('CUS', Carbon::parse('2026-10-20')));
            $this->assertSame('LN-202610-000001', $numbers->next('LN', Carbon::parse('2026-10-20')));
            $this->assertSame('CUS-202611-000001', $numbers->next('CUS', Carbon::parse('2026-11-01')));
        });
    }

    public function test_a_rolled_back_document_releases_its_number(): void
    {
        $numbers = app(DocumentNumberGenerator::class);
        $date = Carbon::parse('2026-10-05');

        try {
            DB::transaction(function () use ($numbers, $date) {
                $numbers->next('CUS', $date);
                throw new \RuntimeException('Save failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame('CUS-202610-000001', DB::transaction(fn () => $numbers->next('CUS', $date)));
    }

    public function test_numbers_must_be_generated_inside_a_transaction(): void
    {
        $this->expectException(LogicException::class);

        // RefreshDatabase wraps each test in a transaction; leave it to reach level 0.
        DB::rollBack();
        app(DocumentNumberGenerator::class)->next('CUS');
    }
}
