<?php

declare(strict_types=1);

const STORAGE_DIR = __DIR__ . '/storage';
const SLICE_DIR = STORAGE_DIR . '/slices';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
    ensureDirectory(STORAGE_DIR);
    ensureDirectory(SLICE_DIR);

    if (is_dir(SLICE_DIR) && is_writable(SLICE_DIR)) {
        return SLICE_DIR;
    }

    $fallbackDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'avito_api_slices';
    ensureDirectory($fallbackDir);

    if (is_dir($fallbackDir) && is_writable($fallbackDir)) {
        return $fallbackDir;
    }

    throw new RuntimeException('Не удалось получить доступ к папке со срезами.');
}

function computeRawTotals(array $summaryRows): array
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

function distributeExtraExpensesEvenly(float $total, int $count): array
{
    if ($count <= 0) {
        return [];
    }

    $totalCents = (int) round($total * 100);
    $base = (int) floor($totalCents / $count);
    $remainder = $totalCents - ($base * $count);
    $result = [];

    for ($index = 0; $index < $count; $index++) {
        $cents = $base + ($index < $remainder ? 1 : 0);
        $result[] = $cents / 100;
    }

    return $result;
}

function metricValueFromRaw(array $raw, string $metric): float
{
    $impressions = parseNumericValue($raw['impressions'] ?? 0);
    $views = parseNumericValue($raw['views'] ?? 0);
    $contacts = parseNumericValue($raw['contacts'] ?? 0);
    $adSpend = parseNumericValue($raw['ad_spend'] ?? 0);
    $salesCount = parseNumericValue($raw['sales_count'] ?? 0);
    $revenue = parseNumericValue($raw['revenue'] ?? 0);
    $extraExpenses = parseNumericValue($raw['extra_expenses'] ?? 0);
    $totalExpenses = $adSpend + $extraExpenses;

    return match ($metric) {
        'impressions' => $impressions,
        'views' => $views,
        'contacts' => $contacts,
        'ad_spend' => $adSpend,
        'sales_count' => $salesCount,
        'revenue' => $revenue,
        'extra_expenses' => $extraExpenses,
        'ctr' => $impressions > 0 ? ($views / $impressions) * 100 : 0.0,
        'cr1' => $views > 0 ? ($contacts / $views) * 100 : 0.0,
        'cr2' => $contacts > 0 ? ($salesCount / $contacts) * 100 : 0.0,
        'cpl' => $contacts > 0 ? ($totalExpenses / $contacts) : 0.0,
        'cpo' => $salesCount > 0 ? ($adSpend / $salesCount) : 0.0,
        'roi' => $totalExpenses > 0 ? (($revenue - $totalExpenses) / $totalExpenses) * 100 : 0.0,
        'roas' => $adSpend > 0 ? ($revenue / $adSpend) : 0.0,
        default => 0.0,
    };
}

function selectableMetrics(): array
{
    return ['revenue', 'sales_count', 'ad_spend', 'contacts', 'views', 'impressions', 'roi', 'roas', 'cpl', 'cpo', 'ctr', 'cr1', 'cr2', 'extra_expenses'];
}

function selectedMetricsFromRequest(): array
{
    $allowed = selectableMetrics();
    $requested = array_map(
        static fn($value): string => normalizeSpaces((string) $value),
        (array) ($_GET['metrics'] ?? [])
    );

    $selected = array_values(array_filter($requested, static fn(string $metric): bool => in_array($metric, $allowed, true)));

    return $selected !== [] ? array_values(array_unique($selected)) : $allowed;
}

function metricLabel(string $metric): string
{
    return [
        '__all__' => 'Все метрики',
        'impressions' => 'Показы',
        'views' => 'Просмотры',
        'contacts' => 'Контакты',
        'ad_spend' => 'Расходы на рекламу',
        'sales_count' => 'Количество продаж',
        'revenue' => 'Выручка',
        'extra_expenses' => 'Расходы дополнительные',
        'ctr' => 'CTR',
        'cr1' => 'CR1',
        'cr2' => 'CR2',
        'cpl' => 'CPL',
        'cpo' => 'CPO',
        'roi' => 'ROI',
        'roas' => 'ROAS',
    ][$metric] ?? $metric;
}

function formatMetricValue(float $value, string $metric): string
{
    if (in_array($metric, ['ctr', 'cr1', 'cr2', 'roi'], true)) {
        return number_format($value, 2, ',', ' ') . '%';
    }

    if (in_array($metric, ['ad_spend', 'revenue', 'extra_expenses', 'cpl', 'cpo'], true)) {
        return 'р.' . number_format($value, 2, ',', ' ');
    }

    if ($metric === 'roas') {
        return number_format($value, 2, ',', ' ');
    }

    return number_format($value, 0, ',', ' ');
}

function buildSliceFileName(string $cityLabel, string $periodFrom, string $periodTo): string
{
    return $periodFrom . '__' . $periodTo . '__' . transliterateForPath($cityLabel) . '.json';
}

function loadSliceFile(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }

    $data['__file'] = basename($path);
    $data['__path'] = $path;
    $data['summary_rows'] = is_array($data['summary_rows'] ?? null) ? $data['summary_rows'] : [];
    $data['totals'] = is_array($data['totals'] ?? null) ? $data['totals'] : computeRawTotals($data['summary_rows']);
    $data['city_label'] = normalizeSpaces((string) ($data['city_label'] ?? ''));
    $data['period_from'] = normalizeSpaces((string) ($data['period_from'] ?? ''));
    $data['period_to'] = normalizeSpaces((string) ($data['period_to'] ?? ''));

    return $data;
}

function loadAllSlices(string $directory): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $files = glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [];
    $items = [];

    foreach ($files as $file) {
        $item = loadSliceFile($file);
        if ($item === null) {
            continue;
        }

        $items[] = $item;
    }

    usort($items, static function (array $left, array $right): int {
        return strcmp(($left['period_from'] ?? ''), ($right['period_from'] ?? ''));
    });

    return $items;
}

