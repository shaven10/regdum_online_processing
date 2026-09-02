<?php

/**
 * Lightweight .xlsx reader (ZipArchive + SimpleXML). No Composer dependency.
 */

function readSpreadsheetRows(string $path, string $extension = ''): array {
    $extension = strtolower($extension !== '' ? $extension : pathinfo($path, PATHINFO_EXTENSION));

    if ($extension === 'csv') {
        return readCsvRows($path);
    }

    if ($extension === 'xlsx') {
        return readXlsxRows($path);
    }

    throw new InvalidArgumentException('Please upload an Excel workbook (.xlsx) or CSV file.');
}

function readCsvRows(string $path): array {
    $handle = fopen($path, 'rb');
    if (!$handle) {
        throw new RuntimeException('Unable to read the uploaded file.');
    }

    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = array_map(static fn($cell) => trim((string) $cell), $row);
    }
    fclose($handle);

    return $rows;
}

function readXlsxRows(string $path): array {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZipArchive is required to read Excel files.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Unable to open the Excel workbook.');
    }

    try {
        $sharedStrings = parseXlsxSharedStrings($zip->getFromName('xl/sharedStrings.xml') ?: '');
        $dateStyles = parseXlsxDateStyles($zip->getFromName('xl/styles.xml') ?: '');
        $sheetPath = findXlsxFirstSheetPath($zip);
        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false || $sheetXml === '') {
            throw new RuntimeException('The Excel workbook has no readable worksheet.');
        }

        return parseXlsxSheetRows($sheetXml, $sharedStrings, $dateStyles);
    } finally {
        $zip->close();
    }
}

function findXlsxFirstSheetPath(ZipArchive $zip): string {
    $workbook = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbook && $rels) {
        $relMap = [];
        $relsXml = simplexml_load_string($rels, 'SimpleXMLElement', LIBXML_NONET);
        if ($relsXml) {
            foreach ($relsXml->Relationship as $rel) {
                $id = (string) $rel['Id'];
                $target = (string) $rel['Target'];
                if ($id === '' || $target === '') {
                    continue;
                }
                $relMap[$id] = 'xl/' . ltrim(str_replace('\\', '/', $target), '/');
                $relMap[$id] = preg_replace('#^xl/xl/#', 'xl/', $relMap[$id]);
            }
        }

        $workbookXml = simplexml_load_string($workbook, 'SimpleXMLElement', LIBXML_NONET);
        if ($workbookXml) {
            $workbookXml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $workbookXml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $sheets = $workbookXml->xpath('//m:sheets/m:sheet') ?: [];
            foreach ($sheets as $sheet) {
                $rid = '';
                $relAttrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                if ($relAttrs && isset($relAttrs['id'])) {
                    $rid = (string) $relAttrs['id'];
                }
                if ($rid === '') {
                    $rid = (string) ($sheet['id'] ?? '');
                }
                if ($rid !== '' && isset($relMap[$rid]) && $zip->locateName($relMap[$rid]) !== false) {
                    return $relMap[$rid];
                }
            }
        }
    }

    foreach (['xl/worksheets/sheet1.xml', 'xl/worksheets/sheet.xml'] as $fallback) {
        if ($zip->locateName($fallback) !== false) {
            return $fallback;
        }
    }

    throw new RuntimeException('Unable to locate a worksheet in the Excel workbook.');
}

function parseXlsxSharedStrings(string $xml): array {
    if (trim($xml) === '') {
        return [];
    }

    $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    if (!$doc) {
        return [];
    }

    $doc->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $items = $doc->xpath('//m:si') ?: [];
    $strings = [];

    foreach ($items as $si) {
        $text = '';
        $si->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $tNodes = $si->xpath('.//m:t') ?: [];
        foreach ($tNodes as $t) {
            $text .= (string) $t;
        }
        $strings[] = $text;
    }

    return $strings;
}

