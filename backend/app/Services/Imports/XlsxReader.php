<?php
namespace App\Services\Imports;
use Generator;
use RuntimeException;
use ZipArchive;
use XMLReader;

final class XlsxReader
{
    private ZipArchive $zip;
    private array $sharedStrings = [];
    private array $sheetMap = [];

    public function __construct(private readonly string $path)
    {
        $this->zip = new ZipArchive();
        if ($this->zip->open($path) !== true) throw new RuntimeException('File XLSX tidak dapat dibuka.');
        $this->loadSharedStrings();
        $this->loadSheetMap();
    }

    public function __destruct() { $this->zip->close(); }
    public function sheetNames(): array { return array_keys($this->sheetMap); }

    /** @return Generator<int,array{row:int,values:array<int,string>}> */
    public function rows(string $sheetName): Generator
    {
        $target = $this->sheetMap[$sheetName] ?? null;
        if (!$target) throw new RuntimeException("Sheet {$sheetName} tidak ditemukan.");
        $xml = $this->zip->getFromName($target);
        if ($xml === false) throw new RuntimeException("Data sheet {$sheetName} tidak dapat dibaca.");

        $reader = new XMLReader();
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') continue;
            $rowNumber = (int) ($reader->getAttribute('r') ?: 0);
            $rowXml = $reader->readOuterXML();
            if ($rowXml === '') continue;
            $rowXml = $this->ensureNamespace($rowXml, 'row');
            $row = simplexml_load_string($rowXml, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if (!$row) continue;
            $row->registerXPathNamespace('m','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $values = [];
            foreach ($row->xpath('m:c') ?: [] as $cell) {
                // XPath namespace registrations are scoped to each SimpleXMLElement node.
                // Register it again on the cell before evaluating inline rich-text paths.
                $cell->registerXPathNamespace('m','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $attrs = $cell->attributes();
                $ref = (string) ($attrs['r'] ?? 'A1');
                $type = (string) ($attrs['t'] ?? '');
                $index = $this->columnIndex($ref);
                $value = '';
                if ($type === 's') {
                    $idx = (int) ($cell->v ?? 0);
                    $value = $this->sharedStrings[$idx] ?? '';
                } elseif ($type === 'inlineStr') {
                    $parts = $cell->xpath('.//m:t') ?: [];
                    foreach ($parts as $part) $value .= (string) $part;
                } else {
                    $value = isset($cell->v) ? (string) $cell->v : '';
                }
                $values[$index] = $value;
            }
            if ($values !== []) yield ['row'=>$rowNumber,'values'=>$values];
        }
        $reader->close();
    }

    public function findHeader(string $sheetName, array $required, int $maxRows = 40): array
    {
        foreach ($this->rows($sheetName) as $row) {
            if ($row['row'] > $maxRows) break;
            $map = [];
            foreach ($row['values'] as $i => $value) $map[trim($value)] = $i;
            $ok = true;
            foreach ($required as $header) if (!array_key_exists($header,$map)) {$ok=false;break;}
            if ($ok) return ['row'=>$row['row'],'map'=>$map];
        }
        throw new RuntimeException('Header XLSX yang dibutuhkan tidak ditemukan: '.implode(', ',$required));
    }

    private function loadSharedStrings(): void
    {
        $xml = $this->zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return;
        $reader = new XMLReader();
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $outer = $reader->readOuterXML();
                $outer = $this->ensureNamespace($outer, 'si');
                $item = simplexml_load_string($outer, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                if (!$item) {$this->sharedStrings[]='';continue;}
                $item->registerXPathNamespace('m','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $text=''; foreach ($item->xpath('.//m:t') ?: [] as $t) $text .= (string)$t;
                $this->sharedStrings[]=$text;
            }
        }
        $reader->close();
    }

    private function loadSheetMap(): void
    {
        $workbookXml = $this->zip->getFromName('xl/workbook.xml');
        $relsXml = $this->zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) throw new RuntimeException('Struktur workbook XLSX tidak lengkap.');

        $rels = [];
        $dom = new \DOMDocument(); $dom->loadXML($relsXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        foreach ($dom->getElementsByTagName('Relationship') as $rel) $rels[$rel->getAttribute('Id')] = $rel->getAttribute('Target');

        $dom2 = new \DOMDocument(); $dom2->loadXML($workbookXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        foreach ($dom2->getElementsByTagName('sheet') as $sheet) {
            $name = $sheet->getAttribute('name');
            $rid = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships','id');
            $target = ltrim($rels[$rid] ?? '', '/');
            if (!str_starts_with($target,'xl/')) $target = 'xl/'.ltrim($target,'/');
            $target = str_replace('xl/../','',$target);
            $this->sheetMap[$name]=$target;
        }
    }


    private function ensureNamespace(string $xml, string $tag): string
    {
        if (preg_match('/<'.$tag.'\b[^>]*\bxmlns=/', $xml)) return $xml;
        return preg_replace('/<'.$tag.'\b/', '<'.$tag.' xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"', $xml, 1) ?? $xml;
    }

    private function columnIndex(string $ref): int
    {
        preg_match('/^[A-Z]+/', strtoupper($ref), $m);
        $letters = $m[0] ?? 'A'; $index=0;
        for ($i=0,$len=strlen($letters);$i<$len;$i++) $index=$index*26+(ord($letters[$i])-64);
        return $index-1;
    }
}
