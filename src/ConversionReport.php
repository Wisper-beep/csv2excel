<?php

declare(strict_types=1);

namespace Csv2Excel;

final readonly class ConversionReport
{
    /**
     * @param list<SheetReport> $sheets
     */
    public function __construct(
        public array $sheets,
        public int $truncatedCells,
        public int $durationMs,
    ) {}

    public function totalRows(): int
    {
        return array_sum(array_map(static fn (SheetReport $s) => $s->rows, $this->sheets));
    }
}
