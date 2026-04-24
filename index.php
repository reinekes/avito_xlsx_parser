<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const STORAGE_DIR = __DIR__ . '/storage';
const IMPORTS_DIR = STORAGE_DIR . '/imports';
const MAX_UPLOAD_BYTES = 25 * 1024 * 1024;

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function boolText(bool $value): string
{
    return $value ? 'yes' : 'no';
}

function ensureDirectory(string $path): bool
{
    if (is_dir($path)) {
        return true;
    }

    return mkdir($path, 0775, true);
}

function normalizeSpaces(string $value): string
{
    return trim(str_replace(["\xc2\xa0", "\xe2\x80\xaf"], ' ', $value));
}

function normalizeHeader(string $value): string
{
    return mb_strtolower(normalizeSpaces($value), 'UTF-8');
}

function parseDateRangeFromFilename(string $filename): array
{
    if (preg_match('/с_(\d{4}-\d{2}-\d{2})_по_(\d{4}-\d{2}-\d{2})/u', $filename, $matches)) {
        return [
            'from' => $matches[1],
            'to' => $matches[2],
        ];
    }

    if (preg_match('/(\d{4}-\d{2}-\d{2})__(\d{4}-\d{2}-\d{2})/u', $filename, $matches)) {
        return [
            'from' => $matches[1],
            'to' => $matches[2],
        ];
    }

    return [
        'from' => '',
        'to' => '',
    ];
}

function parseNumericValue($value): float
{
    if ($value === null) {
        return 0.0;
    }

    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }

    $text = normalizeSpaces((string) $value);
    if ($text === '') {
        return 0.0;
    }

    $text = str_replace(['₽', 'р.', '%'], '', $text);
    $text = str_replace(' ', '', $text);
    $text = str_replace(',', '.', $text);
    $text = preg_replace('/[^0-9.\-]/', '', $text);

    return is_numeric($text) ? (float) $text : 0.0;
}

function extractFormulaDisplayText(string $formula): string
{
    $formula = trim($formula);
    if ($formula === '') {
        return '';
    }

    if (preg_match_all('/"((?:[^"]|"")*)"/u', $formula, $matches) && !empty($matches[1])) {
        $last = end($matches[1]);
        if ($last !== false) {
            return str_replace('""', '"', $last);
        }
    }

    return '';
}

function domNodeTextContent(?DOMNode $node): string
{
    return $node ? normalizeSpaces($node->textContent ?? '') : '';
}

function domChildTextByLocalName(DOMNode $node, string $localName): string
{
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement && $child->localName === $localName) {
            return domNodeTextContent($child);
        }
    }

    return '';
}

function xlsxColumnToIndex(string $reference): int
{
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($reference));
    $index = 0;

    for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }

    return max(0, $index - 1);
}

function xlsxSharedStrings(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) {
        return [];
    }

    if (class_exists('DOMDocument')) {
        $dom = new DOMDocument();
        if (@$dom->loadXML($xml)) {
            $strings = [];
            $items = $dom->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'si');

            foreach ($items as $item) {
                $text = '';
                foreach ($item->childNodes as $child) {
                    if (!($child instanceof DOMElement)) {
                        continue;
                    }

                    if ($child->localName === 't') {
                        $text .= domNodeTextContent($child);
                        continue;
                    }

                    foreach ($child->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 't') as $part) {
                        $text .= domNodeTextContent($part);
                    }
                }

                $strings[] = $text;
            }

            if ($strings !== []) {
                return $strings;
            }
        }
    }

    $root = @simplexml_load_string($xml);
    if ($root === false) {
        return [];
    }

    $root->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $strings = [];

    foreach ($root->xpath('//a:si') ?: [] as $item) {
        $parts = $item->xpath('.//a:t') ?: [];
        $text = '';

        foreach ($parts as $part) {
            $text .= (string) $part;
        }

        $strings[] = $text;
    }

    return $strings;
}

function parseXlsxSheetDom(string $sheetXml, array $sharedStrings): array
{
    if (!class_exists('DOMDocument')) {
        return [];
    }

    $dom = new DOMDocument();
    if (!@$dom->loadXML($sheetXml)) {
        return [];
    }

    $rows = [];
    $rowNodes = $dom->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'row');

    foreach ($rowNodes as $rowNode) {
        $rowValues = [];

        foreach ($rowNode->childNodes as $cell) {
            if (!($cell instanceof DOMElement) || $cell->localName !== 'c') {
                continue;
            }

            $cellRef = $cell->getAttribute('r');
            $index = xlsxColumnToIndex($cellRef);
            $type = $cell->getAttribute('t');
            $value = '';

            if ($type === 's') {
                $sharedIndex = (int) domChildTextByLocalName($cell, 'v');
                $value = $sharedStrings[$sharedIndex] ?? '';
            } elseif ($type === 'str') {
                $value = domChildTextByLocalName($cell, 'v');
                if ($value === '') {
                    $value = extractFormulaDisplayText(domChildTextByLocalName($cell, 'f'));
                }
            } elseif ($type === 'inlineStr') {
                foreach ($cell->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 't') as $part) {
                    $value .= domNodeTextContent($part);
                }
            } else {
                $value = domChildTextByLocalName($cell, 'v');
            }

            $rowValues[$index] = normalizeSpaces($value);
        }

        if ($rowValues === []) {
            continue;
        }

        ksort($rowValues);
        $maxIndex = max(array_keys($rowValues));
        $normalized = [];

        for ($i = 0; $i <= $maxIndex; $i++) {
            $normalized[] = $rowValues[$i] ?? '';
        }

        $rows[] = $normalized;
    }

    return $rows;
}

function xlsxFirstSheetPath(ZipArchive $zip): string
{
    $workbook = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

    if ($workbook === false || $rels === false) {
        return 'xl/worksheets/sheet1.xml';
    }

    $workbookXml = @simplexml_load_string($workbook);
    $relsXml = @simplexml_load_string($rels);

    if ($workbookXml === false || $relsXml === false) {
        return 'xl/worksheets/sheet1.xml';
    }

    $workbookXml->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $workbookXml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $relsXml->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/package/2006/relationships');

    $sheet = $workbookXml->xpath('//a:sheets/a:sheet[1]');
    if (!$sheet || !isset($sheet[0])) {
        return 'xl/worksheets/sheet1.xml';
    }

    $relationshipId = (string) $sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
    if ($relationshipId === '') {
        return 'xl/worksheets/sheet1.xml';
    }

    foreach ($relsXml->xpath('//a:Relationship') ?: [] as $relationship) {
        if ((string) $relationship['Id'] !== $relationshipId) {
            continue;
        }

        $target = (string) $relationship['Target'];
        if ($target === '') {
            break;
        }

        return str_starts_with($target, 'xl/')
            ? $target
            : 'xl/' . ltrim($target, '/');
    }

    return 'xl/worksheets/sheet1.xml';
}

