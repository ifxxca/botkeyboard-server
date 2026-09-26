@extends('admin.layout')
@section('title', 'Import data')
@section('content')
<header class="page-header"><div><h1 class="page-title">Import complete attention snapshot</h1><p class="page-description">Unggah daftar lengkap AWB. Commit yang berhasil mengganti seluruh active set secara atomik.</p></div></header>
<div class="content-grid">
    <section class="card"><div class="card-header"><h2 class="card-title">Upload file</h2><p class="card-description">Mendukung workbook .xlsx atau laporan operasional .csv.</p></div>
        <form class="card-content form-grid" method="post" action="{{ route('admin.imports.store') }}" enctype="multipart/form-data">@csrf
            <label class="field"><span class="label">File sumber</span><input class="file-input" type="file" name="workbook" accept=".xlsx,.csv" required><span class="help">CSV mengambil hanya baris GOODS_VALUE di atas Rp2.000.000. Preview tidak mengubah data aktif.</span></label>
            <div><button class="btn btn-primary" type="submit">Upload & preview</button></div>
        </form>
    </section>
    <aside class="card"><div class="card-header"><h2 class="card-title">Format AWB yang aman</h2></div><div class="card-content"><ul class="info-list"><li>Kolom AWB harus berupa teks.</li><li>Leading zero akan dipertahankan.</li><li>Nilai seperti <span class="code">8.99402E+12</span> berisiko kehilangan digit.</li><li>Formula dan sel angka ditolak untuk mencegah data salah.</li></ul></div></aside>
</div>
<section class="card" style="margin-top:20px"><div class="card-header"><h2 class="card-title">Riwayat import</h2><p class="card-description">Buka batch untuk melihat hasil validasi dan melakukan commit.</p></div><div class="card-content" style="padding-left:0;padding-right:0;padding-bottom:0"><div class="table-wrap"><table class="table"><thead><tr><th>Uploaded</th><th>Filename</th><th>Status</th><th>Rows</th><th>Selected</th><th>Excluded</th><th>Invalid</th><th>Duplicate</th></tr></thead><tbody>
@forelse($batches as $batch)<tr><td>{{ $batch->created_at->timezone(config('attention.display_timezone'))->format('d M Y H:i') }}</td><td><a class="table-link" href="{{ route('admin.imports.show', $batch) }}">{{ $batch->filename }}</a></td><td><span class="badge {{ $batch->status === 'COMMITTED' ? 'badge-online' : 'badge-info' }}">{{ $batch->status }}</span></td><td>{{ number_format($batch->total_rows) }}</td><td>{{ number_format($batch->valid_rows) }}</td><td>{{ number_format($batch->total_rows - $batch->valid_rows - $batch->invalid_rows - $batch->duplicate_rows) }}</td><td>{{ number_format($batch->invalid_rows) }}</td><td>{{ number_format($batch->duplicate_rows) }}</td></tr>@empty<tr><td colspan="8" class="empty">Belum ada import.</td></tr>@endforelse
</tbody></table></div>@if($batches->hasPages())<div class="pagination">{{ $batches->links() }}</div>@endif</div></section>
@endsection
