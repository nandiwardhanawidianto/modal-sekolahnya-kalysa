<?php
namespace App\Services\Imports;

use App\Models\ImportBatch;
use Illuminate\Support\Facades\Storage;

final class ImportBackupService
{
    private const KEEP_PER_STORE = 30;

    public function save(ImportBatch $batch,array $payload): void
    {
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $compressed=function_exists('gzencode')?gzencode($json,6):$json;
        $suffix=function_exists('gzencode')?'.json.gz':'.json';
        $path='import-backups/batch-'.$batch->id.$suffix;
        Storage::disk('local')->put($path,$compressed);
        $batch->update(['backup_path'=>$path]);
        $this->pruneStore($batch->store_id);
    }

    public function load(ImportBatch $batch): array
    {
        if(!$batch->backup_path||!Storage::disk('local')->exists($batch->backup_path))throw new \RuntimeException('Backup batch tidak ditemukan atau sudah melewati masa rollback.');
        $raw=Storage::disk('local')->get($batch->backup_path);
        if(str_ends_with($batch->backup_path,'.gz')){$raw=gzdecode($raw);if($raw===false)throw new \RuntimeException('Backup batch rusak.');}
        return json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    }

    public function remove(ImportBatch $batch): void
    {
        $path=$batch->getRawOriginal('backup_path');
        if($path&&Storage::disk('local')->exists($path))Storage::disk('local')->delete($path);
        $batch->update(['backup_path'=>null]);
    }

    public function exists(ImportBatch $batch): bool
    {
        $path=$batch->getRawOriginal('backup_path');
        return (bool)($path&&Storage::disk('local')->exists($path));
    }

    public function attrs($models): array
    {
        return collect($models)->map(fn($m)=>$m->getAttributes())->values()->all();
    }

    private function pruneStore(int $storeId): void
    {
        $old=ImportBatch::where('store_id',$storeId)->whereNotNull('backup_path')->orderByDesc('id')->skip(self::KEEP_PER_STORE)->take(500)->get();
        foreach($old as $batch){
            $path=$batch->getRawOriginal('backup_path');
            if($path&&Storage::disk('local')->exists($path))Storage::disk('local')->delete($path);
            $batch->update(['backup_path'=>null]);
        }
    }
}
