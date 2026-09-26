<?php

namespace Tests\Feature;

use App\Domain\Attention\AttentionLookup;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CsvReportImportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_operational_csv_selects_only_goods_value_strictly_above_two_million(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        $batch = $this->upload([
            ["'0201234567890", '2000001', 'selected'],
            ["'0201111111111", '2000000', 'equal'],
            ["'0202222222222", '', 'missing'],
            ["'0203333333333", '1999999', 'below'],
        ], "\xEF\xBB\xBFAWB,GOODS_VALUE,SHIPPER_NAME\n");

        $this->assertSame('VALIDATED', $batch->status);
        $this->assertSame(4, $batch->total_rows);
        $this->assertSame(1, $batch->valid_rows);
        $this->assertSame(0, $batch->invalid_rows);
        $this->assertSame(0, $batch->duplicate_rows);
        $this->assertDatabaseHas('import_rows', [
            'import_batch_id' => $batch->id, 'row_number' => 2, 'awb' => '0201234567890',
            'declared_value' => 2000001, 'status' => 'VALID',
        ]);
        $this->assertSame(3, DB::table('import_rows')->where('import_batch_id', $batch->id)
            ->where('status', 'EXCLUDED')->whereNull('awb')->count());
        $this->assertDatabaseCount('attention_items', 0);

        $this->post(route('admin.imports.commit', $batch))->assertRedirect();
        $lookup = app(AttentionLookup::class);
        $this->assertTrue($lookup->check('0201234567890')['matched']);
        $this->assertFalse($lookup->check('0201111111111')['matched']);
        $this->assertFalse($lookup->check('0202222222222')['matched']);
        $this->assertDatabaseCount('attention_items', 1);
    }

    public function test_invalid_and_duplicate_qualifying_rows_block_commit(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        $batch = $this->upload([
            ["'8994016015726", '2500000', 'first'],
            ["'8994016015726", '2600000', 'duplicate'],
            ["'6970493063940", '2.7E+6', 'invalid value'],
            ["'BAD", '3000000', 'invalid AWB'],
        ]);

        $this->assertSame(1, $batch->valid_rows);
        $this->assertSame(1, $batch->duplicate_rows);
        $this->assertSame(2, $batch->invalid_rows);
        $this->post(route('admin.imports.commit', $batch))->assertSessionHasErrors('commit');
        $this->assertDatabaseCount('attention_items', 0);
    }

    public function test_zero_qualifying_rows_cannot_replace_active_snapshot(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        $first = $this->upload([["'0201234567890", '2500000', 'first']]);
        $this->post(route('admin.imports.commit', $first))->assertRedirect();
        $empty = $this->upload([["'8994016015726", '1300000', 'below'],
            ["'6970493063940", '', 'missing']]);

        $this->assertSame(2, $empty->total_rows);
        $this->assertSame(0, $empty->valid_rows);
        $this->assertSame(2, DB::table('import_rows')->where('import_batch_id', $empty->id)
            ->where('status', 'EXCLUDED')->count());
        $this->get(route('admin.imports.show', $empty))->assertOk()->assertSee('Excluded: 2');
        $this->post(route('admin.imports.commit', $empty))->assertSessionHasErrors('commit');
        $this->assertSame($first->id, (int) DB::table('active_datasets')->where('id', 1)->value('active_batch_id'));
        $this->assertTrue(app(AttentionLookup::class)->check('0201234567890')['matched']);
    }

    public function test_missing_required_csv_header_is_rejected_without_active_mutation(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $file = UploadedFile::fake()->createWithContent('report.csv', "AWB,OTHER\n'0201234567890,2500000\n");

        $this->post(route('admin.imports.store'), ['workbook' => $file])->assertSessionHasErrors('workbook');
        $this->assertDatabaseHas('import_batches', ['status' => 'FAILED']);
        $this->assertDatabaseCount('attention_items', 0);
    }

    public function test_wide_report_uses_header_names_and_preserves_alphanumeric_awb(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $headers = array_map(fn (int $index): string => 'OTHER_'.$index, range(0, 179));
        $headers[0] = 'AWB';
        $headers[18] = 'GOODS_VALUE';
        $fields = array_fill(0, 180, '');
        $fields[0] = "'CSS0101238249345";
        $fields[1] = 'Shipper, with comma';
        $fields[18] = '4250000';
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $headers, ',', '"', '');
        fputcsv($stream, $fields, ',', '"', '');
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $file = UploadedFile::fake()->createWithContent('wide-report.csv', $csv);
        $this->post(route('admin.imports.store'), ['workbook' => $file])->assertRedirect();
        $batch = ImportBatch::latest('id')->firstOrFail();
        $this->assertSame(1, $batch->valid_rows);
        $this->assertDatabaseHas('import_rows', ['import_batch_id' => $batch->id,
            'awb' => 'CSS0101238249345', 'declared_value' => 4250000, 'status' => 'VALID']);
    }

    /** @param list<array{string, string, string}> $rows */
    private function upload(array $rows, string $header = "AWB,GOODS_VALUE,SHIPPER_NAME\n"): ImportBatch
    {
        $csv = $header;

        foreach ($rows as $row) {
            $csv .= implode(',', $row)."\n";
        }

        $file = UploadedFile::fake()->createWithContent('operational-report.csv', $csv);
        $this->post(route('admin.imports.store'), ['workbook' => $file])->assertRedirect();

        return ImportBatch::latest('id')->firstOrFail();
    }
}
