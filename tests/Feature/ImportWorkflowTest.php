<?php

namespace Tests\Feature;

use App\Domain\Attention\AttentionLookup;
use App\Models\AttentionItem;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Imports\ImportCommitter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_full_snapshot_a_b_c_is_atomically_replaced_by_b_d_for_lookup(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $first = $this->upload([
            ['AWB' => '0201111111111'],
            ['AWB' => '0202222222222'],
            ['AWB' => '0203333333333'],
        ], ['AWB']);
        $this->post(route('admin.imports.commit', $first))->assertRedirect();

        $lookup = app(AttentionLookup::class);
        foreach (['0201111111111', '0202222222222', '0203333333333'] as $awb) {
            $this->assertTrue($lookup->check($awb)['matched']);
        }

        $second = $this->upload([
            ['AWB' => '0202222222222'],
            ['AWB' => '0204444444444'],
        ], ['AWB']);
        $this->assertTrue($lookup->check('0201111111111')['matched'], 'Preview must leave snapshot A active.');
        $this->assertFalse($lookup->check('0204444444444')['matched'], 'Preview must not activate D.');

        $this->post(route('admin.imports.commit', $second))->assertRedirect();
        $this->assertSame($second->id, (int) DB::table('active_datasets')->where('id', 1)->value('active_batch_id'));
        $this->assertFalse($lookup->check('0201111111111')['matched']);
        $this->assertTrue($lookup->check('0202222222222')['matched']);
        $this->assertFalse($lookup->check('0203333333333')['matched']);
        $this->assertTrue($lookup->check('0204444444444')['matched']);
    }

    public function test_valid_preview_preserves_leading_zero_without_mutating_active_data_and_commit_replaces_snapshot(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $first = $this->upload([['AWB' => '0201234567890', 'VALUE' => '4250000'], ['AWB' => '0201234567890', 'REASON' => 'VIP']]);

        $this->assertSame('VALIDATED', $first->status);
        $this->assertSame(2, $first->valid_rows);
        $this->assertSame(0, $first->invalid_rows);
        $this->assertDatabaseCount('attention_items', 0);
        $this->assertNull(DB::table('active_datasets')->where('id', 1)->value('active_batch_id'));
        $this->assertDatabaseHas('import_rows', ['import_batch_id' => $first->id, 'awb' => '0201234567890', 'status' => 'VALID']);

        $this->post(route('admin.imports.commit', $first))->assertRedirect(route('admin.imports.show', $first));
        $this->assertSame($first->id, (int) DB::table('active_datasets')->where('id', 1)->value('active_batch_id'));
        $this->assertDatabaseCount('attention_items', 2);
        $this->assertDatabaseHas('attention_items', ['awb' => '0201234567890', 'declared_value' => 4250000]);
        $this->assertDatabaseHas('audit_events', ['event' => 'IMPORT_COMMITTED', 'subject_id' => $first->id]);

        $second = $this->upload([['AWB' => '0209876543210']]);
        $this->post(route('admin.imports.commit', $second))->assertRedirect(route('admin.imports.show', $second));
        $this->assertSame($second->id, (int) DB::table('active_datasets')->where('id', 1)->value('active_batch_id'));
        $this->assertDatabaseHas('attention_items', ['import_batch_id' => $first->id, 'awb' => '0201234567890']);
        $this->assertSame(0, AttentionItem::where('import_batch_id', $second->id)->where('awb', '0201234567890')->count());
    }

    public function test_duplicate_and_invalid_rows_are_previewed_but_commit_is_blocked(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $batch = $this->upload([
            ['AWB' => '0201234567890'],
            ['AWB' => '0201234567890'],
            ['AWB' => [1234567890123, DataType::TYPE_NUMERIC]],
            ['AWB' => null, 'TITLE' => 'Missing AWB'],
        ]);

        $this->assertSame(4, $batch->total_rows);
        $this->assertSame(1, $batch->valid_rows);
        $this->assertSame(1, $batch->duplicate_rows);
        $this->assertSame(2, $batch->invalid_rows);
        $this->get(route('admin.imports.show', $batch))->assertOk()->assertSee('DUPLICATE')->assertSee('INVALID');
        $this->post(route('admin.imports.commit', $batch))->assertSessionHasErrors('commit');
        $this->assertNull(DB::table('active_datasets')->where('id', 1)->value('active_batch_id'));
        $this->assertDatabaseCount('attention_items', 0);
    }

    public function test_missing_header_and_formula_cells_fail_without_active_mutation(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        $this->post(route('admin.imports.store'), ['workbook' => $this->workbook([['VALUE' => '100']], ['VALUE'])])
            ->assertSessionHasErrors('workbook');
        $this->assertDatabaseHas('import_batches', ['status' => 'FAILED']);

        $batch = $this->upload([
            ['AWB' => '0201234567890', 'TITLE' => ['=1+1', DataType::TYPE_FORMULA]],
            ['AWB' => '0202222222222', 'VALUE' => '-100000'],
        ]);
        $this->assertSame(2, $batch->invalid_rows);
        $this->assertDatabaseCount('attention_items', 0);
    }

    public function test_hvi_report_deduplicates_equal_values_and_excludes_negative_values_before_commit(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        $batch = $this->upload([
            ['Cnote No' => "'0201234567890", 'Cnote Goods Value' => '4250000'],
            ['Cnote No' => "'020-1234567890", 'Cnote Goods Value' => '4250000'],
            ['Cnote No' => '0202222222222', 'Cnote Goods Value' => '-100000'],
            ['Cnote No' => '0203333333333', 'Cnote Goods Value' => [-100000, DataType::TYPE_NUMERIC]],
            ['Cnote No' => '0204444444444', 'Cnote Goods Value' => '3000000'],
        ], ['Cnote No', 'Cnote Goods Value']);

        $this->assertSame(5, $batch->total_rows);
        $this->assertSame(2, $batch->valid_rows);
        $this->assertSame(0, $batch->invalid_rows);
        $this->assertSame(0, $batch->duplicate_rows);
        $this->assertSame(3, DB::table('import_rows')->where('import_batch_id', $batch->id)
            ->where('status', 'EXCLUDED')->count());
        $this->assertDatabaseHas('import_rows', [
            'import_batch_id' => $batch->id, 'row_number' => 3, 'status' => 'EXCLUDED',
            'awb' => '0201234567890', 'declared_value' => 4250000,
            'issue' => 'Duplicate AWB with same goods value; first row retained.',
        ]);
        $this->assertDatabaseHas('import_rows', [
            'import_batch_id' => $batch->id, 'row_number' => 5, 'status' => 'EXCLUDED',
            'awb' => null, 'declared_value' => null,
            'issue' => 'Cnote Goods Value is negative; excluded from high-value selection.',
        ]);
        $this->get(route('admin.imports.show', $batch))->assertSee('Commit complete snapshot');

        $this->post(route('admin.imports.commit', $batch))
            ->assertRedirect(route('admin.imports.show', $batch))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('attention_items', 2);
        $this->assertDatabaseHas('attention_items', [
            'import_batch_id' => $batch->id, 'awb' => '0201234567890', 'declared_value' => 4250000,
        ]);
        $this->assertDatabaseHas('attention_items', [
            'import_batch_id' => $batch->id, 'awb' => '0204444444444', 'declared_value' => 3000000,
        ]);
        $this->assertDatabaseHas('import_batches', ['id' => $batch->id, 'status' => 'COMMITTED']);
    }

    public function test_hvi_conflicting_values_and_malformed_cells_still_block_commit(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        $batch = $this->upload([
            ['Cnote No' => '0201234567890', 'Cnote Goods Value' => '4250000'],
            ['Cnote No' => '0201234567890', 'Cnote Goods Value' => '5000000'],
            ['Cnote No' => '0202222222222', 'Cnote Goods Value' => '-100000.5'],
            ['Cnote No' => '0203333333333', 'Cnote Goods Value' => ['=-100000', DataType::TYPE_FORMULA]],
            ['Cnote No' => [1234567890123, DataType::TYPE_NUMERIC], 'Cnote Goods Value' => '3000000'],
        ], ['Cnote No', 'Cnote Goods Value']);

        $this->assertSame(1, $batch->valid_rows);
        $this->assertSame(1, $batch->duplicate_rows);
        $this->assertSame(3, $batch->invalid_rows);
        $this->assertDatabaseHas('import_rows', [
            'import_batch_id' => $batch->id, 'row_number' => 3, 'status' => 'DUPLICATE',
            'issue' => 'Repeated qualifying AWB has conflicting goods values.',
        ]);
        $this->assertDatabaseHas('import_rows', [
            'import_batch_id' => $batch->id, 'row_number' => 5, 'status' => 'INVALID',
            'issue' => 'Value formula is not allowed.',
        ]);
        $this->get(route('admin.imports.show', $batch))->assertDontSee('Commit complete snapshot');

        $this->post(route('admin.imports.commit', $batch))->assertSessionHasErrors('commit');

        $this->assertDatabaseCount('attention_items', 0);
        $this->assertNull(DB::table('active_datasets')->where('id', 1)->value('active_batch_id'));
    }

    public function test_thirteen_digit_text_awb_imports_while_numeric_awb_is_rejected(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $batch = $this->upload([['AWB' => '1234567890123']], ['AWB']);

        $this->assertSame(1, $batch->valid_rows);
        $this->post(route('admin.imports.commit', $batch))->assertRedirect();
        $this->assertDatabaseHas('attention_items', ['awb' => '1234567890123']);
    }

    public function test_malformed_xlsx_is_rejected_without_active_mutation(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        $this->post(route('admin.imports.store'), [
            'workbook' => UploadedFile::fake()->createWithContent('attention.xlsx', 'not an xlsx archive'),
        ])->assertSessionHasErrors('workbook');
        $this->assertDatabaseHas('import_batches', ['status' => 'FAILED']);
        $this->assertDatabaseCount('attention_items', 0);
    }

    public function test_oversized_upload_is_rejected_before_staging(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        $this->post(route('admin.imports.store'), [
            'workbook' => UploadedFile::fake()->create('attention.xlsx', 10241,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ])->assertSessionHasErrors('workbook');
        $this->assertDatabaseCount('import_batches', 0);
    }

    public function test_stale_preview_cannot_overwrite_a_newer_committed_batch(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $first = $this->upload([['AWB' => '0201111111111']]);
        $stale = $this->upload([['AWB' => '0202222222222']]);

        $this->post(route('admin.imports.commit', $first))->assertRedirect();
        $this->post(route('admin.imports.commit', $stale))->assertSessionHasErrors('commit');

        $this->assertSame($first->id, (int) DB::table('active_datasets')->where('id', 1)->value('active_batch_id'));
        $this->assertDatabaseMissing('attention_items', ['import_batch_id' => $stale->id]);
    }

    public function test_duplicate_stage_data_causes_full_transaction_rollback(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('imports/test.xlsx', 'same source bytes');
        $actor = User::factory()->create();
        $batch = ImportBatch::factory()->create([
            'stored_path' => 'imports/test.xlsx', 'file_sha256' => hash('sha256', 'same source bytes'),
            'valid_rows' => 2, 'total_rows' => 2,
        ]);

        foreach ([2, 3] as $rowNumber) {
            DB::table('import_rows')->insert([
                'import_batch_id' => $batch->id, 'row_number' => $rowNumber,
                'awb' => '0201234567890', 'reason_code' => 'HIGH_VALUE',
                'severity' => 'critical', 'title' => 'High Value Shipment', 'message' => 'Attention',
                'status' => 'VALID', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        try {
            app(ImportCommitter::class)->commit($batch, $actor->id);
            $this->fail('Duplicate stage data should violate the unique index.');
        } catch (QueryException) {
            $this->assertNull(DB::table('active_datasets')->where('id', 1)->value('active_batch_id'));
            $this->assertDatabaseMissing('attention_items', ['import_batch_id' => $batch->id]);
            $this->assertSame('VALIDATED', $batch->fresh()->status);
            $this->assertDatabaseCount('audit_events', 0);
        }
    }

    public function test_largeish_workbook_imports_without_truncating_rows(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $rows = [];

        for ($number = 0; $number < 1000; $number++) {
            $rows[] = ['AWB' => sprintf('020%010d', $number)];
        }

        $batch = $this->upload($rows, ['AWB']);
        $this->assertSame(1000, $batch->valid_rows);
        $this->post(route('admin.imports.commit', $batch))->assertRedirect();
        $this->assertSame(1000, AttentionItem::where('import_batch_id', $batch->id)->count());
    }

    /** @param list<array<string, mixed>> $rows
     * @param  list<string>  $headers
     */
    private function upload(array $rows, array $headers = ['AWB', 'VALUE', 'REASON', 'SEVERITY', 'TITLE', 'MESSAGE']): ImportBatch
    {
        $this->post(route('admin.imports.store'), ['workbook' => $this->workbook($rows, $headers)])->assertRedirect();

        return ImportBatch::latest('id')->firstOrFail();
    }

    /** @param list<array<string, mixed>> $rows
     * @param  list<string>  $headers
     */
    private function workbook(array $rows, array $headers = ['AWB', 'VALUE', 'REASON', 'SEVERITY', 'TITLE', 'MESSAGE']): File
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($headers as $index => $header) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($index + 1).'1', $header, DataType::TYPE_STRING);
        }

        foreach ($rows as $rowIndex => $row) {
            foreach ($headers as $columnIndex => $header) {
                $value = $row[$header] ?? null;

                if ($value === null) {
                    continue;
                }

                [$content, $type] = is_array($value) ? $value : [(string) $value, DataType::TYPE_STRING];
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($columnIndex + 1).($rowIndex + 2), $content, $type);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'awb-test-');
        (new Xlsx($spreadsheet))->save($path);
        $content = file_get_contents($path);
        unlink($path);
        $spreadsheet->disconnectWorksheets();

        return UploadedFile::fake()->createWithContent('attention.xlsx', $content);
    }
}
