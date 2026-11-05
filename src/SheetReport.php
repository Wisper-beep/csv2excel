<?php

declare(strict_types=1);

namespace Csv2Excel;

final readonly class SheetReport
{
    /**
     * @param list<string> $sheetNames more than one when the file exceeded the per-sheet row limit
     * @param int          $rows       data rows (header excluded)
     */
    public function __construct(
        public string $sourceName,
        public array $sheetNames,
        public int $rows,
        public int $columns,
        public string $encoding,
        public bool $encodingGuessed,
        public string $delimiter,
    ) {}
}
