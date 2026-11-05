<?php

declare(strict_types=1);

namespace Csv2Excel\Detection;

/**
 * Picks the field delimiter whose parse gives the most consistent column count
 * (quote-aware, so delimiters inside quoted fields do not confuse it).
 */
final class DelimiterDetector
{
    /** value => label */
    public const array SUPPORTED = [
        ',' => 'Comma ( , )',
        ';' => 'Semicolon ( ; )',
        "\t" => 'Tab',
        '|' => 'Pipe ( | )',
    ];

    private const int MAX_RECORDS = 50;

    /**
     * @param string $utf8Sample  decoded sample (UTF-8)
     * @param bool   $isTruncated true if the sample may end in the middle of a record
     */
    public function detect(string $utf8Sample, bool $isTruncated = true): string
    {
        $best = ',';
        $bestScore = -1.0;

        foreach (array_keys(self::SUPPORTED) as $delimiter) {
            $counts = $this->fieldCounts($utf8Sample, $delimiter, $isTruncated);
            if ([] === $counts) {
                continue;
            }

            $frequency = array_count_values($counts);
            arsort($frequency);
            $mode = (int) array_key_first($frequency);
            if ($mode < 2) {
                continue;
            }

            $consistency = $frequency[$mode] / \count($counts);
            // Consistency matters most; more columns breaks ties.
            $score = $consistency * 1000 + min($mode, 999);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $delimiter;
            }
        }

        return $best;
    }

    public static function isSupported(string $delimiter): bool
    {
        return \array_key_exists($delimiter, self::SUPPORTED);
    }

    /** @return list<int> */
    private function fieldCounts(string $sample, string $delimiter, bool $isTruncated): array
    {
        $stream = fopen('php://memory', 'r+');
        \assert(false !== $stream);
        fwrite($stream, $sample);
        rewind($stream);

        $counts = [];
        while (\count($counts) <= self::MAX_RECORDS && false !== ($row = fgetcsv($stream, null, $delimiter, '"', ''))) {
            if ([null] === $row) {
                continue;
            }
            $counts[] = \count($row);
        }
        fclose($stream);

        // The last record of a truncated sample is likely incomplete.
        if ($isTruncated && \count($counts) > 1) {
            array_pop($counts);
        }

        return $counts;
    }
}
