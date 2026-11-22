<?php

namespace App\Imports;

use DOMDocument;
use DOMXPath;
use ZipArchive;

class ImportFile
{
    public function read(Mailbox $mailbox, $stream, string $path, object $file): array
    {
        $extension = strtolower(pathinfo($file->filename, PATHINFO_EXTENSION));
        if (! in_array($extension, ['eml', 'txt', 'md', 'markdown', 'docx'], true)) {
            [$raw, $next, $end] = $mailbox->read($stream, $file->position);

            return [$mailbox->parse($raw), $next, $end];
        }
        if ($extension === 'eml') {
            return [$mailbox->parse(stream_get_contents($stream)), 0, true];
        }
        $body = match ($extension) {
            'txt', 'md', 'markdown' => stream_get_contents($stream),
            'docx' => $this->word($path),
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
            if (! $part || $part['size'] > 16 * 1024 * 1024) {
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
}