function parseXlsxDateStyles(string $xml): array {
    $dateStyles = [];
    if (trim($xml) === '') {
        return $dateStyles;
    }

    $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    if (!$doc) {
        return $dateStyles;
    }

    $customFormats = [];
    $doc->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    foreach ($doc->xpath('//m:numFmts/m:numFmt') ?: [] as $fmt) {
        $customFormats[(int) $fmt['numFmtId']] = (string) $fmt['formatCode'];
    }

    $index = 0;
    foreach ($doc->xpath('//m:cellXfs/m:xf') ?: [] as $xf) {
        $numFmtId = (int) ($xf['numFmtId'] ?? 0);
        $formatCode = $customFormats[$numFmtId] ?? '';
        $dateStyles[$index] = isExcelDateNumberFormat($numFmtId, $formatCode);
        $index++;
    }

    return $dateStyles;
}

function isExcelDateNumberFormat(int $numFmtId, string $formatCode): bool {
    $builtInDates = [
        14, 15, 16, 17, 22,
        27, 28, 29, 30, 31, 32, 33, 34, 35, 36,
        45, 46, 47,
        50, 51, 52, 53, 54, 55, 56, 57, 58,
    ];
    if (in_array($numFmtId, $builtInDates, true)) {
        return true;
    }

    $code = strtolower($formatCode);
    if ($code === '') {
        return false;
    }
    if (str_contains($code, 'red') || str_contains($code, '[')) {
        return false;
    }

    return (str_contains($code, 'd') || str_contains($code, 'm') || str_contains($code, 'y'))
        && !str_contains($code, 'h')
        && !str_contains($code, 's');
}

function parseXlsxSheetRows(string $xml, array $sharedStrings, array $dateStyles): array {
    $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    if (!$doc) {
        throw new RuntimeException('The Excel worksheet could not be parsed.');
    }

    $doc->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rowNodes = $doc->xpath('//m:sheetData/m:row') ?: [];
    $rows = [];

    foreach ($rowNodes as $rowNode) {
        $rowIndex = max(1, (int) ($rowNode['r'] ?? (count($rows) + 1))) - 1;
        $cells = [];
        $rowNode->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $cellNodes = $rowNode->xpath('./m:c') ?: [];

        foreach ($cellNodes as $cell) {
            $ref = (string) ($cell['r'] ?? '');
            $colIndex = $ref !== '' ? xlsxColumnIndexFromRef($ref) : count($cells);
            $cells[$colIndex] = extractXlsxCellValue($cell, $sharedStrings, $dateStyles);
        }

        if ($cells === []) {
            $rows[$rowIndex] = [];
            continue;
        }

        $width = max(array_keys($cells)) + 1;
        $row = array_fill(0, $width, '');
        foreach ($cells as $index => $value) {
            $row[$index] = $value;
        }
        $rows[$rowIndex] = $row;
    }

    if ($rows === []) {
        return [];
    }

    $maxIndex = max(array_keys($rows));
    $normalized = [];
    for ($i = 0; $i <= $maxIndex; $i++) {
        $normalized[] = $rows[$i] ?? [];
    }

    return $normalized;
}

function xlsxColumnIndexFromRef(string $ref): int {
    $letters = strtoupper(preg_replace('/[^A-Z]/', '', $ref) ?? '');
    $index = 0;
    $length = strlen($letters);
    for ($i = 0; $i < $length; $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }
    return max(0, $index - 1);
}

function extractXlsxCellValue(SimpleXMLElement $cell, array $sharedStrings, array $dateStyles): string {
    $type = (string) ($cell['t'] ?? '');
    $style = $cell['s'] !== null && (string) $cell['s'] !== '' ? (int) $cell['s'] : null;
    $raw = xlsxCellValueText($cell);

    if ($type === 's') {
        $index = (int) $raw;
        return trim((string) ($sharedStrings[$index] ?? ''));
    }

    if ($type === 'inlineStr') {
        return xlsxInlineStringText($cell);
    }

    if ($type === 'b') {
        return $raw === '1' ? '1' : '0';
    }

    if ($raw === '') {
        $inline = xlsxInlineStringText($cell);
        if ($inline !== '') {
            return $inline;
        }
        return '';
    }

    if ($style !== null && !empty($dateStyles[$style]) && is_numeric($raw) && $type !== 'str') {
        $date = excelSerialToDateString((float) $raw);
        if ($date !== null) {
            return $date;
        }
    }

    return normalizeExcelNumericCell($raw);
}

