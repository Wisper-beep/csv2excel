<?php

declare(strict_types=1);

namespace Csv2Excel\Excel;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Style\Style;

/**
 * Turns raw CSV values into spreadsheet cells without damaging the data.
 *
 * In "smart" mode only values that survive a round-trip through Excel become
 * numbers. Everything else stays text, so that:
 *  - leading zeros survive (ZIP codes, employee IDs: "00123"),
 *  - long numbers keep every digit (card numbers, IDs > 15 digits),
 *  - phone numbers keep their "+" ("+91 98765 43210"),
 *  - values starting with "=", "+", "-" or "@" are never run as formulas
 *    (blocks CSV/formula injection).
 */
final class CellFactory
{
    /** Excel's hard limit on characters per cell. */
    public const int MAX_CELL_LENGTH = 32767;

    /** Excel keeps 15 significant digits; more get silently rounded. */
    private const int MAX_NUMERIC_DIGITS = 15;

    public int $truncatedCells = 0;

    public function __construct(private readonly bool $smartNumbers = true) {}

    public function make(string $value, ?Style $style = null, bool $forceText = false): Cell
    {
        if ('' === $value) {
            return new EmptyCell(null, $style);
        }

        if ($this->smartNumbers && !$forceText) {
            $number = self::toNumber($value);
            if (null !== $number) {
                return new NumericCell($number, $style);
            }
        }

        return $this->text($value, $style);
    }

    /** Always a text cell (used for header rows). */
    public function text(string $value, ?Style $style = null): Cell
    {
        if ('' === $value) {
            return new EmptyCell(null, $style);
        }
        if (\strlen($value) > self::MAX_CELL_LENGTH && mb_strlen($value, 'UTF-8') > self::MAX_CELL_LENGTH) {
            $value = mb_substr($value, 0, self::MAX_CELL_LENGTH, 'UTF-8');
            ++$this->truncatedCells;
        }

        // StringCell is built explicitly: Cell::fromValue() would turn "=..." into a formula.
        return new StringCell($value, $style);
    }

    public static function toNumber(string $value): float|int|null
    {
        if (1 !== preg_match('/^-?(0|[1-9]\d*)(\.\d+)?$/', $value, $m)) {
            return null;
        }

        $digits = \strlen($m[1]) + (isset($m[2]) ? \strlen($m[2]) - 1 : 0);
        if ($digits > self::MAX_NUMERIC_DIGITS) {
            return null;
        }

        return isset($m[2]) ? (float) $value : (int) $value;
    }
}