function parseXlsxFile(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Не удалось открыть XLSX файл.');
    }

    $sharedStrings = xlsxSharedStrings($zip);
    $sheetPath = xlsxFirstSheetPath($zip);
    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();

    if ($sheetXml === false) {
        throw new RuntimeException('Не удалось найти первый лист в XLSX файле.');
    }

    $domRows = parseXlsxSheetDom($sheetXml, $sharedStrings);
    if ($domRows !== []) {
        return $domRows;
    }

    $root = @simplexml_load_string($sheetXml);
    if ($root === false) {
        throw new RuntimeException('Не удалось прочитать XML первого листа.');
    }

    $root->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rows = [];

    foreach ($root->xpath('//a:sheetData/a:row') ?: [] as $row) {
        $rowValues = [];

        foreach ($row->xpath('./a:c') ?: [] as $cell) {
            $cellRef = (string) $cell['r'];
            $index = xlsxColumnToIndex($cellRef);
            $type = (string) $cell['t'];
            $value = '';

            if ($type === 's') {
                $sharedIndex = (int) ($cell->v ?? 0);
                $value = $sharedStrings[$sharedIndex] ?? '';
            } elseif ($type === 'str') {
                $value = (string) ($cell->v ?? '');
                if ($value === '' && isset($cell->f)) {
                    $value = extractFormulaDisplayText((string) $cell->f);
                }
            } elseif ($type === 'inlineStr') {
                foreach ($cell->xpath('.//a:t') ?: [] as $part) {
                    $value .= (string) $part;
                }
            } else {
                $value = (string) ($cell->v ?? '');
            }

            $rowValues[$index] = normalizeSpaces($value);
        }

        if ($rowValues === []) {
            continue;
        }

        ksort($rowValues);
        $maxIndex = max(array_keys($rowValues));
        $normalized = [];

        for ($i = 0; $i <= $maxIndex; $i++) {
            $normalized[] = $rowValues[$i] ?? '';
        }

        $rows[] = $normalized;
    }

    return $rows;
}

function parseSpreadsheetXmlFile(string $path): array
{
    $xml = @simplexml_load_file($path);
    if ($xml === false) {
        throw new RuntimeException('Не удалось прочитать XML файл.');
    }

    $xml->registerXPathNamespace('ss', 'urn:schemas-microsoft-com:office:spreadsheet');
    $rows = [];

    foreach ($xml->xpath('//ss:Worksheet[1]//ss:Table/ss:Row') ?: [] as $row) {
        $values = [];
        $index = 0;

        foreach ($row->xpath('./ss:Cell') ?: [] as $cell) {
            $attributes = $cell->attributes('urn:schemas-microsoft-com:office:spreadsheet');
            if (isset($attributes['Index'])) {
                $index = max(0, ((int) $attributes['Index']) - 1);
            }

            $data = $cell->xpath('./ss:Data');
            $values[$index] = normalizeSpaces((string) ($data[0] ?? ''));
            $index++;
        }

        if ($values === []) {
            continue;
        }

        ksort($values);
        $maxIndex = max(array_keys($values));
        $normalized = [];

        for ($i = 0; $i <= $maxIndex; $i++) {
            $normalized[] = $values[$i] ?? '';
        }

        $rows[] = $normalized;
    }

    return $rows;
}

function parseUploadedSpreadsheet(string $path, string $extension): array
{
    if ($extension === 'xlsx') {
        return parseXlsxFile($path);
    }

    if ($extension === 'xml') {
        return parseSpreadsheetXmlFile($path);
    }

    throw new RuntimeException('Поддерживаются только XLSX и XML Spreadsheet файлы. Формат XLS добавим следующим этапом.');
}

function findHeaderRowIndex(array $sheetRows): int
{
    $requiredHeaders = [
        'номер объявления',
        'город',
        'адрес',
        'название объявления',
        'показы',
        'просмотры',
    ];

    foreach ($sheetRows as $rowIndex => $row) {
        $normalizedHeaders = array_map(static fn($value): string => normalizeHeader((string) $value), $row);
        $matches = 0;

        foreach ($requiredHeaders as $requiredHeader) {
            if (in_array($requiredHeader, $normalizedHeaders, true)) {
                $matches++;
            }
        }

        if ($matches >= 4) {
            return $rowIndex;
        }
    }

    return 0;
}

function extractTableRows(array $sheetRows): array
{
    if ($sheetRows === []) {
        return [];
    }

    $headerRowIndex = findHeaderRowIndex($sheetRows);
    $headers = array_map('normalizeSpaces', $sheetRows[$headerRowIndex] ?? []);
    $result = [];

    for ($i = $headerRowIndex + 1, $count = count($sheetRows); $i < $count; $i++) {
        $row = $sheetRows[$i];
        $hasValue = false;
        $assoc = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $value = normalizeSpaces($row[$index] ?? '');
            if ($value !== '') {
                $hasValue = true;
            }

            $assoc[$header] = $value;
        }

        if ($hasValue) {
            $result[] = $assoc;
        }
    }

    return $result;
}

