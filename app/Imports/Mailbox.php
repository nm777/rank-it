<?php

namespace App\Imports;

use DOMDocument;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message\IMessagePart;
use ZBateson\MailMimeParser\Message\IMultiPart;
use ZBateson\MailMimeParser\Stream\MessagePartStreamReadException;

class Mailbox
{
    // Return one envelope and the byte position of the following envelope.
    public function read($stream, int $position): array
    {
        if (fseek($stream, $position) !== 0) {
            throw new \RuntimeException('Cannot seek staged mailbox.');
        }
        $line = fgets($stream);
        $envelope = $line !== false && $this->isEnvelope($line);
        $raw = '';
        while (($next = ftell($stream)) !== false && ($line = fgets($stream)) !== false) {
            if ($this->isEnvelope($line)) {
                return [$envelope ? $raw : null, $next, false];
            }
            $raw .= preg_replace('/^>(>*From )/', '$1', $line);
        }
        if (! feof($stream)) {
            throw new \RuntimeException('Cannot read staged mailbox.');
        }

        return [$envelope ? $raw : null, ftell($stream), true];
    }

    private function isEnvelope(string $line): bool
    {
        // The From_ separator carries a sender and a delivery timestamp.
        return str_starts_with($line, 'From ') && preg_match('/^From \S+\s+.*\b\d{1,2}:\d{2}\b/', $line) === 1;
    }

    public function parse(?string $raw): ?array
    {
        if ($raw === null || ! preg_match('/\A(?:[\w-]+:[^\n]*\n(?:[ \t][^\n]*\n)*)+\r?\n/', $raw)) {
            return null;
        }
        $attachments = [];
        try {
            $mail = (new MailMimeParser)->parse($raw, false);
            [$body] = $this->body($mail, $attachments);
        } catch (MessagePartStreamReadException|\ValueError $e) {
            return null;
        }
        if (trim($body) === '' && ! $attachments) {
            return null;
        }

        $header = fn ($name) => $mail->getHeader($name)?->getRawValue();
        $sender = $header('From');
        $date = $header('Date');
        $body = rtrim(str_replace("\r\n", "\n", $body), "\n");
        $id = $this->messageIdentity($header('Message-ID'));
        if (! $id && trim($body) !== '' && trim($sender ?? '') !== '' && trim($date ?? '') !== '') {
            $id = hash('sha256', serialize([$sender, $date, $body]));
        }
        $parent = $this->messageIdentity($header('In-Reply-To'));
        if (! $parent && preg_match('/(<[^<>\s]+>)\s*$/', $header('References') ?? '', $reference)) {
            $parent = $this->messageIdentity($reference[1]);
        }
        $data = ['identity' => $id, 'sender' => $sender, 'sent_date' => $date,
            'subject' => $mail->getHeaderValue('Subject'), 'parent_identity' => $parent];
        foreach ([$body, ...array_values($data), ...$attachments] as $value) {
            if ($value !== null && (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0"))) {
                return null;
            }
        }

        return [$data, $body, $attachments];
    }

    private function body(IMessagePart $part, array &$attachments): array
    {
        $type = strtolower($part->getContentType());
        if ($part->getFilename() || strtolower($part->getContentDisposition() ?? '') === 'attachment') {
            $attachments[] = $part->getFilename() ?: 'Unnamed attachment';

            return ['', false];
        }
        if (str_starts_with($type, 'multipart/') && $part instanceof IMultiPart) {
            $parts = [];
            $plain = [];
            foreach ($part->getChildParts() as $child) {
                [$text, $isPlain] = $this->body($child, $attachments);
                if (trim($text) !== '') {
                    $parts[] = $text;
                    if ($isPlain) {
                        $plain[] = $text;
                    }
                }
            }
            if ($type === 'multipart/alternative') {
                return [$plain[0] ?? $parts[0] ?? '', (bool) $plain];
            }

            return [implode("\n", $parts), count($plain) === count($parts)];
        }
        if ($type === 'text/plain') {
            return [$part->getContent() ?? '', true];
        }
        if ($type === 'text/html') {
            $html = $part->getContent();
            if (trim($html ?? '') === '') {
                return ['', false];
            }
            $dom = new DOMDocument;
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            foreach (['script', 'style', 'head'] as $tag) {
                foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
                    $node->parentNode->removeChild($node);
                }
            }

            return [$this->htmlText($dom), false];
        }
        $attachments[] = 'Unnamed attachment';

        return ['', false];
    }

    private function messageIdentity(?string $header): ?string
    {
        return preg_match('/^\s*(<[^<>\s]+>)\s*$/', $header ?? '', $match)
            ? hash('sha256', 'id:'.$match[1]) : null;
    }

    private function htmlText(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            return $node->nodeValue;
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->htmlText($child);
        }

        return match ($node->nodeName) {
            'blockquote' => "\n".preg_replace('/^/m', '> ', trim($text))."\n",
            'br', 'p', 'div', 'li' => $text."\n",
            default => $text,
        };
    }

    public function passages(string $body): array
    {
        // Keep every line (including separators) in source order.
        if ($body === '') {
            return [];
        }
        $blocks = [];
        $previous = null;
        foreach (explode("\n", $body) as $line) {
            $kind = match (true) {
                trim($line) === '', preg_match('/^\s*>\s*$/', $line) === 1 => 'separator',
                preg_match('/^\s*>/', $line) === 1 => 'quote',
                default => 'author',
            };
            if ($previous !== $kind) {
                $blocks[] = $line;
            } else {
                $blocks[array_key_last($blocks)] .= "\n".$line;
            }
            $previous = $kind;
        }

        return $blocks;
    }
}
