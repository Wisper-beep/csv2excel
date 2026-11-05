<?php

declare(strict_types=1);

namespace Csv2Excel\Detection;

final readonly class DetectedEncoding
{
    /**
     * @param string $encoding  mbstring encoding name
     * @param int    $bomLength number of BOM bytes to skip at the start of the file
     * @param bool   $confident false when the result is a heuristic guess
     */
    public function __construct(
        public string $encoding,
        public int $bomLength,
        public bool $confident,
    ) {}
}
