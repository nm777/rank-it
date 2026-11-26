<?php

namespace App\Imports;

use DOMDocument;
use DOMXPath;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use ZipArchive;

class ImportFile
{
    public const array DOCUMENT_EXTENSIONS = ['txt', 'md', 'markdown', 'docx', 'pdf', 'xlsx', 'xls'];

    private const int MAX_DOCUMENT_BYTES = 16 * 1024 * 1024;

    public static function isDocument(string $filename): bool
    {
        return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::DOCUMENT_EXTENSIONS, true);
    }

    public function read(Mailbox $mailbox, $stream, string $path, object $file): array
    {
        $extension = strtolower(pathinfo($file->filename, PATHINFO_EXTENSION));
        if ($extension !== 'eml' && ! self::isDocument($file->filename)) {
            [$raw, $next, $end] = $mailbox->read($stream, $file->position);

            return [$mailbox->parse($raw), $next, $end];
        }
        if ($extension === 'eml') {
            return [$mailbox->parse(stream_get_contents($stream)), 0, true];
        }
        $body = match ($extension) {
            'txt', 'md', 'markdown' => stream_get_contents($stream),
            'docx' => $this->word($path),
            'pdf' => $this->pdf($path),
            'xlsx', 'xls' => $this->excel($path, $extension),
            default => null,
        };
        $parsed = null;
        if (is_string($body) && trim($body) !== '' && mb_check_encoding($body, 'UTF-8')
            && ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $body)) {
            $parsed = [['subject' => $file->filename], str_replace("\r\n", "\n", $body), []];
        }

        return [$parsed, 0, true];
    }

    private function word(string $path): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return null;
        }
        try {
            $part = $zip->statName('word/document.xml');
            if (! $part || $part['size'] > self::MAX_DOCUMENT_BYTES) {
                return null;
            }
            $xml = $zip->getFromIndex($part['index']);
        } finally {
            $zip->close();
        }
        $dom = new DOMDocument;
        if (! $xml || ! @$dom->loadXML($xml, LIBXML_NONET) || $dom->doctype) {
            return null;
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $paragraphs = [];
        foreach ($xpath->query('//w:body//w:p') as $paragraph) {
            $line = '';
            foreach ($xpath->query('.//w:t | .//w:tab | .//w:br', $paragraph) as $node) {
                $line .= match ($node->localName) {
                    'tab' => "\t", 'br' => "\n", default => $node->textContent
                };
            }
            $paragraphs[] = $line;
        }

        return implode("\n", $paragraphs);
    }

    private function pdf(string $path): ?string
    {
        try {
            $result = Process::timeout(20)->run(['pdftotext', '-enc', 'UTF-8', '-nopgbrk', $path, '-']);
        } catch (ProcessTimedOutException) {
            return null;
        }

        return $result->successful() && strlen($result->output()) <= self::MAX_DOCUMENT_BYTES
            ? $result->output()
            : null;
    }

    private function excel(string $path, string $extension): ?string
    {
        if ($extension === 'xlsx' && ! $this->hasBoundedArchiveSize($path)) {
            return null;
        }

        try {
            $reader = IOFactory::createReader($extension === 'xlsx' ? IOFactory::READER_XLSX : IOFactory::READER_XLS);
            if (! $reader->canRead($path)) {
                return null;
            }
            $workbook = $reader->load($path, IReader::READ_DATA_ONLY | IReader::IGNORE_EMPTY_CELLS);
        } catch (SpreadsheetException) {
            return null;
        }

        try {
            $sheets = [];
            $bytes = 0;
            foreach ($workbook->getWorksheetIterator() as $sheet) {
                $rows = [];
                foreach ($sheet->getCoordinates() as $coordinate) {
                    $cell = $sheet->getCell($coordinate);
                    $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
                    if ($value instanceof RichText) {
                        $value = $value->getPlainText();
                    }
                    $text = is_bool($value) ? ($value ? 'TRUE' : 'FALSE') : (string) $value;
                    if (trim($text) === '') {
                        continue;
                    }
                    $bytes += strlen($text) + 1;
                    if ($bytes > self::MAX_DOCUMENT_BYTES) {
                        return null;
                    }
                    $rows[$cell->getRow()][] = $text;
                }
                if ($rows !== []) {
                    $sheets[] = $sheet->getTitle()."\n".implode("\n", array_map(
                        static fn (array $row): string => implode("\t", $row),
                        $rows,
                    ));
                }
            }

            return implode("\n\n", $sheets);
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    private function hasBoundedArchiveSize(string $path): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return false;
        }

        try {
            $bytes = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if ($entry === false) {
                    return false;
                }
                $bytes += $entry['size'];
                if ($bytes > 2 * self::MAX_DOCUMENT_BYTES) {
                    return false;
                }
            }

            return true;
        } finally {
            $zip->close();
        }
    }
}
