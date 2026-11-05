<?php

declare(strict_types=1);

namespace Csv2Excel\Excel;

/**
 * Produces valid, unique worksheet names (Excel rules: max 31 chars,
 * none of \ / ? * : [ ], no leading/trailing apostrophe, case-insensitive unique).
 */
final class SheetNamer
{
    private const int MAX_LENGTH = 31;

    /** @var array<string, true> */
    private array $used = [];

    public function name(string $fileName): string
    {
        // Not pathinfo(): it is locale-dependent with multibyte names and treats "/" as a directory.
        $base = (string) preg_replace('/\.[^.]*$/', '', $fileName);
        $base = str_replace(['\\', '/', '?', '*', ':', '[', ']'], '_', $base);
        $base = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $base);
        $base = trim($base, " '");
        if ('' === $base) {
            $base = 'Sheet';
        }

        $candidate = mb_substr($base, 0, self::MAX_LENGTH, 'UTF-8');
        for ($n = 2; isset($this->used[mb_strtolower($candidate, 'UTF-8')]); ++$n) {
            $suffix = " ({$n})";
            $candidate = rtrim(mb_substr($base, 0, self::MAX_LENGTH - mb_strlen($suffix), 'UTF-8'), " '").$suffix;
        }

        $this->used[mb_strtolower($candidate, 'UTF-8')] = true;

        return $candidate;
    }
}