function availableCities(array $slices): array
{
    $cities = [];
    foreach ($slices as $slice) {
        $city = normalizeSpaces((string) ($slice['city_label'] ?? ''));
        if ($city !== '') {
            $cities[$city] = $city;
        }
    }

    ksort($cities, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($cities);
}

function availableDirections(array $slices, string $cityFilter): array
{
    $directions = [];

    foreach ($slices as $slice) {
        if ($cityFilter !== '' && ($slice['city_label'] ?? '') !== $cityFilter) {
            continue;
        }

        foreach ($slice['summary_rows'] ?? [] as $row) {
            $direction = normalizeSpaces((string) ($row['direction'] ?? ''));
            if ($direction !== '') {
                $directions[$direction] = $direction;
            }
        }
    }

    ksort($directions, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($directions);
}

function sliceValueForGraph(array $slice, string $metric, string $direction): float
{
    if ($direction === '__all__') {
        return metricValueFromRaw($slice['totals'] ?? [], $metric);
    }

    foreach ($slice['summary_rows'] ?? [] as $row) {
        if (($row['direction'] ?? '') === $direction) {
            return metricValueFromRaw($row, $metric);
        }
    }

    return 0.0;
}

function aggregateSummaryRows(array $summaryRows): array
{
    $grouped = [];

    foreach ($summaryRows as $row) {
        $direction = normalizeSpaces((string) ($row['direction'] ?? ''));
        if ($direction === '') {
            continue;
        }

        if (!isset($grouped[$direction])) {
            $grouped[$direction] = [
                'direction' => $direction,
                'impressions' => 0.0,
                'views' => 0.0,
                'contacts' => 0.0,
                'ad_spend' => 0.0,
                'sales_count' => 0.0,
                'revenue' => 0.0,
                'extra_expenses' => 0.0,
            ];
        }

        $grouped[$direction]['impressions'] += parseNumericValue($row['impressions'] ?? 0);
        $grouped[$direction]['views'] += parseNumericValue($row['views'] ?? 0);
        $grouped[$direction]['contacts'] += parseNumericValue($row['contacts'] ?? 0);
        $grouped[$direction]['ad_spend'] += parseNumericValue($row['ad_spend'] ?? 0);
        $grouped[$direction]['sales_count'] += parseNumericValue($row['sales_count'] ?? 0);
        $grouped[$direction]['revenue'] += parseNumericValue($row['revenue'] ?? 0);
        $grouped[$direction]['extra_expenses'] += parseNumericValue($row['extra_expenses'] ?? 0);
    }

    $result = array_values($grouped);
    usort($result, static function (array $left, array $right): int {
        return parseNumericValue($right['impressions'] ?? 0) <=> parseNumericValue($left['impressions'] ?? 0);
    });

    return $result;
}

function aggregateSlicesByPeriod(array $slices): array
{
    $grouped = [];

    foreach ($slices as $slice) {
        $periodFrom = normalizeSpaces((string) ($slice['period_from'] ?? ''));
        $periodTo = normalizeSpaces((string) ($slice['period_to'] ?? ''));
        $key = $periodFrom . '|' . $periodTo;

        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'period_from' => $periodFrom,
                'period_to' => $periodTo,
                'city_label' => '',
                'summary_rows' => [],
                'totals' => [
                    'impressions' => 0.0,
                    'views' => 0.0,
                    'contacts' => 0.0,
                    'ad_spend' => 0.0,
                    'sales_count' => 0.0,
                    'revenue' => 0.0,
                    'extra_expenses' => 0.0,
                ],
                '__files' => [],
                '__cities' => [],
            ];
        }

        $grouped[$key]['summary_rows'] = array_merge($grouped[$key]['summary_rows'], (array) ($slice['summary_rows'] ?? []));
        $grouped[$key]['totals']['impressions'] += parseNumericValue($slice['totals']['impressions'] ?? 0);
        $grouped[$key]['totals']['views'] += parseNumericValue($slice['totals']['views'] ?? 0);
        $grouped[$key]['totals']['contacts'] += parseNumericValue($slice['totals']['contacts'] ?? 0);
        $grouped[$key]['totals']['ad_spend'] += parseNumericValue($slice['totals']['ad_spend'] ?? 0);
        $grouped[$key]['totals']['sales_count'] += parseNumericValue($slice['totals']['sales_count'] ?? 0);
        $grouped[$key]['totals']['revenue'] += parseNumericValue($slice['totals']['revenue'] ?? 0);
        $grouped[$key]['totals']['extra_expenses'] += parseNumericValue($slice['totals']['extra_expenses'] ?? 0);
        $grouped[$key]['__files'][] = (string) ($slice['__file'] ?? '');

        $cityLabel = normalizeSpaces((string) ($slice['city_label'] ?? ''));
        if ($cityLabel !== '') {
            $grouped[$key]['__cities'][$cityLabel] = $cityLabel;
        }
    }

    $result = [];
    foreach ($grouped as $item) {
        $item['summary_rows'] = aggregateSummaryRows($item['summary_rows']);
        $item['city_label'] = count($item['__cities']) <= 1 ? (string) reset($item['__cities']) : 'Все города';
        $item['__file'] = count($item['__files']) === 1
            ? (string) $item['__files'][0]
            : (count($item['__files']) . ' файла: ' . implode(', ', array_filter($item['__files'])));
        $result[] = $item;
    }

    usort($result, static function (array $left, array $right): int {
        return strcmp(($left['period_from'] ?? ''), ($right['period_from'] ?? ''));
    });

    return $result;
}

