<?php

declare(strict_types=1);

namespace Csv2Excel\Tests;

use Csv2Excel\Csv\TextChunker;
use PHPUnit\Framework\TestCase;

final class TextChunkerTest extends TestCase
{
    public function testSplitsAfterLastNewline(): void
    {
        self::assertSame(["a\nb\n", 'c'], TextChunker::splitAtLastNewline("a\nb\nc", 'UTF-8'));
    }

    public function testKeepsEverythingWhenThereIsNoNewline(): void
    {
        self::assertSame(['', 'abc'], TextChunker::splitAtLastNewline('abc', 'GB18030'));
    }

    public function testUtf16NewlineMustBeAligned(): void
    {
        // U+0A30 U+0100 is "30 0A 00 01" in LE: it contains the bytes "0A 00" at an odd offset.
        $line = mb_convert_encoding("a\n", 'UTF-16LE', 'UTF-8');
        $tail = mb_convert_encoding("\u{0A30}\u{0100}", 'UTF-16LE', 'UTF-8');
        [$complete, $rest] = TextChunker::splitAtLastNewline($line.$tail, 'UTF-16LE');

        self::assertSame($line, $complete);
        self::assertSame($tail, $rest);
    }
}