function firstNonEmptyColumnValue(array $rows, string $column): string
{
    foreach ($rows as $row) {
        $value = normalizeSpaces((string) ($row[$column] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function summaryDirectionFromTitle(string $title): string
{
    $normalized = mb_strtolower($title, 'UTF-8');
    $map = [
        'Iphone' => ['iphone'],
        'Samsung' => ['samsung', 'galaxy'],
        'Macbook' => ['macbook'],
        'ipad' => ['ipad'],
        'airpods' => ['airpods', 'air pods'],
        'Apple Watch' => ['apple watch', 'watch ultra', 'watch se', 'watch series'],
        'Dyson' => ['dyson'],
    ];

    foreach ($map as $direction => $keywords) {
        foreach ($keywords as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return $direction;
            }
        }
    }

    return 'Прочее';
}

function buildDirectionSummary(array $rows): array
{
    $groups = [];

    foreach ($rows as $row) {
        $direction = summaryDirectionFromTitle((string) ($row['Название объявления'] ?? ''));

        if (!isset($groups[$direction])) {
            $groups[$direction] = [
                'direction' => $direction,
                'impressions' => 0.0,
                'views' => 0.0,
                'contacts' => 0.0,
                'ad_spend' => 0.0,
            ];
        }

        $groups[$direction]['impressions'] += parseNumericValue($row['Показы'] ?? 0);
        $groups[$direction]['views'] += parseNumericValue($row['Просмотры'] ?? 0);
        $groups[$direction]['contacts'] += parseNumericValue($row['Контакты'] ?? 0);
        $groups[$direction]['ad_spend'] += parseNumericValue($row['Расходы на объявления'] ?? 0);
    }

    $result = array_values($groups);
    usort($result, static function (array $left, array $right): int {
        return $right['impressions'] <=> $left['impressions'];
    });

    return $result;
}

function formatTableCell(string $column, $value): string
{
    $text = normalizeSpaces((string) $value);

    if ($text === '') {
        return 'н/д';
    }

    $numericColumns = [
        'Цена',
        'Дней на Авито',
        'Показы',
        'Просмотры',
        'Целевые просмотры',
        'Контакты',
        'Написали в чат',
        'Посмотрели телефон',
        'Посмотрели телефон и написали в чат',
        'Откликнулись на скидку в чате',
        'Добавили в избранное',
        'Заказано товаров',
        'Стоимость заказанных товаров',
        'Доставлено товаров',
        'Стоимость доставленных товаров',
        'Расходы на объявления',
        'Списано бонусов на объявления',
        'Расходы на размещение и целевые действия',
        'Расходы на продвижение',
        'Остальные расходы',
        'Комиссия',
        'Средняя цена просмотра',
        'Средняя цена контакта',
    ];

    $percentColumns = [
        'Конверсия из показов в просмотры',
        'Конверсия из просмотров в контакты',
        'Конверсия из просмотров в заказанные товары',
    ];

    if (in_array($column, $numericColumns, true)) {
        $number = parseNumericValue($text);

        if (str_contains($column, 'Цена') || str_contains($column, 'Стоимость') || str_contains($column, 'Расходы') || str_contains($column, 'Комиссия')) {
            return number_format($number, 2, '.', '');
        }

        if (fmod($number, 1.0) === 0.0) {
            return (string) (int) $number;
        }

        return number_format($number, 2, '.', '');
    }

    if (in_array($column, $percentColumns, true)) {
        $number = parseNumericValue($text);
        if ($number === 0.0 && $text === '') {
            return 'н/д';
        }

        if ($number <= 1.0 && $number >= -1.0) {
            $number *= 100;
        }

        return number_format($number, 2, '.', '') . '%';
    }

    return $text;
}

function summaryStorageKey(string $city, array $dateRange): string
{
    return 'summary:' . $city . ':' . ($dateRange['from'] ?? '') . ':' . ($dateRange['to'] ?? '');
}

function existingSliceFileName(string $cityLabel, array $dateRange): string
{
    $periodFrom = normalizeSpaces((string) ($dateRange['from'] ?? ''));
    $periodTo = normalizeSpaces((string) ($dateRange['to'] ?? ''));
    $cityLabel = normalizeSpaces($cityLabel);

    if ($cityLabel === '' || $periodFrom === '' || $periodTo === '') {
        return '';
    }

    $fileName = $periodFrom . '__' . $periodTo . '__' . transliterateForPath($cityLabel) . '.json';

    try {
        $sliceDirectory = resolveSliceDirectory();
        return is_file($sliceDirectory . DIRECTORY_SEPARATOR . $fileName) ? $fileName : '';
    } catch (Throwable $e) {
        return '';
    }
}

function resolveImportDirectory(array &$debugInfo): string
{
    $storageReady = ensureDirectory(STORAGE_DIR);
    $importsReady = ensureDirectory(IMPORTS_DIR);
    $projectWritable = is_dir(IMPORTS_DIR) && is_writable(IMPORTS_DIR);

    $debugInfo[] = ['step' => 'storage_dir_after', 'value' => boolText(is_dir(STORAGE_DIR)) . ', created=' . boolText($storageReady) . ', writable=' . boolText(is_dir(STORAGE_DIR) && is_writable(STORAGE_DIR))];
    $debugInfo[] = ['step' => 'imports_dir_after', 'value' => boolText(is_dir(IMPORTS_DIR)) . ', created=' . boolText($importsReady) . ', writable=' . boolText($projectWritable)];

    if ($projectWritable) {
        return IMPORTS_DIR;
    }

    $fallbackDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'avito_api_imports';
    $fallbackReady = ensureDirectory($fallbackDir);
    $fallbackWritable = is_dir($fallbackDir) && is_writable($fallbackDir);

    $debugInfo[] = ['step' => 'fallback_imports_dir', 'value' => $fallbackDir];
    $debugInfo[] = ['step' => 'fallback_imports_ready', 'value' => boolText($fallbackReady) . ', writable=' . boolText($fallbackWritable)];

    return $fallbackWritable ? $fallbackDir : IMPORTS_DIR;
}

function importFileSafeName(string $filename): string
{
    $baseName = basename($filename);
    $baseName = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $baseName);
    $baseName = preg_replace('/\s+/u', '_', (string) $baseName);
    $baseName = preg_replace('/_+/u', '_', (string) $baseName);
    $baseName = trim((string) $baseName, '._');

    return $baseName !== '' ? $baseName : 'import.xlsx';
}

function buildImportStorageName(array $dataset, string $originalFileName): string
{
    $extension = mb_strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION), 'UTF-8');
    $extension = $extension !== '' ? $extension : 'xlsx';
    $dateRange = $dataset['date_range'] ?? ['from' => '', 'to' => ''];
    $cityName = normalizeSpaces((string) ($dataset['city_name'] ?? ''));
    $periodFrom = normalizeSpaces((string) ($dateRange['from'] ?? ''));
    $periodTo = normalizeSpaces((string) ($dateRange['to'] ?? ''));

    if ($cityName !== '' && $periodFrom !== '' && $periodTo !== '') {
        return $periodFrom . '__' . $periodTo . '__' . transliterateForPath($cityName) . '.' . $extension;
    }

    return importFileSafeName($originalFileName);
}

function removeLegacyImportCopies(string $directory, string $safeName): void
{
    $pattern = $directory . DIRECTORY_SEPARATOR . '*__' . $safeName;
    foreach (glob($pattern) ?: [] as $legacyFile) {
        if (is_file($legacyFile)) {
            @unlink($legacyFile);
        }
    }
}

function listImportedFiles(string $directory): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $files = glob($directory . DIRECTORY_SEPARATOR . '*.{xlsx,xml,xls}', GLOB_BRACE) ?: [];
    $items = [];

    foreach ($files as $filePath) {
        if (!is_file($filePath)) {
            continue;
        }

        $fileName = basename($filePath);
        $displayName = preg_match('/^\d{8}_\d{6}__(.+)$/u', $fileName, $matches) ? $matches[1] : $fileName;

        $item = [
            'file_name' => $fileName,
            'display_name' => $displayName,
            'path' => $filePath,
            'modified_at' => (int) filemtime($filePath),
            'size' => (int) filesize($filePath),
            'city_name' => '',
        ];

        try {
            $cityDebug = [];
            $dataset = loadImportedDataset($filePath, $displayName, $cityDebug);
            $item['city_name'] = normalizeSpaces((string) ($dataset['city_name'] ?? ''));
        } catch (Throwable $e) {
            $item['city_name'] = '';
        }

        $existing = $items[$displayName] ?? null;
        if ($existing === null || $item['modified_at'] > $existing['modified_at']) {
            $items[$displayName] = $item;
        }
    }

    $result = array_values($items);
    usort($result, static function (array $left, array $right): int {
        return ($right['modified_at'] <=> $left['modified_at']) ?: strcmp($left['file_name'], $right['file_name']);
    });

    return $result;
}