function renderSvgChart(array $points, string $metric): string
{
    if ($points === []) {
        return '';
    }

    $width = max(780, min(1380, 118 * count($points)));
    $height = 340;
    $left = 70;
    $right = 30;
    $top = 24;
    $bottom = 90;
    $plotWidth = $width - $left - $right;
    $plotHeight = $height - $top - $bottom;
    $values = array_map(static fn(array $point): float => (float) $point['value'], $points);
    $minValue = min($values);
    $maxValue = max($values);

    if ($minValue === $maxValue) {
        $minValue = min(0.0, $minValue);
        $maxValue = $maxValue + 1;
    }

    $coords = [];
    $count = count($points);

    foreach ($points as $index => $point) {
        $x = $left + ($count > 1 ? ($plotWidth * $index / ($count - 1)) : $plotWidth / 2);
        $ratio = ($point['value'] - $minValue) / ($maxValue - $minValue);
        $y = $top + $plotHeight - ($ratio * $plotHeight);
        $coords[] = ['x' => $x, 'y' => $y, 'label' => $point['label'], 'value' => $point['value']];
    }

    $polyline = implode(' ', array_map(static fn(array $point): string => number_format($point['x'], 2, '.', '') . ',' . number_format($point['y'], 2, '.', ''), $coords));
    $gridLines = 4;
    $svg = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . h(metricLabel($metric)) . '" xmlns="http://www.w3.org/2000/svg">';
    $svg .= '<rect x="0" y="0" width="' . $width . '" height="' . $height . '" fill="#ffffff"/>';

    for ($i = 0; $i <= $gridLines; $i++) {
        $y = $top + ($plotHeight * $i / $gridLines);
        $value = $maxValue - (($maxValue - $minValue) * $i / $gridLines);
        $svg .= '<line x1="' . $left . '" y1="' . number_format($y, 2, '.', '') . '" x2="' . ($width - $right) . '" y2="' . number_format($y, 2, '.', '') . '" stroke="rgba(28,39,49,0.12)" stroke-width="1"/>';
        $svg .= '<text x="' . ($left - 12) . '" y="' . number_format($y + 4, 2, '.', '') . '" text-anchor="end" font-size="12" fill="#586876">' . h(formatMetricValue($value, $metric)) . '</text>';
    }

    $svg .= '<polyline points="' . $polyline . '" fill="none" stroke="#0d6c8f" stroke-width="3"/>';

    foreach ($coords as $point) {
        $svg .= '<circle cx="' . number_format($point['x'], 2, '.', '') . '" cy="' . number_format($point['y'], 2, '.', '') . '" r="5" fill="#0d6c8f"/>';
        $svg .= '<text x="' . number_format($point['x'], 2, '.', '') . '" y="' . number_format($point['y'] - 12, 2, '.', '') . '" text-anchor="middle" font-size="12" fill="#1c2731">' . h(formatMetricValue($point['value'], $metric)) . '</text>';
        $svg .= '<text x="' . number_format($point['x'], 2, '.', '') . '" y="' . ($height - 40) . '" text-anchor="middle" font-size="12" fill="#586876">' . h($point['label']) . '</text>';
    }

    $svg .= '</svg>';

    return $svg;
}

function metricColor(string $metric): string
{
    return [
        'revenue' => '#0d6c8f',
        'sales_count' => '#1f7a48',
        'ad_spend' => '#c56a05',
        'contacts' => '#aa2e25',
        'views' => '#5d54a4',
        'impressions' => '#2e86ab',
        'roi' => '#198754',
        'roas' => '#6f42c1',
        'cpl' => '#fd7e14',
        'cpo' => '#dc3545',
        'ctr' => '#20a39e',
        'cr1' => '#5f0f40',
        'cr2' => '#3a86ff',
        'extra_expenses' => '#6c757d',
    ][$metric] ?? '#0d6c8f';
}

function renderSvgMultiMetricChart(array $pointsByMetric): string
{
    if ($pointsByMetric === []) {
        return '';
    }

    $periods = array_values(array_unique(array_map(static fn(array $point): string => (string) $point['label'], reset($pointsByMetric) ?: [])));
    $count = count($periods);
    if ($count === 0) {
        return '';
    }

    $width = max(860, min(1460, 124 * $count));
    $height = 450;
    $left = 70;
    $right = 30;
    $top = 24;
    $bottom = 86;
    $plotWidth = $width - $left - $right;
    $plotHeight = $height - $top - $bottom;
    $gridLines = 4;

    $svg = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="Все метрики" xmlns="http://www.w3.org/2000/svg">';
    $svg .= '<rect x="0" y="0" width="' . $width . '" height="' . $height . '" fill="#ffffff"/>';

    for ($i = 0; $i <= $gridLines; $i++) {
        $y = $top + ($plotHeight * $i / $gridLines);
        $labelValue = 100 - (100 * $i / $gridLines);
        $svg .= '<line x1="' . $left . '" y1="' . number_format($y, 2, '.', '') . '" x2="' . ($width - $right) . '" y2="' . number_format($y, 2, '.', '') . '" stroke="rgba(28,39,49,0.12)" stroke-width="1"/>';
        $svg .= '<text x="' . ($left - 12) . '" y="' . number_format($y + 4, 2, '.', '') . '" text-anchor="end" font-size="12" fill="#586876">' . h(number_format($labelValue, 0, ',', ' ') . '%') . '</text>';
    }

    foreach (array_keys($pointsByMetric) as $metricIndex => $metric) {
        $points = $pointsByMetric[$metric];
        $values = array_map(static fn(array $point): float => (float) $point['value'], $points);
        $maxValue = max($values);
        $coords = [];
        $labelOffsetPattern = [-14, -28, 16, 30];
        $labelOffsetY = $labelOffsetPattern[$metricIndex % count($labelOffsetPattern)];

        foreach ($points as $index => $point) {
            $x = $left + ($count > 1 ? ($plotWidth * $index / ($count - 1)) : $plotWidth / 2);
            $normalized = $maxValue > 0 ? ($point['value'] / $maxValue) * 100 : 0.0;
            $y = $top + $plotHeight - (($normalized / 100) * $plotHeight);
            $labelY = max($top + 12, min($top + $plotHeight - 6, $y + $labelOffsetY));
            $coords[] = [
                'x' => $x,
                'y' => $y,
                'label' => $point['label'],
                'value' => $point['value'],
                'label_y' => $labelY,
            ];
        }

        $polyline = implode(' ', array_map(static fn(array $point): string => number_format($point['x'], 2, '.', '') . ',' . number_format($point['y'], 2, '.', ''), $coords));
        $color = metricColor($metric);
        $svg .= '<polyline points="' . $polyline . '" fill="none" stroke="' . $color . '" stroke-width="3"/>';

        foreach ($coords as $point) {
            $svg .= '<circle cx="' . number_format($point['x'], 2, '.', '') . '" cy="' . number_format($point['y'], 2, '.', '') . '" r="4" fill="' . $color . '"/>';
            $svg .= '<text x="' . number_format($point['x'], 2, '.', '') . '" y="' . number_format($point['label_y'], 2, '.', '') . '" text-anchor="middle" font-size="10" font-weight="600" fill="' . $color . '" stroke="#ffffff" stroke-width="3" paint-order="stroke fill">' . h(formatMetricValue((float) $point['value'], $metric)) . '</text>';
        }
    }

    foreach ($periods as $index => $period) {
        $x = $left + ($count > 1 ? ($plotWidth * $index / ($count - 1)) : $plotWidth / 2);
        $svg .= '<text x="' . number_format($x, 2, '.', '') . '" y="' . ($height - 38) . '" text-anchor="middle" font-size="12" fill="#586876">' . h($period) . '</text>';
    }

    $svg .= '</svg>';

    return $svg;
}

