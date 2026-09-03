<?php
namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Models\Store;
use App\Services\Imports\AdsImporter;
use App\Services\Imports\ImportPreviewService;
use App\Services\Imports\ImportBackupService;
use App\Services\Imports\ImportRollbackService;
use App\Services\Imports\IncomeImporter;
use App\Services\Imports\OrderImporter;
use App\Services\Imports\ProductImporter;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    public function preview(Request $request, Store $store, ImportPreviewService $preview)
    {
        $data = $request->validate([
            'type' => 'required|in:products,orders,income,ads',
            'file' => 'required|file|max:30720',
        ]);
        $ext = strtolower($request->file('file')->getClientOriginalExtension());
        if ($data['type'] === 'ads' && !in_array($ext, ['csv','txt'], true)) return response()->json(['message'=>'Laporan iklan harus CSV.'],422);
        if ($data['type'] !== 'ads' && $ext !== 'xlsx') return response()->json(['message'=>'File ini harus XLSX.'],422);
        return response()->json(['preview' => $preview->preview($store, $data['type'], $request->file('file'))]);
    }

    public function products(Request $request, Store $store, ProductImporter $importer)
    {
        $request->validate(['file'=>'required|file|mimes:xlsx|max:20480']);
        return response()->json($importer->import($store, $request->file('file'), $request->user()->id));
    }

    public function orders(Request $request, Store $store, OrderImporter $importer)
    {
        $request->validate(['file'=>'required|file|mimes:xlsx|max:30720']);
        return response()->json($importer->import($store, $request->file('file'), $request->user()->id));
    }

    public function income(Request $request, Store $store, IncomeImporter $importer)
    {
        $request->validate(['file'=>'required|file|mimes:xlsx|max:30720']);
        return response()->json($importer->import($store, $request->file('file'), $request->user()->id));
    }

    public function ads(Request $request, Store $store, AdsImporter $importer)
    {
        $data = $request->validate(['file'=>'required|file|max:10240','replace'=>'sometimes|boolean']);
        $ext = strtolower($request->file('file')->getClientOriginalExtension());
        if (!in_array($ext, ['csv','txt'], true)) return response()->json(['message'=>'Laporan iklan harus CSV.'],422);
        return response()->json($importer->import($store, $request->file('file'), $request->user()->id, (bool)($data['replace'] ?? false)));
    }

    public function rollback(Request $request, Store $store, ImportBatch $batch, ImportRollbackService $rollback)
    {
        abort_unless($batch->store_id === $store->id, 404);
        $rollback->rollback($batch, $request->user()->id);
        return response()->json(['ok'=>true]);
    }

    public function history(Store $store, ImportBackupService $backups)
    {
        $rows = ImportBatch::where('store_id',$store->id)->with('errors:id,import_batch_id,row_number,code,message,raw')->latest()->limit(50)->get();
        $rows->each(fn($b) => $b->setAttribute('rollback_available', $b->status === 'completed' && !$b->rolled_back_at && $backups->exists($b)));
        return response()->json(['batches'=>$rows]);
    }
}
