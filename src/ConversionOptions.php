<?php

declare(strict_types=1);

namespace Csv2Excel;

use Csv2Excel\Detection\DelimiterDetector;
use Csv2Excel\Detection\EncodingDetector;
use InvalidArgumentException;

final readonly class ConversionOptions
{
    public const array FORMATS = ['xlsx', 'ods'];

    /**
     * @param string      $format       "xlsx" or "ods"
     * @param null|string $encoding     null = auto-detect per file
     * @param null|string $delimiter    null = auto-detect per file
     * @param bool        $headerRow    first row is a header (bold, frozen, filter)
     * @param bool        $smartNumbers convert safe numeric values to numbers
     * @param bool        $autoWidth    fit column widths to content (XLSX)
     * @param bool        $autoFilter   add filter buttons on the header row
     */
    public function __construct(
        public string $format = 'xlsx',
        public ?string $encoding = null,
        public ?string $delimiter = null,
        public bool $headerRow = true,
        public bool $smartNumbers = true,
        public bool $autoWidth = true,
        public bool $autoFilter = true,
    ) {
        if (!\in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException("Unsupported output format: {$format}");
        }
        if (null !== $encoding && !EncodingDetector::isSupported($encoding)) {
            throw new InvalidArgumentException("Unsupported encoding: {$encoding}");
        }
        if (null !== $delimiter && !DelimiterDetector::isSupported($delimiter)) {
            throw new InvalidArgumentException('Unsupported delimiter.');
        }
    }

    /**
     * Builds options from loosely typed input (web form, CLI flags).
     * "auto" or "" means auto-detect for encoding and delimiter.
     *
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input, self $defaults = new self()): self
    {
        $pick = static function (string $key, ?string $default) use ($input): ?string {
            if (!\array_key_exists($key, $input)) {
                return $default;
            }
            $value = (string) $input[$key];

            return '' === $value || 'auto' === $value ? null : $value;
        };
        $flag = static fn (string $key, bool $default): bool => \array_key_exists($key, $input)
            ? filter_var($input[$key], FILTER_VALIDATE_BOOLEAN)
            : $default;

        $delimiter = $pick('delimiter', $defaults->delimiter);
        if ('tab' === $delimiter || '\t' === $delimiter) {
            $delimiter = "\t";
        }

        return new self(
            format: strtolower($pick('format', $defaults->format) ?? 'xlsx'),
            encoding: $pick('encoding', $defaults->encoding),
            delimiter: $delimiter,
            headerRow: $flag('header', $defaults->headerRow),
            smartNumbers: $flag('smart_numbers', $defaults->smartNumbers),
            autoWidth: $flag('auto_width', $defaults->autoWidth),
            autoFilter: $flag('auto_filter', $defaults->autoFilter),
        );
    }
}