function xlsxCellValueText(SimpleXMLElement $cell): string {
    $nodes = $cell->xpath('./*[local-name()="v"]');
    if (!$nodes) {
        return '';
    }
    return trim((string) $nodes[0]);
}

function xlsxInlineStringText(SimpleXMLElement $cell): string {
    $text = '';
    foreach ($cell->xpath('.//*[local-name()="t"]') ?: [] as $t) {
        $text .= (string) $t;
    }
    return trim($text);
}

function excelSerialToDateString(float $serial): ?string {
    if ($serial <= 0 || $serial > 2958465) {
        return null;
    }

    $unix = (int) round(($serial - 25569) * 86400);
    if ($unix < 0) {
        return null;
    }

    return gmdate('Y-m-d', $unix);
}

function normalizeExcelNumericCell(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^[+-]?\d+\.0+$/', $value)) {
        return preg_replace('/\.0+$/', '', $value) ?? $value;
    }

    if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)[eE][+-]?\d+$/', $value)) {
        return scientificNotationToDecimalString($value);
    }

    return $value;
}

function scientificNotationToDecimalString(string $value): string {
    if (!preg_match('/^([+-]?)(\d+\.?\d*|\.\d+)[eE]([+-]?\d+)$/', trim($value), $match)) {
        return trim($value);
    }

    $sign = $match[1] === '-' ? '-' : '';
    $mantissa = $match[2];
    $exponent = (int) $match[3];
    $decPos = strpos($mantissa, '.');
    $fracLen = $decPos === false ? 0 : strlen($mantissa) - $decPos - 1;
    $digits = str_replace('.', '', $mantissa);
    $digits = ltrim($digits, '0');
    if ($digits === '') {
        return '0';
    }

    $shift = $exponent - $fracLen;
    if ($shift >= 0) {
        return $sign . $digits . str_repeat('0', $shift);
    }

    $pad = -$shift;
    if ($pad >= strlen($digits)) {
        $fraction = str_pad($digits, $pad, '0', STR_PAD_LEFT);
        $decimal = rtrim($fraction, '0');
        return $decimal === '' ? '0' : $sign . '0.' . $fraction;
    }

    $intPart = substr($digits, 0, strlen($digits) - $pad);
    $fracPart = rtrim(substr($digits, strlen($digits) - $pad), '0');
    return $fracPart === '' ? $sign . $intPart : $sign . $intPart . '.' . $fracPart;
}

function downloadXlsxFile(string $filename, string $binary): void {
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($binary));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $binary;
    exit;
}

function buildSimpleXlsxWorkbook(string $sheetName, array $rows): string {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZipArchive is required to generate Excel files.');
    }

    $sheetName = preg_replace('/[\\/:*?\[\]]/', '', $sheetName) ?: 'Sheet1';
    $sheetXml = buildSimpleXlsxSheetXml($rows);

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    if ($tmp === false) {
        throw new RuntimeException('Unable to create a temporary Excel file.');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Unable to write the Excel template.');
    }

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="' . htmlspecialchars($sheetName, ENT_XML1) . '" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    $binary = (string) file_get_contents($tmp);
    @unlink($tmp);
    return $binary;
}

function buildSimpleXlsxSheetXml(array $rows): string {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

    foreach ($rows as $r => $row) {
        $xml .= '<row r="' . ($r + 1) . '">';
        foreach ($row as $c => $value) {
            $ref = xlsxColumnLetter($c) . ($r + 1);
            $text = htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
            $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
        }
        $xml .= '</row>';
    }

    return $xml . '</sheetData></worksheet>';
}

function xlsxColumnLetter(int $index): string {
    $letter = '';
    $n = $index + 1;
    while ($n > 0) {
        $n--;
        $letter = chr(65 + ($n % 26)) . $letter;
        $n = intdiv($n, 26);
    }
    return $letter;
}