$messages = [];
$sliceDirectory = resolveSliceDirectory();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'delete_slice') {
            $fileName = basename((string) ($_POST['file_name'] ?? ''));
            $filePath = $sliceDirectory . DIRECTORY_SEPARATOR . $fileName;

            if (!is_file($filePath)) {
                throw new RuntimeException('Срез для удаления не найден.');
            }

            unlink($filePath);
            $messages[] = ['type' => 'success', 'text' => 'Срез удален: ' . $fileName];
        }

        if ($action === 'save_edit') {
            $oldFileName = basename((string) ($_POST['old_file_name'] ?? ''));
            $cityLabel = normalizeSpaces((string) ($_POST['city_label'] ?? ''));
            $periodFrom = normalizeSpaces((string) ($_POST['period_from'] ?? ''));
            $periodTo = normalizeSpaces((string) ($_POST['period_to'] ?? ''));
            $sourceFilename = normalizeSpaces((string) ($_POST['source_filename'] ?? ''));
            $sourceItemCount = (int) parseNumericValue($_POST['source_item_count'] ?? 0);
            $directions = $_POST['direction'] ?? [];
            $impressions = $_POST['impressions'] ?? [];
            $views = $_POST['views'] ?? [];
            $contacts = $_POST['contacts'] ?? [];
            $adSpend = $_POST['ad_spend'] ?? [];
            $salesCount = $_POST['sales_count'] ?? [];
            $revenue = $_POST['revenue'] ?? [];
            $extraExpensesTotal = parseNumericValue($_POST['extra_expenses_total'] ?? 0);

            if ($cityLabel === '') {
                throw new RuntimeException('Укажите город.');
            }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodTo)) {
                throw new RuntimeException('Укажите корректные даты периода.');
            }

            $summaryRows = [];
            foreach ((array) $directions as $index => $direction) {
                $direction = normalizeSpaces((string) $direction);
                if ($direction === '') {
                    continue;
                }

                $summaryRows[] = [
                    'direction' => $direction,
                    'impressions' => parseNumericValue($impressions[$index] ?? 0),
                    'views' => parseNumericValue($views[$index] ?? 0),
                    'contacts' => parseNumericValue($contacts[$index] ?? 0),
                    'ad_spend' => parseNumericValue($adSpend[$index] ?? 0),
                    'sales_count' => parseNumericValue($salesCount[$index] ?? 0),
                    'revenue' => parseNumericValue($revenue[$index] ?? 0),
                    'extra_expenses' => parseNumericValue($extraExpenses[$index] ?? 0),
                ];
            }

            if ($summaryRows === []) {
                throw new RuntimeException('В срезе не осталось строк для сохранения.');
            }

            $distributedExtraExpenses = distributeExtraExpensesEvenly($extraExpensesTotal, count($summaryRows));

            foreach ($summaryRows as $index => $summaryRow) {
                $summaryRows[$index]['extra_expenses'] = $distributedExtraExpenses[$index] ?? 0.0;
            }

            $newFileName = buildSliceFileName($cityLabel, $periodFrom, $periodTo);
            $newPath = $sliceDirectory . DIRECTORY_SEPARATOR . $newFileName;
            $content = [
                'version' => 1,
                'city_label' => $cityLabel,
                'city_key' => transliterateForPath($cityLabel),
                'period_from' => $periodFrom,
                'period_to' => $periodTo,
                'saved_at' => date(DATE_ATOM),
                'source_filename' => $sourceFilename,
                'source_item_count' => $sourceItemCount,
                'summary_rows' => $summaryRows,
                'totals' => computeRawTotals($summaryRows),
            ];

            $json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new RuntimeException('Не удалось сериализовать JSON.');
            }

            if (file_put_contents($newPath, $json) === false) {
                throw new RuntimeException('Не удалось сохранить изменения.');
            }

            $oldPath = $sliceDirectory . DIRECTORY_SEPARATOR . $oldFileName;
            if ($oldFileName !== '' && $oldFileName !== $newFileName && is_file($oldPath)) {
                unlink($oldPath);
            }

            $messages[] = ['type' => 'success', 'text' => 'Срез сохранен: ' . $newFileName];
            $_GET['edit'] = $newFileName;
        }
    } catch (Throwable $e) {
        $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
    }
}

$allSlices = loadAllSlices($sliceDirectory);
$cities = availableCities($allSlices);
$selectedCity = normalizeSpaces((string) ($_GET['city'] ?? ''));
$metric = normalizeSpaces((string) ($_GET['metric'] ?? 'revenue'));
$direction = normalizeSpaces((string) ($_GET['direction'] ?? '__all__'));
$selectedMetrics = selectedMetricsFromRequest();
$selectedFiles = array_map(static fn($value): string => basename((string) $value), (array) ($_GET['slices'] ?? []));
$editFile = basename((string) ($_GET['edit'] ?? ''));
$directions = availableDirections($allSlices, $selectedCity);

