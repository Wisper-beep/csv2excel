# Problem files

These are classic CSV files that **break when you double-click them in Excel**. Each one is converted correctly by CSV2Excel. `tests/ProblemFilesTest.php` checks this on every push, and the CI run uploads the converted workbook as a downloadable artifact.

Regenerate the files with `php scripts/generate-problem-files.php`. Add `--big` to also create a 200,000-row file for performance tests.

| File | What goes wrong in Excel (double-click) | CSV2Excel result |
|---|---|---|
| `01-leading-zeros.csv` | `02134` → `2134`, `000742` → `742`, `007` → `7` | Kept exactly. The whole column stays text. |
| `02-long-numbers.csv` | `4111111111111111` → `4.11111E+15`, and the stored value becomes `4111111111111110` | Every digit kept |
| `03-phone-numbers.csv` | `919876543210` → `9.19877E+11`, `0612345678` → `612345678` | Kept as written, including the `+` prefix |
| `04-european-excel-export.csv` | Windows-1252 + `;`: everything lands in one column, with `MÃ¼ller` instead of `Müller` | Encoding and delimiter detected, `1.234,56` kept |
| `05-chinese-gbk.csv` | GBK text opens as `ÕÅÎ°` instead of `张伟`, and `00001` → `1` | Encoding detected as GB18030/GBK |
| `06-excel-unicode-text.txt` | UTF-16 "Unicode Text" (tab-separated) is unreadable for most tools | Japanese, Arabic, Greek, Hindi and emoji come through |
| `07-multiline-and-quotes.csv` | Quotes, commas and line breaks inside fields shift columns or split rows | Fields stay intact, line breaks kept inside the cell |
| `08-formula-injection.csv` | `=HYPERLINK(...)`, `=cmd\|...`, `+SUM(...)` run as formulas | Stored as plain text, never as formulas |
| `09-auto-date-conversion.csv` | `MARCH1` → `1-Mar`, `1-2` → `2-Jan`, `3/4` → `4-Mar`, `1.10` → `1.1`, `1E5` → `1.00E+05` | Kept exactly as written |

Try them yourself:

```bash
php bin/csv2excel examples/problem-files/0* -o problem-files.xlsx
```
