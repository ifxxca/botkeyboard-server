<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImportBatch;
use App\Services\Imports\CsvReportPreview;
use App\Services\Imports\ImportCommitter;
use App\Services\Imports\WorkbookPreview;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Reader\Exception as WorkbookReadException;
use Throwable;

class ImportController extends Controller
{
    public function index(): View
    {
        return view('admin.imports.index', ['batches' => ImportBatch::latest()->paginate(20)]);
    }

    public function store(Request $request, WorkbookPreview $workbookPreview, CsvReportPreview $csvPreview): RedirectResponse
    {
        $request->validate([
            'workbook' => ['required', 'file', 'mimes:xlsx,csv,txt', 'extensions:xlsx,csv', 'max:10240'],
        ]);

        $file = $request->file('workbook');
        $storedPath = $file->store('imports', 'local');
        $activeBatchId = DB::table('active_datasets')->where('id', 1)->value('active_batch_id');
        $batch = ImportBatch::create([
            'public_id' => (string) Str::uuid(),
            'filename' => basename($file->getClientOriginalName()),
            'file_sha256' => hash_file('sha256', $file->getRealPath()),
            'stored_path' => $storedPath,
            'status' => 'UPLOADED',
            'created_by' => $request->user()->id,
            'base_active_batch_id' => $activeBatchId,
        ]);

        try {
            $preview = strtolower($file->getClientOriginalExtension()) === 'csv' ? $csvPreview : $workbookPreview;
            $preview->preview($batch, Storage::disk('local')->path($storedPath));
        } catch (InvalidArgumentException|WorkbookReadException $exception) {
            $batch->update(['status' => 'FAILED']);
            Storage::disk('local')->delete($storedPath);
            Log::warning('Import preview failed', ['batch_id' => $batch->public_id, 'type' => $exception::class]);

            return back()->withErrors(['workbook' => 'File could not be validated. Check headers, values, and row limit.']);
        } catch (Throwable $exception) {
            $batch->update(['status' => 'FAILED']);
            Storage::disk('local')->delete($storedPath);
            Log::error('Import preview encountered a server failure', ['batch_id' => $batch->public_id, 'type' => $exception::class]);

            throw $exception;
        }

        return redirect()->route('admin.imports.show', $batch);
    }

    public function show(ImportBatch $batch): View
    {
        $rows = DB::table('import_rows')->where('import_batch_id', $batch->id)
            ->orderBy('row_number')->paginate(50);

        return view('admin.imports.show', compact('batch', 'rows'));
    }

    public function commit(ImportBatch $batch, ImportCommitter $committer): RedirectResponse
    {
        try {
            $committer->commit($batch, auth()->id());
        } catch (DomainException $exception) {
            return back()->withErrors(['commit' => $exception->getMessage()]);
        }

        return redirect()->route('admin.imports.show', $batch)->with('status', 'Snapshot committed.');
    }
}
