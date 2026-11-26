<?php

namespace App\Imports;

class DocumentTablePassages
{
    public function __construct(private ImportFile $importFile) {}

    public function extract(string $filename, string $path): array
    {
        $passages = [];
        foreach ($this->importFile->tableCells($filename, $path) as $table) {
            $rowHeader = $table['rowHeader'] ?? 0;
            $rows = $table['rows'];
            if ($rows === []) {
                continue;
            }
            $headers = array_shift($rows);
            unset($headers[$rowHeader]);
            $headers = array_filter($headers, fn (string $value): bool => mb_strlen($value) <= 160);
            $distinct = array_count_values($headers);
            $headers = array_filter($headers, fn (string $value): bool => $distinct[$value] === 1);
            if (count($headers) < 2) {
                continue;
            }

            foreach ($rows as $row) {
                $label = $row[$rowHeader] ?? null;
                if ($label === null || mb_strlen($label) > 500) {
                    continue;
                }
                foreach (array_intersect_key($row, $headers) as $column => $value) {
                    if (mb_strlen($value) > 1000) {
                        continue;
                    }
                    $passages[] = [
                        'wording' => sprintf('%s | Row: %s | Column: %s | Value: %s', $table['location'], $label, $headers[$column], $value),
                        'document_location' => $table['location'],
                    ];
                    if (count($passages) === 500) {
                        return $passages;
                    }
                }
            }
        }

        return $passages;
    }
}
