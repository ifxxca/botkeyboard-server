<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Attention\AwbNormalizer;
use App\Http\Controllers\Controller;
use App\Models\AttentionItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;

class AttentionItemController extends Controller
{
    public function index(Request $request, AwbNormalizer $normalizer): View
    {
        $search = $request->query('awb', '');
        $error = null;

        try {
            $awb = $search === '' ? null : $normalizer->normalize((string) $search);
        } catch (InvalidArgumentException) {
            $awb = null;
            $error = 'Search must be a complete 8–30 character AWB.';
        }

        $activeBatchId = DB::table('active_datasets')->where('id', 1)->value('active_batch_id');
        $items = AttentionItem::query()->where('import_batch_id', $activeBatchId);

        if ($awb !== null) {
            $items->where('awb', $awb);
        }

        if ($error !== null) {
            $items->whereKey(-1);
        }

        return view('admin.items.index', [
            'items' => $items->orderBy('awb')->paginate(25)->withQueryString(),
            'search' => $search,
            'error' => $error,
        ]);
    }
}