function loadImportedDataset(string $path, string $displayName, array &$debugInfo): array
{
    $extension = mb_strtolower(pathinfo($displayName, PATHINFO_EXTENSION), 'UTF-8');
    $debugInfo[] = ['step' => 'parse_file', 'value' => $displayName];
    $debugInfo[] = ['step' => 'parse_path', 'value' => $path];
    $debugInfo[] = ['step' => 'extension', 'value' => $extension];

    $sheetRows = parseUploadedSpreadsheet($path, $extension);
    $debugInfo[] = ['step' => 'sheet_rows_count', 'value' => (string) count($sheetRows)];
    $headerRowIndex = findHeaderRowIndex($sheetRows);
    $debugInfo[] = ['step' => 'header_row_index', 'value' => (string) $headerRowIndex];
    $headerPreview = array_slice(array_map('normalizeSpaces', $sheetRows[$headerRowIndex] ?? []), 0, 8);
    $debugInfo[] = ['step' => 'header_preview', 'value' => implode(' | ', $headerPreview)];
    $importedRows = extractTableRows($sheetRows);
    $debugInfo[] = ['step' => 'imported_rows_count', 'value' => (string) count($importedRows)];

    if ($importedRows === []) {
        $sampleRows = array_slice($sheetRows, max(0, $headerRowIndex), 3);
        foreach ($sampleRows as $sampleIndex => $sampleRow) {
            $debugInfo[] = [
                'step' => 'sample_row_' . ($sampleIndex + 1),
                'value' => implode(' | ', array_slice(array_map('normalizeSpaces', $sampleRow), 0, 8)),
            ];
        }

        throw new RuntimeException('В файле не найдено строк с данными.');
    }

    $dateRange = parseDateRangeFromFilename($displayName);
    $columns = array_keys($importedRows[0]);
    $cityName = firstNonEmptyColumnValue($importedRows, 'Город');
    $summaryRows = buildDirectionSummary($importedRows);
    $summaryContext = summaryStorageKey($cityName, $dateRange);

    $debugInfo[] = ['step' => 'city_name', 'value' => $cityName];
    $debugInfo[] = ['step' => 'summary_rows_count', 'value' => (string) count($summaryRows)];
    $debugInfo[] = ['step' => 'first_item_number', 'value' => (string) ($importedRows[0]['Номер объявления'] ?? '')];
    $debugInfo[] = ['step' => 'first_item_title', 'value' => (string) ($importedRows[0]['Название объявления'] ?? '')];

    return [
        'imported_rows' => $importedRows,
        'summary_rows' => $summaryRows,
        'date_range' => $dateRange,
        'uploaded_file_name' => $displayName,
        'city_name' => $cityName,
        'region_name' => $cityName,
        'columns' => $columns,
        'summary_context' => $summaryContext,
    ];
}

function transliterateForPath(string $value): string
{
    $map = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
        'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    $value = normalizeHeader($value);
    $value = strtr($value, $map);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    $value = trim((string) $value, '_');

    return $value !== '' ? $value : 'slice';
}

function resolveSliceDirectory(): string
{
    $directory = STORAGE_DIR . '/slices';
    ensureDirectory(STORAGE_DIR);
    ensureDirectory($directory);

    if (is_dir($directory) && is_writable($directory)) {
        return $directory;
    }

    $fallbackDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'avito_api_slices';
    ensureDirectory($fallbackDir);

    if (is_dir($fallbackDir) && is_writable($fallbackDir)) {
        return $fallbackDir;
    }

    throw new RuntimeException('Не удалось подготовить папку для сохранения срезов.');
}

function computeSliceTotals(array $summaryRows): array
{
    $totals = [
        'impressions' => 0.0,
        'views' => 0.0,
        'contacts' => 0.0,
        'ad_spend' => 0.0,
        'sales_count' => 0.0,
        'revenue' => 0.0,
        'extra_expenses' => 0.0,
    ];

    foreach ($summaryRows as $row) {
        $totals['impressions'] += parseNumericValue($row['impressions'] ?? 0);
        $totals['views'] += parseNumericValue($row['views'] ?? 0);
        $totals['contacts'] += parseNumericValue($row['contacts'] ?? 0);
        $totals['ad_spend'] += parseNumericValue($row['ad_spend'] ?? 0);
        $totals['sales_count'] += parseNumericValue($row['sales_count'] ?? 0);
        $totals['revenue'] += parseNumericValue($row['revenue'] ?? 0);
        $totals['extra_expenses'] += parseNumericValue($row['extra_expenses'] ?? 0);
    }

    return $totals;
}

