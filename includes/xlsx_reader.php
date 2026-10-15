<?php
/**
 * Minimal, dependency-free .xlsx reader (uses only PHP's built-in
 * ZipArchive + SimpleXML extensions — both bundled with PHP, no
 * Composer/PhpSpreadsheet needed). Reads the first sheet's plain cell
 * values only — no formulas, styles, or multiple sheets — which is all
 * a student import spreadsheet needs. Handles files from this app's own
 * export AND typical files saved directly from Excel/LibreOffice/Google
 * Sheets (shared strings, inline strings, and numeric cells).
 */

/** True when this PHP install can read .xlsx files at all (needs the zip and
 *  SimpleXML extensions). When false, the import page tells the admin to enable
 *  them or upload a .csv instead of wrongly calling the file "corrupted". */
function xlsx_support_available(): bool
{
    return !xlsx_missing_extensions();
}

/** Names of the PHP extensions .xlsx reading needs that this server lacks
 *  (empty list = all good), so the error can say exactly what to switch on. */
function xlsx_missing_extensions(): array
{
    $missing = [];
    if (!class_exists('ZipArchive')) $missing[] = 'zip';
    if (!function_exists('simplexml_load_string')) $missing[] = 'simplexml (php-xml)';
    return $missing;
}

/** Reads the first worksheet of an uploaded .xlsx file into a plain array
 *  of rows, each row itself an array of string cell values indexed by
 *  column number (0-based, gaps for blank cells filled with ''). Returns
 *  null if the file isn't a readable .xlsx (e.g. corrupt upload, or it's
 *  actually a .csv/.xls someone renamed). */
function read_xlsx_first_sheet(string $path): ?array
{
    if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) {
        return null;
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return null;
    }

    $sharedStrings = xlsx_read_shared_strings($zip);

    $sheetPath = xlsx_find_first_sheet_path($zip);
    $sheetXml = $sheetPath ? $zip->getFromName($sheetPath) : false;
    $zip->close();

    if ($sheetXml === false) {
        return null;
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($sheetXml);
    if ($xml === false) {
        return null;
    }
    $xml->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $rows = [];
    foreach ($xml->xpath('//s:sheetData/s:row') as $rowEl) {
        $row = [];
        foreach ($rowEl->c as $cellEl) {
            $ref = (string)$cellEl['r']; // e.g. "C7"
            $colIndex = $ref !== '' ? xlsx_column_index_from_ref($ref) : count($row);
            $row[$colIndex] = xlsx_cell_value($cellEl, $sharedStrings);
        }
        if (!$row) {
            continue;
        }
        $maxIndex = max(array_keys($row));
        $ordered = [];
        for ($i = 0; $i <= $maxIndex; $i++) {
            $ordered[] = $row[$i] ?? '';
        }
        $rows[] = $ordered;
    }
    return $rows;
}

/** Same output shape as parse_csv_upload(): a list of associative rows
 *  keyed by the header found in the sheet's first row. */
function xlsx_rows_to_assoc(array $rawRows): array
{
    if (!$rawRows) {
        return [];
    }
    $header = array_map('trim', $rawRows[0]);
    $out = [];
    for ($i = 1; $i < count($rawRows); $i++) {
        $row = [];
        foreach ($header as $colIndex => $colName) {
            if ($colName === '') continue;
            $row[$colName] = trim((string)($rawRows[$i][$colIndex] ?? ''));
        }
        // Skip fully-blank trailing rows.
        if (implode('', $row) === '') continue;
        $out[] = $row;
    }
    return $out;
}

function xlsx_read_shared_strings(ZipArchive $zip): array
{
    $xmlStr = $zip->getFromName('xl/sharedStrings.xml');
    if ($xmlStr === false) {
        return [];
    }
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlStr);
    if ($xml === false) {
        return [];
    }
    $xml->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $strings = [];
    foreach ($xml->xpath('//s:si') as $si) {
        // A shared string can be a single <t> or multiple <r><t> "runs" (rich text).
        if (isset($si->t)) {
            $strings[] = (string)$si->t;
        } else {
            $text = '';
            foreach ($si->r as $run) {
                $text .= (string)$run->t;
            }
            $strings[] = $text;
        }
    }
    return $strings;
}

/** Finds the first worksheet's zip path via workbook.xml.rels, falling
 *  back to the conventional "xl/worksheets/sheet1.xml" if anything about
 *  that lookup fails (still covers every normal .xlsx file). */
function xlsx_find_first_sheet_path(ZipArchive $zip): ?string
{
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml !== false && $relsXml !== false) {
        libxml_use_internal_errors(true);
        $workbook = simplexml_load_string($workbookXml);
        $rels = simplexml_load_string($relsXml);
        if ($workbook !== false && $rels !== false) {
            $workbook->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $sheets = $workbook->xpath('//s:sheets/s:sheet');
            if ($sheets) {
                $rId = (string)$sheets[0]->attributes('r', true)['id'];
                foreach ($rels->Relationship as $rel) {
                    if ((string)$rel['Id'] === $rId) {
                        $target = ltrim((string)$rel['Target'], '/');
                        return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                    }
                }
            }
        }
    }
    return $zip->locateName('xl/worksheets/sheet1.xml') !== false ? 'xl/worksheets/sheet1.xml' : null;
}

function xlsx_column_index_from_ref(string $ref): int
{
    preg_match('/^([A-Z]+)/', $ref, $m);
    $letters = $m[1] ?? 'A';
    $index = 0;
    foreach (str_split($letters) as $ch) {
        $index = $index * 26 + (ord($ch) - 64);
    }
    return $index - 1;
}

function xlsx_cell_value(SimpleXMLElement $cellEl, array $sharedStrings): string
{
    $type = (string)$cellEl['t'];
    if ($type === 'inlineStr') {
        // Plain <is><t>, or rich text split over several <r><t> runs.
        $text = '';
        if (isset($cellEl->is)) {
            foreach ($cellEl->is->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                $text .= (string)$t;
            }
        }
        return $text;
    }
    if ($type === 's') {
        $idx = (int)$cellEl->v;
        return $sharedStrings[$idx] ?? '';
    }
    if ($type === 'e') {
        return ''; // an Excel error cell (#N/A, #REF!...) carries no usable value
    }
    $raw = isset($cellEl->v) ? trim((string)$cellEl->v) : '';
    if ($type === '' || $type === 'n') {
        return xlsx_format_number($raw);
    }
    // 'str' (formula result string), 'b' (boolean), 'd' (ISO date) — raw text is what we want.
    return $raw;
}

/** Excel stores every number as a float and some writers emit scientific
 *  notation (2.00266733806E+11) or float noise (85.400000000000006). Turn
 *  those back into the plain digits a person typed, so a National ID or an
 *  average survives the trip. Non-numbers are returned untouched. */
function xlsx_format_number(string $raw): string
{
    if ($raw === '' || !is_numeric($raw)) {
        return $raw;
    }
    if (preg_match('/^-?\d{1,15}$/', $raw)) {
        return $raw; // already a plain integer — keep it exactly
    }
    $f = (float)$raw;
    if ($f == floor($f) && abs($f) < 1e15) {
        return sprintf('%.0f', $f);
    }
    if (abs($f) >= 1e15) {
        return $raw; // beyond what a spreadsheet stores exactly anyway
    }
    return rtrim(rtrim(sprintf('%.10F', $f), '0'), '.');
}
