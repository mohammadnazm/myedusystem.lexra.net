<?php
/**
 * Minimal, dependency-free .xlsx writer (uses only PHP's built-in ZipArchive
 * extension — no Composer/PhpSpreadsheet needed, matching this project's
 * "no build step, run on any PHP host" design). Handles exactly what this
 * app needs: one sheet of plain text/number cells with a header row. No
 * styling, formulas, or multiple sheets — deliberately kept simple so
 * there's very little that can go wrong on a live server.
 */

/**
 * Writes $rows (a list of associative arrays) as a real .xlsx file and
 * streams it as a download, then ends the request. $columns controls the
 * column order and header labels; only those keys are read from each row.
 */
function send_xlsx_download(string $filename, array $columns, array $rows, array $options = []): void
{
    if (!class_exists('ZipArchive')) {
        // Extremely rare on real hosting (zip is bundled/enabled by default
        // almost everywhere), but fail safe with a CSV instead of a 500.
        send_csv_download(preg_replace('/\.xlsx$/', '.csv', $filename), $columns, $rows);
        return;
    }

    $tmpPath = build_xlsx_file($columns, $rows, $options);

    // Anything printed earlier (a stray blank line in an include, a PHP
    // notice) would be glued to the front of the file and corrupt it.
    while (ob_get_level() > 0) { ob_end_clean(); }
    @ini_set('zlib.output_compression', '0');

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Content-Length: ' . filesize($tmpPath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($tmpPath);
    @unlink($tmpPath);
    exit;
}

/** Builds a real .xlsx file on disk (in the system temp dir) and returns its
 *  path — the pure, non-exiting half of send_xlsx_download(), split out so
 *  it can be unit-tested and reused without needing an HTTP response. The
 *  caller is responsible for deleting the returned file when done with it. */
function build_xlsx_file(array $columns, array $rows, array $options = []): string
{
    $tmpPath = tempnam(sys_get_temp_dir(), 'xlsx_');
    $zip = new ZipArchive();
    $zip->open($tmpPath, ZipArchive::OVERWRITE);

    $zip->addEmptyDir('_rels');
    $zip->addEmptyDir('xl');
    $zip->addEmptyDir('xl/_rels');
    $zip->addEmptyDir('xl/worksheets');

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
        '</Types>'
    );

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>'
    );

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
        '</Relationships>'
    );

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets><sheet name="Students" sheetId="1" r:id="rId1"/></sheets>' .
        '</workbook>'
    );

    // Styles: 0 = default, 1 = bold header on a light fill, 2 = Text format (@).
    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>' .
        '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>' .
        '<fill><patternFill patternType="solid"><fgColor rgb="FFE8EAF6"/><bgColor indexed="64"/></patternFill></fill></fills>' .
        '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' .
        '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
        '<cellXfs count="3">' .
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' .
        '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>' .
        '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>' .
        '</cellXfs>' .
        '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' .
        '</styleSheet>'
    );

    $zip->addFromString('xl/worksheets/sheet1.xml', xlsx_build_sheet_xml($columns, $rows, $options));

    $zip->close();
    return $tmpPath;
}

/** Builds the worksheet XML: frozen bold header row, sensible column widths,
 *  optional Text-formatted columns, then the data. Text is written as inline
 *  strings (no shared-strings table needed); whole numbers and decimals PHP
 *  gives us as int/float are written as real numeric cells. */
function xlsx_build_sheet_xml(array $columns, array $rows, array $options = []): string
{
    $textCols = array_flip($options['text_columns'] ?? []);

    // Column widths from the longest value in each column (capped), so nothing opens squashed.
    $widths = [];
    foreach ($columns as $i => $col) {
        $max = mb_strlen((string)$col) + 2;
        foreach ($rows as $row) {
            $max = max($max, min(mb_strlen((string)($row[$col] ?? '')), 45) + 2);
        }
        $widths[$i] = max(9, min($max, 47));
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
          . '<selection pane="bottomLeft"/></sheetView></sheetViews>';
    $xml .= '<cols>';
    foreach ($columns as $i => $col) {
        $n = $i + 1;
        $style = isset($textCols[$col]) ? ' style="2"' : '';
        $xml .= '<col min="' . $n . '" max="' . $n . '" width="' . $widths[$i] . '"' . $style . ' customWidth="1"/>';
    }
    $xml .= '</cols><sheetData>';

    // Header row (style 1 = bold).
    $xml .= '<row r="1">';
    foreach ($columns as $i => $col) {
        $xml .= xlsx_build_cell_xml(xlsx_column_letter($i) . '1', (string)$col, 1);
    }
    $xml .= '</row>';

    $rowNum = 2;
    foreach ($rows as $row) {
        $xml .= '<row r="' . $rowNum . '">';
        foreach ($columns as $i => $col) {
            $value = $row[$col] ?? '';
            $style = isset($textCols[$col]) ? 2 : 0;
            $xml .= xlsx_build_cell_xml(xlsx_column_letter($i) . $rowNum, $value, $style);
        }
        $xml .= '</row>';
        $rowNum++;
    }

    $xml .= '</sheetData></worksheet>';
    return $xml;
}

/** One cell. ints/floats -> numeric cell; everything else -> inline text
 *  (after stripping characters XML can't hold, which would make Excel
 *  report the whole file as corrupt). Empty text cells keep only their style. */
function xlsx_build_cell_xml(string $ref, $value, int $style = 0): string
{
    $s = $style ? ' s="' . $style . '"' : '';
    if (is_int($value) || (is_float($value) && is_finite($value))) {
        return '<c r="' . $ref . '"' . $s . '><v>' . $value . '</v></c>';
    }
    $text = (string)$value;
    if ($text === '') {
        return '<c r="' . $ref . '"' . $s . '/>';
    }
    $text = mb_scrub($text, 'UTF-8');
    $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
    $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    return '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . $escaped . '</t></is></c>';
}

/** 0-indexed column number -> spreadsheet column letters (0 => A, 25 => Z, 26 => AA...). */
function xlsx_column_letter(int $index): string
{
    $letters = '';
    $index++;
    while ($index > 0) {
        $rem = ($index - 1) % 26;
        $letters = chr(65 + $rem) . $letters;
        $index = intdiv($index - 1, 26);
    }
    return $letters;
}