$filteredSlices = array_values(array_filter($allSlices, static function (array $slice) use ($selectedCity): bool {
    return $selectedCity === '' || ($slice['city_label'] ?? '') === $selectedCity;
}));

$selectedSlices = $selectedFiles === []
    ? []
    : array_values(array_filter($filteredSlices, static function (array $slice) use ($selectedFiles): bool {
        return in_array($slice['__file'] ?? '', $selectedFiles, true);
    }));
$selectedSlices = aggregateSlicesByPeriod($selectedSlices);

$chartPoints = [];
foreach ($selectedSlices as $slice) {
    $chartPoints[] = [
        'label' => ($slice['period_from'] ?? '') . ' → ' . ($slice['period_to'] ?? ''),
        'value' => sliceValueForGraph($slice, $metric, $direction === '' ? '__all__' : $direction),
        'file_name' => $slice['__file'] ?? '',
    ];
}

$allMetricChart = [];
if ($metric === '__all__' && $selectedSlices !== []) {
    foreach ($selectedMetrics as $metricKey) {
        $allMetricChart[$metricKey] = [];
        foreach ($selectedSlices as $slice) {
            $allMetricChart[$metricKey][] = [
                'label' => ($slice['period_from'] ?? '') . ' → ' . ($slice['period_to'] ?? ''),
                'value' => sliceValueForGraph($slice, $metricKey, $direction === '' ? '__all__' : $direction),
                'file_name' => $slice['__file'] ?? '',
            ];
        }
    }
}

