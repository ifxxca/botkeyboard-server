@extends('admin.layout')
@section('title', 'Import preview')
@section('content')
<header class="page-header"><div><span class="badge {{ $batch->status === 'COMMITTED' ? 'badge-online' : 'badge-info' }}">{{ $batch->status }}</span><h1 class="page-title" style="margin-top:10px">Import preview</h1><p class="page-description">{{ $batch->filename }}</p></div><a class="btn btn-secondary" href="{{ route('admin.imports.index') }}">Kembali</a></header>
<section class="stats-grid">
    <div class="card stat-card"><div class="stat-head">Rows</div><div class="stat-value">{{ number_format($batch->total_rows) }}</div></div>
    <div class="card stat-card"><div class="stat-head">Selected</div><div class="stat-value">{{ number_format($batch->valid_rows) }}</div></div>
    <div class="card stat-card"><div class="stat-head">Excluded</div><div class="stat-value">{{ number_format($batch->total_rows - $batch->valid_rows - $batch->invalid_rows - $batch->duplicate_rows) }}</div><div class="stat-note">Excluded: {{ $batch->total_rows - $batch->valid_rows - $batch->invalid_rows - $batch->duplicate_rows }}</div></div>
    <div class="card stat-card"><div class="stat-head">Perlu diperbaiki</div><div class="stat-value">{{ number_format($batch->invalid_rows + $batch->duplicate_rows) }}</div></div>
</section>
<div class="alert alert-info">Mode: replace complete active set. Previewed base batch: {{ $batch->base_active_batch_id ?? 'none' }}. Commit dibatalkan jika snapshot lain sudah diaktifkan.</div>
@if($batch->invalid_rows > 0 || $batch->duplicate_rows > 0)<div class="alert alert-warning">Ada baris invalid atau duplikat yang harus diperbaiki. Periksa kolom Issue pada tabel untuk mengetahui masalah tiap baris, perbaiki file sumber, lalu unggah ulang sebelum commit.</div>@endif
<section class="card"><div class="card-header"><div class="page-header" style="margin:0"><div><h2 class="card-title">Hasil validasi</h2><p class="card-description">Duplikat laporan dengan nilai barang sama diambil sekali. Nilai ≤ Rp2.000.000, termasuk negatif, dikecualikan. INVALID dan DUPLICATE yang tersisa harus diperbaiki sebelum commit.</p></div>
@if($batch->status === 'VALIDATED' && $batch->valid_rows > 0 && $batch->invalid_rows === 0 && $batch->duplicate_rows === 0)<form method="post" action="{{ route('admin.imports.commit', $batch) }}">@csrf<button class="btn btn-primary" type="submit">Commit complete snapshot</button></form>@endif
</div></div><div class="card-content" style="padding-left:0;padding-right:0;padding-bottom:0"><div class="table-wrap"><table class="table"><thead><tr><th>Row</th><th>AWB</th><th>Reason</th><th>Status</th><th>Issue</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td>{{ $row->row_number }}</td><td class="mono">{{ $row->awb ?? '—' }}</td><td>{{ $row->reason_code ?? '—' }}</td><td><span class="badge {{ $row->status === 'VALID' ? 'badge-online' : ($row->status === 'DUPLICATE' ? 'badge-never' : ($row->status === 'EXCLUDED' ? 'badge-disabled' : 'badge-offline')) }}">{{ $row->status }}</span></td><td>{{ $row->issue }}</td></tr>@empty<tr><td colspan="5" class="empty">Tidak ada row.</td></tr>@endforelse
</tbody></table></div>@if($rows->hasPages())<div class="pagination">{{ $rows->links() }}</div>@endif</div></section>
@endsection
