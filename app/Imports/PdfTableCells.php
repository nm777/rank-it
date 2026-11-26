<?php

namespace App\Imports;

use DOMDocument;
use DOMXPath;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

class PdfTableCells
{
    private const int MAX_OUTPUT_BYTES = 16 * 1024 * 1024;

    public function tables(string $path): iterable
    {
        $deadline = microtime(true) + 12;
        $text = $this->extract(['pdftotext', '-bbox-layout', '-enc', 'UTF-8', $path, '-'], $deadline);
        if ($text === null) {
            return;
        }

        foreach ($text->getElementsByTagName('page') as $index => $page) {
            $number = (string) ($index + 1);
            $drawing = $this->extract(['pdftocairo', '-svg', '-f', $number, '-l', $number, $path, '-'], $deadline);
            if ($drawing === null) {
                return;
            }

            $words = [];
            foreach ($page->getElementsByTagName('line') as $line) {
                $run = null;
                foreach ($line->getElementsByTagName('word') as $word) {
                    $left = (float) $word->getAttribute('xMin');
                    $right = (float) $word->getAttribute('xMax');
                    $top = (float) $word->getAttribute('yMin');
                    $bottom = (float) $word->getAttribute('yMax');
                    if ($run !== null && $left >= $run['right'] && $left - $run['right'] < ($bottom - $top) * 0.65) {
                        $run['right'] = $right;
                        $run['text'] .= ' '.$word->textContent;

                        continue;
                    }
                    if ($run !== null) {
                        $words[] = $run;
                    }
                    $run = ['left' => $left, 'right' => $right, 'top' => $top, 'bottom' => $bottom, 'text' => $word->textContent];
                }
                if ($run !== null) {
                    $words[] = $run;
                }
            }

            foreach ($this->grids($drawing) as [$columns, $rows, $dividers]) {
                yield ['location' => 'Page '.$number, 'rows' => $this->cells($words, $columns, $rows, $dividers)];
            }
        }
    }

    private function extract(array $command, float $deadline): ?DOMDocument
    {
        $remaining = $deadline - microtime(true);
        if ($remaining < 1) {
            return null;
        }
        try {
            $result = Process::timeout((int) ceil($remaining))->run($command);
        } catch (ProcessTimedOutException) {
            return null;
        }

        $dom = new DOMDocument;
        $output = $result->output();

        return $result->successful() && strlen($output) <= self::MAX_OUTPUT_BYTES
            && @$dom->loadXML($output, LIBXML_NONET) ? $dom : null;
    }