$editSlice = null;
if ($editFile !== '') {
    foreach ($allSlices as $slice) {
        if (($slice['__file'] ?? '') === $editFile) {
            $editSlice = $slice;
            break;
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>История срезов Avito</title>
    <style>
        :root {
            --bg: #eef3f6;
            --panel: rgba(255, 255, 255, 0.92);
            --text: #1c2731;
            --muted: #586876;
            --line: rgba(28, 39, 49, 0.12);
            --accent: #0d6c8f;
            --accent-dark: #0a4f68;
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

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 24px;
            box-shadow: var(--shadow);
            padding: 22px;
            margin-bottom: 18px;
            min-width: 0;
        }

        .hero h1,
        h2,
        h3 {
            margin: 0 0 12px;
        }

        .small {
            color: var(--muted);
            line-height: 1.55;
            font-size: 13px;
        }

        .toolbar,
        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: end;
        }

        .field {
            min-width: 180px;
            flex: 1 1 220px;
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

        input,
        select,
        button {
            width: 100%;
            border-radius: 14px;
            border: 1px solid var(--line);
            font: inherit;
        }

        input,
        select {
            padding: 12px 14px;
            background: #fff;
        }

        button,
        .button-link {
            padding: 13px 18px;
            color: #fff;
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            border: 0;
            cursor: pointer;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .button-link.secondary,
        .secondary {
            background: #fff;
            color: var(--text);
            border: 1px solid var(--line);
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

        .table-wrap {
            width: 100%;
            overflow: auto;
            border-radius: 18px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.85);
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
            background: #edf4f8;
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .chart-box {
            width: 100%;
            overflow: auto;
            border-radius: 18px;
            border: 1px solid var(--line);
            background: #fff;
        }

        .chart-box svg {
            display: block;
            min-width: 920px;
        }

        .legend-note {
            margin-top: 10px;
            color: var(--muted);
            font-size: 13px;
        }

        .metric-selector {
            margin-top: 14px;
            padding: 16px 18px;
            border: 1px solid var(--line);
            border-radius: 18px;
            background: rgba(248, 250, 252, 0.96);
        }

        .metric-selector-title {
            margin: 0 0 10px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .metric-selector-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 8px 14px;
        }

        .metric-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0;
            margin: 0;
            font-size: 13px;
            font-weight: 600;
            text-transform: none;
            letter-spacing: 0;
            color: var(--text);
        }

        .metric-option input {
            width: 16px;
            height: 16px;
            margin: 0;
            flex: 0 0 auto;
        }

        .metric-swatch {
            width: 16px;
            height: 4px;
            border-radius: 999px;
            flex: 0 0 auto;
        }

        .slice-row-actions {
            display: flex;
            gap: 8px;
        }

        .slice-row-actions a,
        .slice-row-actions button {
            width: auto;
            min-width: 0;
            padding: 9px 12px;
            border-radius: 10px;
            font-size: 12px;
        }

        .checkbox-cell {
            text-align: center;
        }

        .summary-edit-table input {
            width: 110px;
            min-width: 110px;
            padding: 8px 10px;
            border-radius: 10px;
            border: 1px solid var(--line);
            font: inherit;
        }

        .summary-edit-table input[readonly] {
            background: #f3f7fa;
            color: var(--muted);
        }

        .summary-edit-total td {
            font-weight: 700;
            background: rgba(13, 108, 143, 0.08);
        }

        @media (max-width: 760px) {
            .page {
                width: 100%;
                padding: 16px 12px 36px;
            }

            .panel {
                padding: 18px;
                border-radius: 20px;
            }
        }
    </style>
</head>
<body>
<div class="page">
    <section class="panel hero">
        <div class="toolbar">
            <div style="flex:1 1 420px;">
                <h1>История срезов и графики</h1>
                <p class="small">
                    Здесь хранятся сохраненные нижние таблицы. Можно фильтровать по городу, выбирать нужные диапазоны,
                    строить график по метрике и редактировать любой JSON-срез.
                </p>
            </div>
            <a class="button-link secondary" href="/local/avito_api/index.php">Назад к импорту</a>
        </div>
    </section>

    <?php if ($messages !== []): ?>
        <section class="panel">
            <div class="messages">
                <?php foreach ($messages as $message): ?>
                    <div class="message message-<?= h($message['type']) ?>"><?= h($message['text']) ?></div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="panel">
        <h2>Фильтр и график</h2>
        <form method="get" class="filters" id="filter-form">
            <div class="field">
                <label for="city">Город</label>
                <select id="city" name="city">
                    <option value="">Все города</option>
                    <?php foreach ($cities as $city): ?>
                        <option value="<?= h($city) ?>"<?= $city === $selectedCity ? ' selected' : '' ?>><?= h($city) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="metric">Метрика</label>
                <select id="metric" name="metric">
                    <option value="__all__"<?= $metric === '__all__' ? ' selected' : '' ?>>Все метрики</option>
                    <?php foreach (selectableMetrics() as $metricOption): ?>
                        <option value="<?= h($metricOption) ?>"<?= $metricOption === $metric ? ' selected' : '' ?>><?= h(metricLabel($metricOption)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="direction">Направление</label>
                <select id="direction" name="direction">
                    <option value="__all__">Все направления</option>
                    <?php foreach ($directions as $directionOption): ?>
                        <option value="<?= h($directionOption) ?>"<?= $directionOption === $direction ? ' selected' : '' ?>><?= h($directionOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="flex:0 0 220px;">
                <button type="submit">Применить фильтр</button>
            </div>
            <?php if ($metric === '__all__'): ?>
                <div class="metric-selector" style="flex:1 1 100%;">
                    <div class="metric-selector-title">Легенда и метрики графика</div>
                    <div class="metric-selector-grid">
                        <?php foreach (selectableMetrics() as $metricOption): ?>
                            <label class="metric-option">
                                <input type="checkbox" name="metrics[]" value="<?= h($metricOption) ?>"<?= in_array($metricOption, $selectedMetrics, true) ? ' checked' : '' ?>>
                                <span class="metric-swatch" style="background: <?= h(metricColor($metricOption)) ?>"></span>
                                <span><?= h(metricLabel($metricOption)) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <div id="selected-slices-hidden"></div>
        </form>
    </section>

    <?php if (($metric === '__all__' && $allMetricChart !== []) || ($metric !== '__all__' && $chartPoints !== [])): ?>
        <section class="panel">
            <h2>График по выбранным диапазонам</h2>
            <p class="small">
                Метрика: <strong><?= h(metricLabel($metric)) ?></strong>.
                Направление: <strong><?= h($direction === '__all__' ? 'Все направления' : $direction) ?></strong>.
            </p>
            <div class="chart-box">
                <?php if ($metric === '__all__'): ?>
                    <?= renderSvgMultiMetricChart($allMetricChart) ?>
                <?php else: ?>
                    <?= renderSvgChart($chartPoints, $metric) ?>
                <?php endif; ?>
            </div>
            <?php if ($metric === '__all__'): ?>
                <div class="legend-note">В режиме "Все метрики" линии нормализованы относительно собственного максимума каждой метрики, чтобы их можно было читать на одном графике.</div>
            <?php endif; ?>
            <div class="table-wrap" style="margin-top:14px;">
                <table>
                    <thead>
                    <tr>
                        <th>Период</th>
                        <?php if ($metric === '__all__'): ?>
                            <?php foreach ($selectedMetrics as $metricOption): ?>
                                <th><?= h(metricLabel($metricOption)) ?></th>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <th>Значение</th>
                        <?php endif; ?>
                        <th>Файл</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($metric === '__all__'): ?>
                        <?php foreach ($selectedSlices as $slice): ?>
                            <tr>
                                <td><?= h(($slice['period_from'] ?? '') . ' → ' . ($slice['period_to'] ?? '')) ?></td>
                                <?php foreach ($selectedMetrics as $metricOption): ?>
                                    <td><?= h(formatMetricValue(sliceValueForGraph($slice, $metricOption, $direction === '' ? '__all__' : $direction), $metricOption)) ?></td>
                                <?php endforeach; ?>
                                <td><?= h($slice['__file'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php foreach ($chartPoints as $point): ?>
                            <tr>
                                <td><?= h($point['label']) ?></td>
                                <td><?= h(formatMetricValue((float) $point['value'], $metric)) ?></td>
                                <td><?= h($point['file_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($editSlice !== null): ?>
        <section class="panel">
            <h2>Редактирование среза</h2>
            <p class="small">
                В форме ниже показана вся сводка. Базовые поля редактируются, а дополнительные расходы задаются одной общей суммой внизу и автоматически распределяются по строкам.
            </p>
            <form method="post">
                <input type="hidden" name="action" value="save_edit">
                <input type="hidden" name="old_file_name" value="<?= h($editSlice['__file'] ?? '') ?>">
                <div class="filters" style="margin-bottom:14px;">
                    <div class="field">
                        <label>Город</label>
                        <input type="text" name="city_label" value="<?= h($editSlice['city_label'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label>Период от</label>
                        <input type="date" name="period_from" value="<?= h($editSlice['period_from'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label>Период до</label>
                        <input type="date" name="period_to" value="<?= h($editSlice['period_to'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label>Исходный файл</label>
                        <input type="text" name="source_filename" value="<?= h($editSlice['source_filename'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label>Количество объявлений</label>
                        <input type="number" min="0" step="1" name="source_item_count" value="<?= h((string) ($editSlice['source_item_count'] ?? 0)) ?>">
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="summary-edit-table" id="edit-summary-table">
                        <thead>
                        <tr>
                            <th>Направление</th>
                            <th>Показы</th>
                            <th>CTR</th>
                            <th>Просмотры</th>
                            <th>CR1</th>
                            <th>Контакты</th>
                            <th>CPL</th>
                            <th>CR2</th>
                            <th>Количество продаж</th>
                            <th>CPO</th>
                            <th>Выручка</th>
                            <th>ROI</th>
                            <th>ROAS</th>
                            <th>Расходы на рекламу</th>
                            <th>Расходы дополнительные</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (($editSlice['summary_rows'] ?? []) as $row): ?>
                            <tr class="edit-summary-row">
                                <td><input type="text" name="direction[]" value="<?= h($row['direction'] ?? '') ?>"></td>
                                <td><input type="number" min="0" step="0.01" name="impressions[]" data-edit-manual="impressions" value="<?= h((string) ($row['impressions'] ?? 0)) ?>"></td>
                                <td data-edit-field="ctr"><?= h(formatMetricValue(metricValueFromRaw($row, 'ctr'), 'ctr')) ?></td>
                                <td><input type="number" min="0" step="0.01" name="views[]" data-edit-manual="views" value="<?= h((string) ($row['views'] ?? 0)) ?>"></td>
                                <td data-edit-field="cr1"><?= h(formatMetricValue(metricValueFromRaw($row, 'cr1'), 'cr1')) ?></td>
                                <td><input type="number" min="0" step="0.01" name="contacts[]" data-edit-manual="contacts" value="<?= h((string) ($row['contacts'] ?? 0)) ?>"></td>
                                <td data-edit-field="cpl"><?= h(formatMetricValue(metricValueFromRaw($row, 'cpl'), 'cpl')) ?></td>
                                <td data-edit-field="cr2"><?= h(formatMetricValue(metricValueFromRaw($row, 'cr2'), 'cr2')) ?></td>
                                <td><input type="number" min="0" step="0.01" name="sales_count[]" value="<?= h((string) ($row['sales_count'] ?? 0)) ?>"></td>
                                <td data-edit-field="cpo"><?= h(formatMetricValue(metricValueFromRaw($row, 'cpo'), 'cpo')) ?></td>
                                <td><input type="number" min="0" step="0.01" name="revenue[]" value="<?= h((string) ($row['revenue'] ?? 0)) ?>"></td>
                                <td data-edit-field="roi"><?= h(formatMetricValue(metricValueFromRaw($row, 'roi'), 'roi')) ?></td>
                                <td data-edit-field="roas"><?= h(formatMetricValue(metricValueFromRaw($row, 'roas'), 'roas')) ?></td>
                                <td><input type="number" min="0" step="0.01" name="ad_spend[]" data-edit-manual="ad_spend" value="<?= h((string) ($row['ad_spend'] ?? 0)) ?>"></td>
                                <td><input type="number" min="0" step="0.01" name="extra_expenses[]" value="<?= h((string) ($row['extra_expenses'] ?? 0)) ?>" readonly></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="summary-edit-total">
                            <td>ИТОГО</td>
                            <td data-edit-total="impressions"></td>
                            <td data-edit-total="ctr"></td>
                            <td data-edit-total="views"></td>
                            <td data-edit-total="cr1"></td>
                            <td data-edit-total="contacts"></td>
                            <td data-edit-total="cpl"></td>
                            <td data-edit-total="cr2"></td>
                            <td data-edit-total="sales_count"></td>
                            <td data-edit-total="cpo"></td>
                            <td data-edit-total="revenue"></td>
                            <td data-edit-total="roi"></td>
                            <td data-edit-total="roas"></td>
                            <td data-edit-total="ad_spend"></td>
                            <td><input type="number" min="0" step="0.01" name="extra_expenses_total" value="<?= h((string) parseNumericValue($editSlice['totals']['extra_expenses'] ?? 0)) ?>"></td>
                        </tr>
                        </tbody>
                    </table>
                </div>
                <div class="toolbar" style="margin-top:14px;">
                    <button type="submit">Сохранить JSON</button>
                    <a class="button-link secondary" href="/local/avito_api/history.php?city=<?= urlencode($selectedCity) ?>">Закрыть редактирование</a>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <section class="panel">
        <div class="toolbar">
            <div style="flex:1 1 320px;">
                <h2>Список сохраненных срезов</h2>
                <p class="small">
                    Отмечайте нужные диапазоны и нажимайте кнопку построения графика. Диапазоны берутся из имени и данных JSON-среза.
                </p>
            </div>
            <button type="button" id="build-graph-button">Построить график по выбранным</button>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Выбрать</th>
                    <th>Период</th>
                    <th>Город</th>
                    <th>Файл</th>
                    <th>Показы</th>
                    <th>Контакты</th>
                    <th>Расходы</th>
                    <th>Выручка</th>
                    <th>Продажи</th>
                    <th>Действия</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($filteredSlices === []): ?>
                    <tr>
                        <td colspan="10">Для выбранного фильтра срезы не найдены.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($filteredSlices as $slice): ?>
                        <tr>
                            <td class="checkbox-cell">
                                <input
                                    class="slice-checkbox"
                                    type="checkbox"
                                    value="<?= h($slice['__file'] ?? '') ?>"
                                    <?= in_array($slice['__file'] ?? '', $selectedFiles, true) ? 'checked' : '' ?>
                                >
                            </td>
                            <td><?= h(($slice['period_from'] ?? '') . ' → ' . ($slice['period_to'] ?? '')) ?></td>
                            <td><?= h($slice['city_label'] ?? '') ?></td>
                            <td><?= h($slice['__file'] ?? '') ?></td>
                            <td><?= h(number_format(parseNumericValue($slice['totals']['impressions'] ?? 0), 0, ',', ' ')) ?></td>
                            <td><?= h(number_format(parseNumericValue($slice['totals']['contacts'] ?? 0), 0, ',', ' ')) ?></td>
                            <td><?= h(formatMetricValue(parseNumericValue($slice['totals']['ad_spend'] ?? 0), 'ad_spend')) ?></td>
                            <td><?= h(formatMetricValue(parseNumericValue($slice['totals']['revenue'] ?? 0), 'revenue')) ?></td>
                            <td><?= h(number_format(parseNumericValue($slice['totals']['sales_count'] ?? 0), 0, ',', ' ')) ?></td>
                            <td>
                                <div class="slice-row-actions">
                                    <a class="button-link secondary" href="/local/avito_api/history.php?city=<?= urlencode($selectedCity) ?>&metric=<?= urlencode($metric) ?>&direction=<?= urlencode($direction) ?>&edit=<?= urlencode((string) ($slice['__file'] ?? '')) ?>">Редактировать</a>
                                    <form method="post" onsubmit="return confirm('Удалить этот JSON-срез?');">
                                        <input type="hidden" name="action" value="delete_slice">
                                        <input type="hidden" name="file_name" value="<?= h($slice['__file'] ?? '') ?>">
                                        <button type="submit" class="secondary">Удалить</button>
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
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const graphButton = document.getElementById('build-graph-button');
    const filterForm = document.getElementById('filter-form');
    const hiddenContainer = document.getElementById('selected-slices-hidden');
    const editSummaryTable = document.getElementById('edit-summary-table');

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

    function distributeExtraExpenses(total, count) {
        if (!Number.isFinite(total) || count <= 0) {
            return [];
        }

        const totalCents = Math.round(total * 100);
        const base = Math.floor(totalCents / count);
        const remainder = totalCents - (base * count);
        const values = [];

        for (let index = 0; index < count; index += 1) {
            const cents = base + (index < remainder ? 1 : 0);
            values.push(cents / 100);
        }

        return values;
    }

    function recalculateEditSummary() {
        if (!editSummaryTable) {
            return;
        }

        const rows = Array.from(editSummaryTable.querySelectorAll('tbody tr.edit-summary-row'));
        const totalExtraExpenses = parseNumber(editSummaryTable.querySelector('[name="extra_expenses_total"]')?.value);
        const distributedExtraExpenses = distributeExtraExpenses(totalExtraExpenses, rows.length);
        const totals = {
            impressions: 0,
            views: 0,
            contacts: 0,
            sales_count: 0,
            revenue: 0,
            ad_spend: 0,
            extra_expenses: 0
        };

        rows.forEach(function (row, index) {
            const impressions = parseNumber(row.querySelector('[name="impressions[]"]')?.value);
            const views = parseNumber(row.querySelector('[name="views[]"]')?.value);
            const contacts = parseNumber(row.querySelector('[name="contacts[]"]')?.value);
            const salesCount = parseNumber(row.querySelector('[name="sales_count[]"]')?.value);
            const revenue = parseNumber(row.querySelector('[name="revenue[]"]')?.value);
            const adSpend = parseNumber(row.querySelector('[name="ad_spend[]"]')?.value);
            const extraExpenses = distributedExtraExpenses[index] || 0;
            const totalExpenses = adSpend + extraExpenses;
            const roi = totalExpenses > 0 ? ((revenue - totalExpenses) / totalExpenses) * 100 : NaN;
            const roas = adSpend > 0 ? revenue / adSpend : NaN;
            const extraExpensesInput = row.querySelector('[name="extra_expenses[]"]');

            if (extraExpensesInput instanceof HTMLInputElement) {
                extraExpensesInput.value = extraExpenses > 0 ? extraExpenses.toFixed(2) : '0';
            }

            totals.impressions += impressions;
            totals.views += views;
            totals.contacts += contacts;
            totals.sales_count += salesCount;
            totals.revenue += revenue;
            totals.ad_spend += adSpend;
            totals.extra_expenses += extraExpenses;

            const values = {
                ctr: ratioPercent(views, impressions),
                cr1: ratioPercent(contacts, views),
                cpl: unitCost(adSpend + extraExpenses, contacts),
                cr2: ratioPercent(salesCount, contacts),
                cpo: unitCost(adSpend, salesCount),
                roi: Number.isFinite(roi) ? formatPercent(roi) : 'н/д',
                roas: Number.isFinite(roas) ? formatDecimal(roas) : 'н/д'
            };

            Object.keys(values).forEach(function (field) {
                const cell = row.querySelector('[data-edit-field="' + field + '"]');
                if (cell) {
                    cell.textContent = values[field];
                }
            });
        });

        const totalValues = {
            impressions: formatInteger(totals.impressions),
            ctr: ratioPercent(totals.views, totals.impressions),
            views: formatInteger(totals.views),
            cr1: ratioPercent(totals.contacts, totals.views),
            contacts: formatInteger(totals.contacts),
            cpl: unitCost(totals.ad_spend + totals.extra_expenses, totals.contacts),
            cr2: ratioPercent(totals.sales_count, totals.contacts),
            sales_count: formatInteger(totals.sales_count),
            cpo: unitCost(totals.ad_spend, totals.sales_count),
            revenue: formatMoney(totals.revenue),
            roi: Number.isFinite(((totals.revenue - (totals.ad_spend + totals.extra_expenses)) / (totals.ad_spend + totals.extra_expenses)) * 100)
                && (totals.ad_spend + totals.extra_expenses) > 0
                ? formatPercent(((totals.revenue - (totals.ad_spend + totals.extra_expenses)) / (totals.ad_spend + totals.extra_expenses)) * 100)
                : 'н/д',
            roas: totals.ad_spend > 0 ? formatDecimal(totals.revenue / totals.ad_spend) : 'н/д',
            ad_spend: formatMoney(totals.ad_spend),
            extra_expenses: formatMoney(totals.extra_expenses)
        };

        Object.keys(totalValues).forEach(function (field) {
            const cell = editSummaryTable.querySelector('[data-edit-total="' + field + '"]');
            if (cell) {
                cell.textContent = totalValues[field];
            }
        });
    }

    if (editSummaryTable) {
        editSummaryTable.addEventListener('input', function (event) {
            const target = event.target;
            if (!(target instanceof HTMLInputElement)) {
                return;
            }

            recalculateEditSummary();
        });

        recalculateEditSummary();
    }

    function syncSelectedSlices() {
        if (!hiddenContainer) {
            return;
        }

        hiddenContainer.innerHTML = '';
        document.querySelectorAll('.slice-checkbox:checked').forEach(function (checkbox) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'slices[]';
            input.value = checkbox.value;
            hiddenContainer.appendChild(input);
        });
    }

    if (filterForm) {
        filterForm.addEventListener('submit', function () {
            syncSelectedSlices();
        });
    }

    if (!graphButton) {
        return;
    }

    graphButton.addEventListener('click', function () {
        if (!filterForm) {
            return;
        }

        syncSelectedSlices();
        filterForm.requestSubmit();
    });
});
</script>
</body>
</html>
