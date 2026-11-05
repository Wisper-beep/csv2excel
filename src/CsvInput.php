<?php

declare(strict_types=1);

namespace Csv2Excel;

final readonly class CsvInput
{
    /**
     * @param string $path        file on disk
     * @param string $displayName original file name (used for the sheet name)
     */
    public function __construct(
        public string $path,
        public string $displayName,
    ) {}

    public static function fromPath(string $path): self
    {
        return new self($path, basename($path));
    }
}