    private function grids(DOMDocument $drawing): iterable
    {
        $horizontal = $vertical = [];
        $xpath = new DOMXPath($drawing);
        foreach ($xpath->query('//*[local-name()="path" and not(ancestor::*[local-name()="defs"])]') as $path) {
            $matrix = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
            if ($path->hasAttribute('transform')) {
                if (! preg_match('/^matrix\(([-\d.,eE+ ]+)\)$/', $path->getAttribute('transform'), $match)) {
                    continue;
                }
                $matrix = array_map('floatval', explode(',', $match[1]));
                if (count($matrix) !== 6 || $matrix[1] !== 0.0 || $matrix[2] !== 0.0) {
                    continue;
                }
            }
            [$scaleX, , , $scaleY, $translateX, $translateY] = $matrix;
            preg_match_all('/[ML] (-?[\d.]+) (-?[\d.]+)(?= L (-?[\d.]+) (-?[\d.]+))/', $path->getAttribute('d'), $segments, PREG_SET_ORDER);
            foreach ($segments as [, $x1, $y1, $x2, $y2]) {
                [$x1, $y1, $x2, $y2] = array_map('floatval', [$x1, $y1, $x2, $y2]);
                $x1 = $x1 * $scaleX + $translateX;
                $x2 = $x2 * $scaleX + $translateX;
                $y1 = $y1 * $scaleY + $translateY;
                $y2 = $y2 * $scaleY + $translateY;
                if ($y1 === $y2 && abs($x2 - $x1) >= 48) {
                    $left = (int) round(min($x1, $x2));
                    $right = (int) round(max($x1, $x2));
                    $horizontal[$left.':'.$right][] = $y1;
                } elseif ($x1 === $x2 && abs($y2 - $y1) >= 5) {
                    $vertical[] = [$x1, min($y1, $y2), max($y1, $y2)];
                }
            }
        }
        uksort($horizontal, function (string $a, string $b) use ($horizontal): int {
            [$leftA, $rightA] = array_map('intval', explode(':', $a));
            [$leftB, $rightB] = array_map('intval', explode(':', $b));

            return (($rightB - $leftB) * count($horizontal[$b])) <=> (($rightA - $leftA) * count($horizontal[$a]));
        });
        $used = [];
        foreach ($horizontal as $range => $lines) {
            if (count($lines) < 3) {
                continue;
            }
            [$left, $right] = array_map('intval', explode(':', $range));
            $rows = $this->distinctLines($lines);
            if (count($rows) < 3) {
                continue;
            }

            // A table needs vertical rules through its header, not just aligned prose.
            $headerMiddle = ($rows[0] + $rows[1]) / 2;
            $columns = [$left, $right];
            foreach ($vertical as [$x, $top, $bottom]) {
                if ($x > $left + 1.5 && $x < $right - 1.5 && $top <= $headerMiddle && $bottom >= $headerMiddle) {
                    $columns[] = $x;
                }
            }
            $columns = $this->distinctLines($columns);
            if (count($columns) < 4) {
                continue;
            }
            $rectangle = [$left, $right, $rows[0], end($rows)];
            foreach ($used as [$usedLeft, $usedRight, $usedTop, $usedBottom]) {
                if ($left < $usedRight && $right > $usedLeft && $rectangle[2] < $usedBottom && $rectangle[3] > $usedTop) {
                    continue 2;
                }
            }
            $used[] = $rectangle;

            $dividers = [];
            foreach ($columns as $index => $column) {
                $dividers[$index] = array_filter($vertical, fn (array $line): bool => abs($line[0] - $column) <= 1.5);
            }

            yield [$columns, $rows, $dividers];
        }
    }

    private function distinctLines(array $lines): array
    {
        sort($lines, SORT_NUMERIC);
        $distinct = [];
        foreach ($lines as $line) {
            if ($distinct === [] || $line - end($distinct) > 1.5) {
                $distinct[] = $line;
            }
        }

        return $distinct;
    }

    private function cells(array $words, array $columns, array $rows, array $dividers): array
    {
        $cells = [];
        foreach ($words as $word) {
            $row = $this->interval($rows, $word['top'], $word['bottom']);
            $column = $this->interval($columns, $word['left'], $word['right']);
            if ($row !== null && $column !== null) {
                $cells[$row][$column][] = $word;
            }
        }

        $result = [0 => []];
        foreach ($cells as $row => $values) {
            $middle = ($rows[$row] + $rows[$row + 1]) / 2;
            $present = [0 => true, count($columns) - 1 => true];
            foreach ($dividers as $index => $lines) {
                foreach ($lines as [, $top, $bottom]) {
                    if ($top <= $middle && $bottom >= $middle) {
                        $present[$index] = true;

                        break;
                    }
                }
            }
            foreach ($values as $column => $runs) {
                if (! isset($present[$column], $present[$column + 1])) {
                    continue;
                }
                usort($runs, fn (array $a, array $b): int => [round($a['top']), $a['left']] <=> [round($b['top']), $b['left']]);
                $result[$row][$column] = trim(implode(' ', array_column($runs, 'text')));
            }
        }

        ksort($result, SORT_NUMERIC);

        return $result;
    }

    private function interval(array $edges, float $start, float $end): ?int
    {
        foreach ($edges as $index => $edge) {
            if (isset($edges[$index + 1]) && $start >= $edge && $end <= $edges[$index + 1]) {
                return $index;
            }
        }

        return null;
    }
}