function saveSlicePayload(array $payload): array
{
    $cityLabel = normalizeSpaces((string) ($payload['city_label'] ?? ''));
    $periodFrom = normalizeSpaces((string) ($payload['period_from'] ?? ''));
    $periodTo = normalizeSpaces((string) ($payload['period_to'] ?? ''));
    $summaryRows = $payload['summary_rows'] ?? null;

    if ($cityLabel === '') {
        throw new RuntimeException('Не указан город для сохранения среза.');
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodTo)) {
        throw new RuntimeException('Для сохранения нужны корректные даты периода.');
    }

    if (!is_array($summaryRows) || $summaryRows === []) {
        throw new RuntimeException('Нет данных нижней таблицы для сохранения.');
    }

    $cleanRows = [];
    foreach ($summaryRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $direction = normalizeSpaces((string) ($row['direction'] ?? ''));
        if ($direction === '') {
            continue;
        }

        $cleanRows[] = [
            'direction' => $direction,
            'impressions' => parseNumericValue($row['impressions'] ?? 0),
            'views' => parseNumericValue($row['views'] ?? 0),
            'contacts' => parseNumericValue($row['contacts'] ?? 0),
            'ad_spend' => parseNumericValue($row['ad_spend'] ?? 0),
            'sales_count' => parseNumericValue($row['sales_count'] ?? 0),
            'revenue' => parseNumericValue($row['revenue'] ?? 0),
            'extra_expenses' => parseNumericValue($row['extra_expenses'] ?? 0),
        ];
    }

    if ($cleanRows === []) {
        throw new RuntimeException('После очистки данных не осталось строк для сохранения.');
    }

    $cityKey = transliterateForPath($cityLabel);
    $fileName = $periodFrom . '__' . $periodTo . '__' . $cityKey . '.json';
    $directory = resolveSliceDirectory();
    $filePath = $directory . DIRECTORY_SEPARATOR . $fileName;

    $content = [
        'version' => 1,
        'city_label' => $cityLabel,
        'city_key' => $cityKey,
        'period_from' => $periodFrom,
        'period_to' => $periodTo,
        'saved_at' => date(DATE_ATOM),
        'source_filename' => normalizeSpaces((string) ($payload['source_filename'] ?? '')),
        'source_item_count' => (int) parseNumericValue($payload['source_item_count'] ?? 0),
        'summary_rows' => $cleanRows,
        'totals' => computeSliceTotals($cleanRows),
    ];

    $json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Не удалось сериализовать срез в JSON.');
    }

    if (file_put_contents($filePath, $json) === false) {
        throw new RuntimeException('Не удалось сохранить JSON файл среза.');
    }

    return [
        'file_name' => $fileName,
        'file_path' => $filePath,
        'directory' => $directory,
        'totals' => $content['totals'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_slice') {
    header('Content-Type: application/json; charset=UTF-8');

    try {
        $rawPayload = (string) ($_POST['slice_payload'] ?? '');
        if ($rawPayload === '') {
            throw new RuntimeException('Пустой payload сохранения.');
        }

        $payload = json_decode($rawPayload, true, flags: JSON_THROW_ON_ERROR);
        $result = saveSlicePayload($payload);

        echo json_encode([
            'ok' => true,
            'message' => 'Срез сохранен.',
            'file_name' => $result['file_name'],
            'file_path' => $result['file_path'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    exit;
}

$messages = [];
$debugInfo = [];
$importedRows = [];
$summaryRows = [];
$dateRange = ['from' => '', 'to' => ''];
$uploadedFileName = '';
$cityName = '';
$regionName = '';
$columns = [];
$summaryContext = '';
$existingSliceFile = '';
$bootstrapDebug = [];
$activeImportDirectory = resolveImportDirectory($bootstrapDebug);
$availableImports = listImportedFiles($activeImportDirectory);
$selectedImportFile = basename((string) ($_GET['import_file'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $debugInfo[] = ['step' => 'request', 'value' => 'POST'];
    $debugInfo[] = ['step' => 'php_version', 'value' => PHP_VERSION];
    $debugInfo[] = ['step' => 'ziparchive_loaded', 'value' => boolText(class_exists('ZipArchive'))];
    $debugInfo[] = ['step' => 'simplexml_loaded', 'value' => boolText(function_exists('simplexml_load_string'))];
    $debugInfo[] = ['step' => 'dom_loaded', 'value' => boolText(class_exists('DOMDocument'))];
    $debugInfo[] = ['step' => 'mbstring_loaded', 'value' => boolText(function_exists('mb_strtolower'))];
    $debugInfo[] = ['step' => 'storage_dir_before', 'value' => boolText(is_dir(STORAGE_DIR)) . ', writable=' . boolText(is_writable(__DIR__))];
    $activeImportDirectory = resolveImportDirectory($debugInfo);
    $debugInfo[] = ['step' => 'active_import_dir', 'value' => $activeImportDirectory];

    if ($action === 'delete_import') {
        $deleteFileName = basename((string) ($_POST['file_name'] ?? ''));
        $deletePath = $activeImportDirectory . DIRECTORY_SEPARATOR . $deleteFileName;

        if ($deleteFileName === '' || !is_file($deletePath)) {
            $messages[] = ['type' => 'error', 'text' => 'Файл для удаления не найден.'];
        } elseif (!unlink($deletePath)) {
            $messages[] = ['type' => 'error', 'text' => 'Не удалось удалить файл.'];
        } else {
            $messages[] = ['type' => 'success', 'text' => 'Файл удален: ' . $deleteFileName];
            if ($selectedImportFile === $deleteFileName) {
                $selectedImportFile = '';
            }
        }
    } elseif (!isset($_FILES['stats_file']) || !is_array($_FILES['stats_file'])) {
        $messages[] = ['type' => 'error', 'text' => 'Выберите файл выгрузки.'];
        $debugInfo[] = ['step' => 'upload', 'value' => 'stats_file missing'];
    } else {
        $upload = $_FILES['stats_file'];
        $uploadedFileName = (string) ($upload['name'] ?? '');
        $debugInfo[] = ['step' => 'upload_name', 'value' => $uploadedFileName];
        $debugInfo[] = ['step' => 'upload_tmp_name', 'value' => (string) ($upload['tmp_name'] ?? '')];
        $debugInfo[] = ['step' => 'upload_error', 'value' => (string) ($upload['error'] ?? 'unknown')];
        $debugInfo[] = ['step' => 'upload_size', 'value' => (string) ($upload['size'] ?? 0)];
        $debugInfo[] = ['step' => 'is_uploaded_file', 'value' => boolText(isset($upload['tmp_name']) && is_uploaded_file((string) $upload['tmp_name']))];

        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $messages[] = ['type' => 'error', 'text' => 'Не удалось загрузить файл.'];
        } elseif (($upload['size'] ?? 0) > MAX_UPLOAD_BYTES) {
            $messages[] = ['type' => 'error', 'text' => 'Файл слишком большой.'];
        } else {
            $tempName = '__upload__' . uniqid('', true) . '.' . mb_strtolower(pathinfo($uploadedFileName, PATHINFO_EXTENSION), 'UTF-8');
            $savedPath = rtrim($activeImportDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $tempName;
            $debugInfo[] = ['step' => 'saved_path', 'value' => $savedPath];

            if (!move_uploaded_file((string) $upload['tmp_name'], $savedPath)) {
                $messages[] = ['type' => 'error', 'text' => 'Не удалось сохранить загруженный файл в папку проекта.'];
                $debugInfo[] = ['step' => 'move_uploaded_file', 'value' => 'failed'];
            } else {
                $debugInfo[] = ['step' => 'move_uploaded_file', 'value' => 'ok'];
                $debugInfo[] = ['step' => 'saved_file_exists', 'value' => boolText(is_file($savedPath))];
                try {
                    $dataset = loadImportedDataset($savedPath, $uploadedFileName, $debugInfo);
                    $finalFileName = buildImportStorageName($dataset, $uploadedFileName);
                    $finalPath = rtrim($activeImportDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $finalFileName;
                    $debugInfo[] = ['step' => 'final_import_file', 'value' => $finalFileName];

                    if ($finalPath !== $savedPath) {
                        if (is_file($finalPath)) {
                            unlink($finalPath);
                        }
                        rename($savedPath, $finalPath);
                    }

                    $importedRows = $dataset['imported_rows'];
                    $summaryRows = $dataset['summary_rows'];
                    $dateRange = $dataset['date_range'];
                    $uploadedFileName = $finalFileName;
                    $cityName = $dataset['city_name'];
                    $regionName = $dataset['region_name'];
                    $columns = $dataset['columns'];
                    $summaryContext = $dataset['summary_context'];
                    $existingSliceFile = existingSliceFileName($cityName, $dateRange);
                    $selectedImportFile = $finalFileName;
                    $messages[] = ['type' => 'success', 'text' => 'Файл загружен и распарсен. Если для этого города и периода уже был импорт, он перезаписан.'];
                } catch (Throwable $e) {
                    if (is_file($savedPath)) {
                        @unlink($savedPath);
                    }
                    $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
                    $debugInfo[] = ['step' => 'exception_class', 'value' => get_class($e)];
                    $debugInfo[] = ['step' => 'exception_message', 'value' => $e->getMessage()];
                }
            }
        }
    }
}

$availableImports = listImportedFiles($activeImportDirectory);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($selectedImportFile === '' && $availableImports !== []) {
        $selectedImportFile = (string) ($availableImports[0]['file_name'] ?? '');
    }

    if ($selectedImportFile !== '') {
        $selectedImportPath = $activeImportDirectory . DIRECTORY_SEPARATOR . $selectedImportFile;
        if (is_file($selectedImportPath)) {
            try {
                $loadDebug = [];
                $dataset = loadImportedDataset($selectedImportPath, $selectedImportFile, $loadDebug);
                $importedRows = $dataset['imported_rows'];
                $summaryRows = $dataset['summary_rows'];
                $dateRange = $dataset['date_range'];
                $uploadedFileName = $dataset['uploaded_file_name'];
                $cityName = $dataset['city_name'];
                $regionName = $dataset['region_name'];
                $columns = $dataset['columns'];
                $summaryContext = $dataset['summary_context'];
                $existingSliceFile = existingSliceFileName($cityName, $dateRange);
            } catch (Throwable $e) {
                $messages[] = ['type' => 'error', 'text' => 'Не удалось открыть сохраненный файл: ' . $e->getMessage()];
                $debugInfo[] = ['step' => 'request', 'value' => 'GET'];
                $debugInfo[] = ['step' => 'selected_import_file', 'value' => $selectedImportFile];
                $debugInfo[] = ['step' => 'exception_class', 'value' => get_class($e)];
                $debugInfo[] = ['step' => 'exception_message', 'value' => $e->getMessage()];
            }
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Импорт статистики Avito</title>
    <style>
        :root {
            --bg: #eef3f6;
            --panel: rgba(255, 255, 255, 0.92);
            --text: #1c2731;
            --muted: #586876;
            --line: rgba(28, 39, 49, 0.12);
            --accent: #0d6c8f;
            --accent-dark: #0a4f68;
            --warn: #9a6700;
            --success: #1f7a48;
            --error: #aa2e25;
            --shadow: 0 16px 40px rgba(24, 33, 43, 0.08);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(13, 108, 143, 0.08), transparent 32%),
                linear-gradient(180deg, #f7fafc, #eef3f6);
        }

        .page {
            width: min(96vw, 1780px);
            margin: 0 auto;
            padding: 24px 20px 48px;
        }

        .hero,
        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 24px;
            box-shadow: var(--shadow);
        }

        .hero {
            padding: 26px 28px;
            margin-bottom: 20px;
        }

        .hero h1 {
            margin: 0 0 8px;
            font-size: clamp(28px, 4vw, 42px);
        }

        .hero p {
            margin: 0;
            max-width: 920px;
            color: var(--muted);
            line-height: 1.6;
        }

        .stack {
            display: grid;
            gap: 18px;
            min-width: 0;
        }

        .panel {
            padding: 22px;
            min-width: 0;
        }

        h2, h3 {
            margin: 0 0 14px;
        }

        .row {
            display: grid;
            grid-template-columns: 1.5fr auto;
            gap: 12px;
            align-items: center;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
        }

        input[type="file"],
        button {
            width: 100%;
            border-radius: 14px;
            border: 1px solid var(--line);
            font: inherit;
        }

        input[type="file"] {
            padding: 12px 14px;
            background: #fff;
        }

        button {
            padding: 13px 18px;
            color: #fff;
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            border: 0;
            cursor: pointer;
            font-weight: 700;
        }

        .hint,
        .small {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.55;
        }

        .messages {
            display: grid;
            gap: 10px;
            margin-bottom: 16px;
        }

        .message {
            padding: 13px 14px;
            border-radius: 16px;
            border: 1px solid transparent;
        }

        .message-success {
            color: var(--success);
            background: rgba(31, 122, 72, 0.08);
            border-color: rgba(31, 122, 72, 0.18);
        }

        .message-error {
            color: var(--error);
            background: rgba(170, 46, 37, 0.08);
            border-color: rgba(170, 46, 37, 0.18);
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .stat {
            padding: 14px 16px;
            border-radius: 18px;
            background: rgba(13, 108, 143, 0.08);
        }

        .stat strong {
            display: block;
            margin-top: 6px;
            font-size: 18px;
        }

        .table-wrap {
            width: 100%;
            max-width: 100%;
            overflow: auto;
            border-radius: 18px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.85);
        }

        .report-table-wrap {
            max-height: 90vh;
        }

        .imports-table-wrap {
            max-height: 255px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
            font-size: 13px;
            white-space: nowrap;
        }

        th {
            position: sticky;
            top: 0;
            background: #edf4f8;
            z-index: 2;
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .address-column {
            font-size: 10px;
            line-height: 1.35;
            white-space: normal;
            min-width: 180px;
        }

        .na-cell {
            background: rgba(255, 120, 160, 0.12);
            color: #9a3357;
        }

        .summary-table input {
            width: 110px;
            min-width: 110px;
            padding: 8px 10px;
            border-radius: 10px;
            border: 1px solid var(--line);
            font: inherit;
        }

        .summary-total td {
            font-weight: 700;
            background: rgba(13, 108, 143, 0.08);
        }

        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            margin-bottom: 14px;
        }

        .inline-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .inline-actions .button-link,
        .inline-actions button {
            width: auto;
            min-width: 0;
        }

        .toolbar .button-link,
        .toolbar button {
            width: auto;
            min-width: 210px;
        }

        .button-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            padding: 13px 18px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--text);
            text-decoration: none;
            font-weight: 700;
        }

        .save-status {
            color: var(--muted);
            font-size: 13px;
        }

        .debug-table td:first-child {
            width: 240px;
            color: var(--muted);
            font-weight: 700;
        }

        @media (max-width: 760px) {
            .page {
                width: 100%;
                padding: 16px 12px 36px;
            }

            .row {
                grid-template-columns: 1fr;
            }

            .hero,
            .panel {
                padding: 18px;
                border-radius: 20px;
            }
        }
    </style>
</head>
<body>
<div class="page">
    <section class="hero">
        <h1>Импорт статистики Avito из файла</h1>
        <p>
            Загружаете файл, страница парсит строки,
            берет период из имени файла вида <code>Статистика_с_2024-10-09_по_2026-04-12</code>,
            строит основную таблицу и нижнюю сводку по направлениям.
        </p>
    </section>

    <div class="stack">
        <section class="panel">
            <h2>Загрузка файла</h2>

            <?php if ($messages !== []): ?>
                <div class="messages">
                    <?php foreach ($messages as $message): ?>
                        <div class="message message-<?= h($message['type']) ?>"><?= h($message['text']) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <div class="row">
                    <div>
                        <label for="stats_file">Файл выгрузки</label>
                        <input id="stats_file" name="stats_file" type="file" accept=".xlsx,.xml,.xls">
                        <div class="hint">Для текущей версии поддерживаются <code>.xlsx</code> и <code>.xml</code>. Формат <code>.xls</code> добавим следующим этапом.</div>
                    </div>
                    <div>
                        <button type="submit">Загрузить и распарсить</button>
                    </div>
                </div>
            </form>
        </section>

        <section class="panel">
            <h2>Загруженные файлы</h2>
            <p class="small">
                Здесь можно открыть уже сохраненную выгрузку без новой загрузки. Если загрузить файл с тем же именем еще раз,
                старая версия будет перезаписана.
            </p>
            <div class="table-wrap imports-table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Файл</th>
                        <th>Город</th>
                        <th>Изменен</th>
                        <th>Размер</th>
                        <th>Действие</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($availableImports === []): ?>
                        <tr>
                            <td colspan="5">Сохраненных файлов пока нет.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($availableImports as $importFile): ?>
                            <tr>
                                <td><?= h($importFile['display_name'] ?? $importFile['file_name']) ?></td>
                                <td><?= h(($importFile['city_name'] ?? '') !== '' ? $importFile['city_name'] : 'н/д') ?></td>
                                <td><?= h(date('Y-m-d H:i', (int) $importFile['modified_at'])) ?></td>
                                <td><?= h(number_format(((int) $importFile['size']) / 1024, 1, ',', ' ')) ?> KB</td>
                                <td>
                                    <div class="inline-actions">
                                        <a class="button-link<?= ($selectedImportFile !== '' && $selectedImportFile === $importFile['file_name']) ? ' secondary' : '' ?>" href="/local/avito_api/index.php?import_file=<?= urlencode((string) $importFile['file_name']) ?>">
                                            <?= ($selectedImportFile !== '' && $selectedImportFile === $importFile['file_name']) ? 'Открыт' : 'Открыть' ?>
                                        </a>
                                        <form method="post" onsubmit="return confirm('Удалить этот файл выгрузки?');" style="margin:0;">
                                            <input type="hidden" name="action" value="delete_import">
                                            <input type="hidden" name="file_name" value="<?= h($importFile['file_name']) ?>">
                                            <button type="submit" class="button-link secondary">Удалить</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if ($importedRows !== []): ?>
            <section class="panel">
                <h2>Срез из файла</h2>
                <div class="stats">
                    <div class="stat">
                        Файл
                        <strong><?= h($uploadedFileName) ?></strong>
                    </div>
                    <div class="stat">
                        Период
                        <strong><?= h(($dateRange['from'] ?: 'н/д') . ' → ' . ($dateRange['to'] ?: 'н/д')) ?></strong>
                    </div>
                    <div class="stat">
                        Город
                        <strong><?= h($cityName !== '' ? $cityName : 'н/д') ?></strong>
                    </div>
                    <div class="stat">
                        Регион
                        <strong><?= h($regionName !== '' ? $regionName : 'н/д') ?></strong>
                    </div>
                    <div class="stat">
                        Объявлений в выгрузке
                        <strong><?= h((string) count($importedRows)) ?></strong>
                    </div>
                </div>

                <div class="toolbar">
                    <a class="button-link" href="/local/avito_api/history.php">Открыть историю срезов</a>
                </div>

                <div class="table-wrap report-table-wrap">
                    <table id="source-table">
                        <thead>
                        <tr>
                            <?php foreach ($columns as $column): ?>
                                <th<?= $column === 'Адрес' ? ' class="address-column"' : '' ?>><?= h($column) ?></th>
                            <?php endforeach; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($importedRows as $row): ?>
                            <tr>
                                <?php foreach ($columns as $column): ?>
                                    <?php $cell = formatTableCell($column, $row[$column] ?? ''); ?>
                                    <?php
                                    $cellClasses = [];
                                    if ($column === 'Адрес') {
                                        $cellClasses[] = 'address-column';
                                    }
                                    if ($cell === 'н/д') {
                                        $cellClasses[] = 'na-cell';
                                    }
                                    ?>
                                    <td<?= $cellClasses !== [] ? ' class="' . h(implode(' ', $cellClasses)) . '"' : '' ?>>
                                        <?= h($cell) ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <?php if ($summaryRows !== []): ?>
                <section class="panel">
                    <h2>Сводка по направлениям</h2>
                    <p class="small">
                        Нижняя таблица строится только по загруженному файлу. Можно вручную заполнить продажи, выручку и дополнительные расходы,
                        после чего показатели пересчитаются без нового запроса и без перезагрузки страницы.
                    </p>
                    <div class="toolbar">
                        <button type="button" id="save-slice-button">Сохранить показания</button>
                        <a class="button-link" href="/local/avito_api/history.php">История и графики</a>
                        <span class="save-status" id="save-slice-status"><?= $existingSliceFile !== '' ? 'Срез уже сохранен: ' . h($existingSliceFile) : 'Срез еще не сохранен.' ?></span>
                    </div>
                    <div class="table-wrap report-table-wrap">
                        <table
                            class="summary-table"
                            id="direction-summary-table"
                            data-context="<?= h($summaryContext) ?>"
                            data-city-label="<?= h($cityName) ?>"
                            data-period-from="<?= h($dateRange['from']) ?>"
                            data-period-to="<?= h($dateRange['to']) ?>"
                            data-source-filename="<?= h($uploadedFileName) ?>"
                            data-source-item-count="<?= h((string) count($importedRows)) ?>"
                        >
                            <thead>
                            <tr>
                                <th>Направления</th>
                                <th>Показы</th>
                                <th>CTR</th>
                                <th>Просмотры</th>
                                <th>CR1</th>
                                <th>Количество контактов</th>
                                <th>CPL</th>
                                <th>CR2</th>
                                <th>Количество продаж</th>
                                <th>CPO</th>
                                <th>Выручка от заказов</th>
                                <th>ROI</th>
                                <th>ROAS</th>
                                <th>Расходы на рекламу</th>
                                <th>Расходы дополнительные</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($summaryRows as $summaryRow): ?>
                                <tr
                                    data-direction="<?= h($summaryRow['direction']) ?>"
                                    data-impressions="<?= h((string) $summaryRow['impressions']) ?>"
                                    data-views="<?= h((string) $summaryRow['views']) ?>"
                                    data-contacts="<?= h((string) $summaryRow['contacts']) ?>"
                                    data-ad-spend="<?= h((string) $summaryRow['ad_spend']) ?>"
                                >
                                    <td><?= h($summaryRow['direction']) ?></td>
                                    <td data-field="impressions"></td>
                                    <td data-field="ctr"></td>
                                    <td data-field="views"></td>
                                    <td data-field="cr1"></td>
                                    <td data-field="contacts"></td>
                                    <td data-field="cpl"></td>
                                    <td data-field="cr2"></td>
                                    <td><input type="number" min="0" step="1" data-manual="sales_count" placeholder="0"></td>
                                    <td data-field="cpo"></td>
                                    <td><input type="number" min="0" step="0.01" data-manual="revenue" placeholder="0"></td>
                                    <td data-field="roi"></td>
                                    <td data-field="roas"></td>
                                    <td data-field="ad_spend"></td>
                                    <td><input type="number" min="0" step="0.01" data-manual="extra_expenses" placeholder="0"></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="summary-total">
                                <td>ИТОГО</td>
                                <td data-total="impressions"></td>
                                <td data-total="ctr"></td>
                                <td data-total="views"></td>
                                <td data-total="cr1"></td>
                                <td data-total="contacts"></td>
                                <td data-total="cpl"></td>
                                <td data-total="cr2"></td>
                                <td data-total="sales_count"></td>
                                <td data-total="cpo"></td>
                                <td data-total="revenue"></td>
                                <td data-total="roi"></td>
                                <td data-total="roas"></td>
                                <td data-total="ad_spend"></td>
                                <td data-total="extra_expenses"></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const summaryTable = document.getElementById('direction-summary-table');
    const saveSliceButton = document.getElementById('save-slice-button');
    const saveSliceStatus = document.getElementById('save-slice-status');

    if (!summaryTable) {
        return;
    }

    function parseNumber(value) {
        const normalized = String(value || '').replace(/\s/g, '').replace(',', '.');
        const parsed = parseFloat(normalized);

        return Number.isFinite(parsed) ? parsed : 0;
    }

    function formatInteger(value) {
        return Math.round(value).toLocaleString('ru-RU');
    }

    function formatPercent(value) {
        if (!Number.isFinite(value)) {
            return 'н/д';
        }

        return value.toLocaleString('ru-RU', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }) + '%';
    }

    function formatMoney(value) {
        if (!Number.isFinite(value)) {
            return 'н/д';
        }

        return 'р.' + value.toLocaleString('ru-RU', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function formatDecimal(value) {
        if (!Number.isFinite(value)) {
            return 'н/д';
        }

        return value.toLocaleString('ru-RU', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function ratioPercent(part, total) {
        if (!Number.isFinite(part) || !Number.isFinite(total) || total <= 0) {
            return 'н/д';
        }

        return formatPercent((part / total) * 100);
    }

    function unitCost(sum, count) {
        if (!Number.isFinite(sum) || !Number.isFinite(count) || count <= 0) {
            return 'н/д';
        }

        return formatMoney(sum / count);
    }

    function storageKey() {
        return 'avito-import-summary:' + (summaryTable.dataset.context || 'default');
    }

    function loadManualValues() {
        try {
            const raw = window.localStorage.getItem(storageKey());
            return raw ? JSON.parse(raw) : {};
        } catch (error) {
            return {};
        }
    }

    function saveManualValues(values) {
        try {
            window.localStorage.setItem(storageKey(), JSON.stringify(values));
        } catch (error) {
        }
    }

    function collectSlicePayload() {
        const rows = Array.from(summaryTable.querySelectorAll('tbody tr[data-direction]')).map(function (row) {
            return {
                direction: row.dataset.direction || '',
                impressions: parseNumber(row.dataset.impressions),
                views: parseNumber(row.dataset.views),
                contacts: parseNumber(row.dataset.contacts),
                ad_spend: parseNumber(row.dataset.adSpend),
                sales_count: parseNumber(row.querySelector('[data-manual="sales_count"]')?.value),
                revenue: parseNumber(row.querySelector('[data-manual="revenue"]')?.value),
                extra_expenses: parseNumber(row.querySelector('[data-manual="extra_expenses"]')?.value)
            };
        });

        return {
            city_label: summaryTable.dataset.cityLabel || '',
            period_from: summaryTable.dataset.periodFrom || '',
            period_to: summaryTable.dataset.periodTo || '',
            source_filename: summaryTable.dataset.sourceFilename || '',
            source_item_count: parseNumber(summaryTable.dataset.sourceItemCount || 0),
            summary_rows: rows
        };
    }

    function recalculate() {
        const rows = Array.from(summaryTable.querySelectorAll('tbody tr[data-direction]'));
        const manualValues = {};
        const totals = {
            impressions: 0,
            views: 0,
            contacts: 0,
            sales_count: 0,
            revenue: 0,
            ad_spend: 0,
            extra_expenses: 0
        };

        rows.forEach(function (row) {
            const direction = row.dataset.direction || '';
            const impressions = parseNumber(row.dataset.impressions);
            const views = parseNumber(row.dataset.views);
            const contacts = parseNumber(row.dataset.contacts);
            const adSpend = parseNumber(row.dataset.adSpend);
            const salesCount = parseNumber(row.querySelector('[data-manual="sales_count"]')?.value);
            const revenue = parseNumber(row.querySelector('[data-manual="revenue"]')?.value);
            const extraExpenses = parseNumber(row.querySelector('[data-manual="extra_expenses"]')?.value);
            const totalExpenses = adSpend + extraExpenses;
            const roi = totalExpenses > 0 ? ((revenue - totalExpenses) / totalExpenses) * 100 : NaN;
            const roas = adSpend > 0 ? revenue / adSpend : NaN;

            manualValues[direction] = {
                sales_count: salesCount,
                revenue: revenue,
                extra_expenses: extraExpenses
            };

            totals.impressions += impressions;
            totals.views += views;
            totals.contacts += contacts;
            totals.sales_count += salesCount;
            totals.revenue += revenue;
            totals.ad_spend += adSpend;
            totals.extra_expenses += extraExpenses;

            const values = {
                impressions: formatInteger(impressions),
                ctr: ratioPercent(views, impressions),
                views: formatInteger(views),
                cr1: ratioPercent(contacts, views),
                contacts: formatInteger(contacts),
                cpl: unitCost(adSpend, contacts),
                cr2: ratioPercent(salesCount, contacts),
                cpo: unitCost(adSpend, salesCount),
                roi: Number.isFinite(roi) ? formatPercent(roi) : 'н/д',
                roas: Number.isFinite(roas) ? formatDecimal(roas) : 'н/д',
                ad_spend: formatMoney(adSpend)
            };

            Object.keys(values).forEach(function (field) {
                const cell = row.querySelector('[data-field="' + field + '"]');
                if (cell) {
                    cell.textContent = values[field];
                }
            });
        });

        saveManualValues(manualValues);

        const totalExpenses = totals.ad_spend + totals.extra_expenses;
        const totalValues = {
            impressions: formatInteger(totals.impressions),
            ctr: ratioPercent(totals.views, totals.impressions),
            views: formatInteger(totals.views),
            cr1: ratioPercent(totals.contacts, totals.views),
            contacts: formatInteger(totals.contacts),
            cpl: unitCost(totals.ad_spend, totals.contacts),
            cr2: ratioPercent(totals.sales_count, totals.contacts),
            sales_count: formatInteger(totals.sales_count),
            cpo: unitCost(totals.ad_spend, totals.sales_count),
            revenue: formatMoney(totals.revenue),
            roi: totalExpenses > 0 ? formatPercent(((totals.revenue - totalExpenses) / totalExpenses) * 100) : 'н/д',
            roas: totals.ad_spend > 0 ? formatDecimal(totals.revenue / totals.ad_spend) : 'н/д',
            ad_spend: formatMoney(totals.ad_spend),
            extra_expenses: formatMoney(totals.extra_expenses)
        };

        Object.keys(totalValues).forEach(function (field) {
            const cell = summaryTable.querySelector('[data-total="' + field + '"]');
            if (cell) {
                cell.textContent = totalValues[field];
            }
        });
    }

    async function saveSlice() {
        const payload = collectSlicePayload();

        if (!payload.city_label || !payload.period_from || !payload.period_to || payload.summary_rows.length === 0) {
            if (saveSliceStatus) {
                saveSliceStatus.textContent = 'Не хватает данных для сохранения среза.';
            }
            return;
        }

        if (saveSliceButton) {
            saveSliceButton.disabled = true;
            saveSliceButton.textContent = 'Сохраняю...';
        }

        if (saveSliceStatus) {
            saveSliceStatus.textContent = 'Идет сохранение среза...';
        }

        try {
            const body = new URLSearchParams();
            body.set('action', 'save_slice');
            body.set('slice_payload', JSON.stringify(payload));

            const response = await fetch(window.location.pathname, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            });

            const result = await response.json();
            if (!response.ok || !result.ok) {
                throw new Error(result.message || 'Не удалось сохранить срез.');
            }

            if (saveSliceStatus) {
                saveSliceStatus.textContent = 'Срез сохранен: ' + (result.file_name || '');
            }
        } catch (error) {
            if (saveSliceStatus) {
                saveSliceStatus.textContent = error instanceof Error ? error.message : 'Ошибка сохранения среза.';
            }
        } finally {
            if (saveSliceButton) {
                saveSliceButton.disabled = false;
                saveSliceButton.textContent = 'Сохранить показания';
            }
        }
    }

    const savedValues = loadManualValues();

    Array.from(summaryTable.querySelectorAll('tbody tr[data-direction]')).forEach(function (row) {
        const direction = row.dataset.direction || '';
        const rowValues = savedValues[direction] || {};

        row.querySelectorAll('[data-manual]').forEach(function (input) {
            const field = input.dataset.manual || '';
            if (Object.prototype.hasOwnProperty.call(rowValues, field)) {
                input.value = rowValues[field];
            }

            input.addEventListener('input', recalculate);
        });
    });

    if (saveSliceButton) {
        saveSliceButton.addEventListener('click', saveSlice);
    }

    recalculate();
});
</script>
</body>
</html>
