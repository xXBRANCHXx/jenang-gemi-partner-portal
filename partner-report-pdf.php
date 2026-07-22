<?php
declare(strict_types=1);

final class JGPartnerPdfDocument
{
    private const WIDTH = 595.28;
    private const HEIGHT = 841.89;
    private array $pages = [[]];
    private int $page = 0;
    private string $title;
    private array $images = [];

    public function __construct(string $title)
    {
        $this->title = $title;
    }

    public function addPage(): void
    {
        $this->pages[] = [];
        $this->page = count($this->pages) - 1;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function setPage(int $page): void
    {
        if (isset($this->pages[$page])) {
            $this->page = $page;
        }
    }

    private function command(string $command): void
    {
        $this->pages[$this->page][] = $command;
    }

    private function color(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return implode(' ', array_map(
            static fn (string $part): string => rtrim(rtrim(sprintf('%.4F', hexdec($part) / 255), '0'), '.'),
            str_split(str_pad($hex, 6, '0'), 2)
        ));
    }

    private function pdfY(float $top): float
    {
        return self::HEIGHT - $top;
    }

    public function rect(float $x, float $top, float $width, float $height, string $fill, ?string $stroke = null, float $lineWidth = 1): void
    {
        $y = self::HEIGHT - $top - $height;
        $paint = $stroke === null ? 'f' : 'B';
        $strokeCommand = $stroke === null ? '' : $this->color($stroke) . " RG {$lineWidth} w ";
        $this->command(sprintf("q %s%s rg %.2F %.2F %.2F %.2F re %s Q", $strokeCommand, $this->color($fill), $x, $y, $width, $height, $paint));
    }

    public function roundedRect(float $x, float $top, float $width, float $height, float $radius, string $fill, ?string $stroke = null, float $lineWidth = 1): void
    {
        $left = $x;
        $right = $x + $width;
        $bottom = self::HEIGHT - $top - $height;
        $upper = $bottom + $height;
        $radius = min($radius, $width / 2, $height / 2);
        $control = $radius * 0.55228475;
        $strokeCommand = $stroke === null ? '' : $this->color($stroke) . " RG {$lineWidth} w ";
        $paint = $stroke === null ? 'f' : 'B';
        $path = sprintf(
            '%.2F %.2F m %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c h',
            $left + $radius, $bottom,
            $right - $radius, $bottom,
            $right - $radius + $control, $bottom, $right, $bottom + $radius - $control, $right, $bottom + $radius,
            $right, $upper - $radius,
            $right, $upper - $radius + $control, $right - $radius + $control, $upper, $right - $radius, $upper,
            $left + $radius, $upper,
            $left + $radius - $control, $upper, $left, $upper - $radius + $control, $left, $upper - $radius,
            $left, $bottom + $radius,
            $left, $bottom + $radius - $control, $left + $radius - $control, $bottom, $left + $radius, $bottom
        );
        $this->command(sprintf('q %s%s rg %s %s Q', $strokeCommand, $this->color($fill), $path, $paint));
    }

    public function line(float $x1, float $top1, float $x2, float $top2, string $color, float $width = 1): void
    {
        $this->command(sprintf(
            'q %s RG %.2F w %.2F %.2F m %.2F %.2F l S Q',
            $this->color($color),
            $width,
            $x1,
            $this->pdfY($top1),
            $x2,
            $this->pdfY($top2)
        ));
    }

    public function textWidth(string $text, float $size, bool $bold = false): float
    {
        $width = 0.0;
        foreach (str_split($this->encode($text)) as $character) {
            if ($character === ' ') $factor = 0.28;
            elseif (ctype_digit($character)) $factor = 0.55;
            elseif (ctype_upper($character)) $factor = 0.62;
            elseif (str_contains('ilI.,:;|!', $character)) $factor = 0.26;
            elseif (str_contains('mwMW@', $character)) $factor = 0.82;
            else $factor = 0.51;
            $width += $factor + ($bold ? 0.025 : 0);
        }
        return $width * $size;
    }

    private function encode(string $text): string
    {
        $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        $encoded = is_string($encoded) ? $encoded : $text;
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $encoded) ?? '';
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $this->encode($text));
    }

    public function text(float $x, float $top, string $text, float $size = 10, bool $bold = false, string $color = '#17202a', string $align = 'left', ?float $boxWidth = null): void
    {
        if ($align !== 'left' && $boxWidth !== null) {
            $textWidth = $this->textWidth($text, $size, $bold);
            if ($align === 'right') $x += max(0, $boxWidth - $textWidth);
            if ($align === 'center') $x += max(0, ($boxWidth - $textWidth) / 2);
        }
        $font = $bold ? '/F2' : '/F1';
        $this->command(sprintf(
            'BT %s rg %s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET',
            $this->color($color),
            $font,
            $size,
            $x,
            $this->pdfY($top),
            $this->escape($text)
        ));
    }

    public function wrappedText(float $x, float $top, string $text, float $width, float $size = 9, bool $bold = false, string $color = '#43505d', float $lineHeight = 13, int $maxLines = 0): float
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if ($line !== '' && $this->textWidth($candidate, $size, $bold) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') $lines[] = $line;
        if ($maxLines > 0 && count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $last = rtrim($lines[$maxLines - 1], '.,;:') . '...';
            while ($this->textWidth($last, $size, $bold) > $width && mb_strlen($last) > 4) {
                $last = mb_substr($last, 0, -4) . '...';
            }
            $lines[$maxLines - 1] = $last;
        }
        foreach ($lines as $index => $value) {
            $this->text($x, $top + ($index * $lineHeight), $value, $size, $bold, $color);
        }
        return max($lineHeight, count($lines) * $lineHeight);
    }

    public function watermark(string $text): void
    {
        $encoded = $this->escape($text);
        $this->command(sprintf(
            'q 0.92 0.94 0.92 rg 0.7071 0.7071 -0.7071 0.7071 115 270 cm BT /F2 46 Tf 0 0 Td (%s) Tj ET Q',
            $encoded
        ));
    }

    public function jpeg(float $x, float $top, float $width, float $height, string $data, int $pixelWidth, int $pixelHeight): void
    {
        $hash = sha1($data);
        if (!isset($this->images[$hash])) {
            $this->images[$hash] = [
                'name' => 'Im' . (count($this->images) + 1),
                'data' => $data,
                'width' => $pixelWidth,
                'height' => $pixelHeight,
            ];
        }
        $name = $this->images[$hash]['name'];
        $y = self::HEIGHT - $top - $height;
        $this->command(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q', $width, $height, $x, $y, $name));
    }

    public function addFooters(string $leftLabel, string $pageLabel): void
    {
        $activePage = $this->page;
        $total = count($this->pages);
        foreach (array_keys($this->pages) as $page) {
            $this->page = $page;
            $this->line(44, 804, self::WIDTH - 44, 804, '#d9e0df', 0.6);
            $this->text(44, 822, $leftLabel, 7.5, true, '#687673');
            $this->text(self::WIDTH - 164, 822, sprintf('%s %d / %d', $pageLabel, $page + 1, $total), 7.5, true, '#687673', 'right', 120);
        }
        $this->page = $activePage;
    }

    public function output(): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $firstImageObject = 5 + (count($this->pages) * 2);
        $imageObjects = [];
        foreach (array_values($this->images) as $index => $image) {
            $imageObjects[$image['name']] = $firstImageObject + $index;
        }
        $imageResources = '';
        foreach ($imageObjects as $name => $objectId) {
            $imageResources .= '/' . $name . ' ' . $objectId . ' 0 R ';
        }
        foreach ($this->pages as $index => $commands) {
            $pageObject = 5 + ($index * 2);
            $contentObject = $pageObject + 1;
            $kids[] = $pageObject . ' 0 R';
            $stream = implode("\n", $commands) . "\n";
            $objects[$pageObject] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << %s >> >> /Contents %d 0 R >>',
                self::WIDTH,
                self::HEIGHT,
                $imageResources,
                $contentObject
            );
            $objects[$contentObject] = "<< /Length " . strlen($stream) . ">>\nstream\n" . $stream . 'endstream';
        }
        $objects[2] = '<< /Type /Pages /Count ' . count($this->pages) . ' /Kids [' . implode(' ', $kids) . '] >>';
        foreach (array_values($this->images) as $index => $image) {
            $objectId = $firstImageObject + $index;
            $objects[$objectId] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
                $image['width'],
                $image['height'],
                strlen($image['data']),
                $image['data']
            );
        }
        $infoObject = max(array_keys($objects)) + 1;
        $objects[$infoObject] = sprintf(
            '<< /Title (%s) /Author (Jenang Gemi Partner Portal) /Creator (Jenang Gemi Report Engine) /CreationDate (D:%s) >>',
            $this->escape($this->title),
            gmdate('YmdHis') . 'Z'
        );
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $max; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info {$infoObject} 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
        return $pdf;
    }
}

function jg_partner_report_profile_initials(string $name): string
{
    $words = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
    if ($words === []) return 'P';
    $first = mb_substr($words[0], 0, 1);
    $last = count($words) > 1 ? mb_substr($words[array_key_last($words)], 0, 1) : '';
    return mb_strtoupper($first . $last);
}

function jg_partner_report_fallback_accent(string $name): string
{
    $palette = ['#486485', '#77596e', '#8a6049', '#3f6d6a', '#71644a', '#54627a'];
    return $palette[abs((int) crc32($name)) % count($palette)];
}

/** @return array{data:string,width:int,height:int,accent:string}|null */
function jg_partner_report_icon_asset(?string $path): ?array
{
    if ($path === null || !is_file($path) || !function_exists('imagecreatefromstring')) return null;
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') return null;
    if (substr($raw, 0, 4) === "\x00\x00\x01\x00" && strlen($raw) >= 22) {
        $entry = unpack('Vsize/Voffset', substr($raw, 14, 8));
        $size = (int) ($entry['size'] ?? 0);
        $offset = (int) ($entry['offset'] ?? 0);
        if ($size > 0 && $offset >= 0 && $offset + $size <= strlen($raw)) {
            $raw = substr($raw, $offset, $size);
        }
    }
    $source = @imagecreatefromstring($raw);
    if (!$source instanceof GdImage) return null;
    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    if ($sourceWidth <= 0 || $sourceHeight <= 0) {
        return null;
    }

    $sampleR = 0;
    $sampleG = 0;
    $sampleB = 0;
    $samples = 0;
    $stepX = max(1, intdiv($sourceWidth, 24));
    $stepY = max(1, intdiv($sourceHeight, 24));
    for ($y = 0; $y < $sourceHeight; $y += $stepY) {
        for ($x = 0; $x < $sourceWidth; $x += $stepX) {
            $index = imagecolorat($source, $x, $y);
            $rgba = imagecolorsforindex($source, $index);
            if (($rgba['alpha'] ?? 0) > 90) continue;
            $brightness = (int) $rgba['red'] + (int) $rgba['green'] + (int) $rgba['blue'];
            $spread = max($rgba['red'], $rgba['green'], $rgba['blue']) - min($rgba['red'], $rgba['green'], $rgba['blue']);
            if ($brightness > 700 || $brightness < 55 || $spread < 18) continue;
            $sampleR += (int) $rgba['red'];
            $sampleG += (int) $rgba['green'];
            $sampleB += (int) $rgba['blue'];
            $samples++;
        }
    }
    $base = [72, 96, 133];
    $average = $samples > 0
        ? [$sampleR / $samples, $sampleG / $samples, $sampleB / $samples]
        : $base;
    $accent = array_map(
        static fn (float $value, int $index): int => max(35, min(175, (int) round(($value * 0.68) + ($base[$index] * 0.32)))),
        $average,
        array_keys($base)
    );

    $canvasSize = 256;
    $canvas = imagecreatetruecolor($canvasSize, $canvasSize);
    $background = imagecolorallocate($canvas, 248, 247, 244);
    imagefill($canvas, 0, 0, $background);
    imagealphablending($canvas, true);
    $padding = 16;
    $scale = min(($canvasSize - ($padding * 2)) / $sourceWidth, ($canvasSize - ($padding * 2)) / $sourceHeight);
    $drawWidth = max(1, (int) round($sourceWidth * $scale));
    $drawHeight = max(1, (int) round($sourceHeight * $scale));
    imagecopyresampled(
        $canvas,
        $source,
        (int) (($canvasSize - $drawWidth) / 2),
        (int) (($canvasSize - $drawHeight) / 2),
        0,
        0,
        $drawWidth,
        $drawHeight,
        $sourceWidth,
        $sourceHeight
    );
    ob_start();
    imagejpeg($canvas, null, 92);
    $jpeg = ob_get_clean();
    if (!is_string($jpeg) || $jpeg === '') return null;
    return [
        'data' => $jpeg,
        'width' => $canvasSize,
        'height' => $canvasSize,
        'accent' => sprintf('#%02x%02x%02x', $accent[0], $accent[1], $accent[2]),
    ];
}

function jg_partner_report_dictionary(string $language): array
{
    $en = [
        'report_title' => 'Partner Performance Report',
        'confidential' => 'INTERNAL RECORD',
        'reporting_period' => 'Reporting period',
        'generated' => 'Generated',
        'timezone' => 'Time zone',
        'document_ref' => 'Document reference',
        'orders' => 'Sales orders',
        'units' => 'Units sold',
        'partner_cost' => 'Partner cost',
        'average_order' => 'Average units / order',
        'summary' => 'Executive summary',
        'summary_copy' => 'This report consolidates partner sales activity for the selected period. Cancelled orders remain visible in status and ledger records but are excluded from units sold and partner cost.',
        'status' => 'Order status',
        'listed' => 'Listed',
        'accepted' => 'Accepted',
        'fulfilled' => 'Fulfilled',
        'cancelled' => 'Cancelled',
        'channels' => 'Sales channel performance',
        'channel' => 'Channel',
        'share' => 'Share',
        'products' => 'Product mix',
        'product' => 'Product',
        'product_snapshot' => 'Product portfolio snapshot',
        'top_product' => 'Top product by units',
        'product_lines' => 'Product lines sold',
        'top_three_share' => 'Top 3 unit share',
        'order_ledger' => 'Detailed order ledger',
        'date' => 'Date',
        'order_id' => 'Order ID',
        'customer' => 'Customer',
        'order_customer' => 'Order / customer',
        'unassigned' => 'Unassigned',
        'cost' => 'Cost',
        'no_data' => 'No orders were recorded in this reporting period.',
        'notes' => 'Report notes',
        'notes_copy' => 'Figures are based on records available in the Partner Portal when this document was generated. Units and partner cost exclude cancelled orders. Amounts are stated in Indonesian rupiah (IDR).',
        'notes_copy_sample' => 'This approval document uses fictional orders and customers. Calculations, section flow, pagination, and formatting are identical to a live Partner Portal report.',
        'prepared_for' => 'Prepared for',
        'page' => 'PAGE',
        'portal_attribution' => 'Prepared through Jenang Gemi Partner Portal',
        'dataset' => 'Dataset',
        'dataset_live' => 'PORTAL RECORDS',
        'dataset_sample' => 'SAMPLE DATA - NOT FOR ACCOUNTING',
        'active_sales' => 'non-cancelled sales orders',
    ];
    $id = [
        'report_title' => 'Laporan Kinerja Mitra',
        'confidential' => 'DOKUMEN INTERNAL',
        'reporting_period' => 'Periode laporan',
        'generated' => 'Dibuat',
        'timezone' => 'Zona waktu',
        'document_ref' => 'Referensi dokumen',
        'orders' => 'Pesanan penjualan',
        'units' => 'Unit terjual',
        'partner_cost' => 'Biaya mitra',
        'average_order' => 'Rata-rata unit / pesanan',
        'summary' => 'Ringkasan eksekutif',
        'summary_copy' => 'Laporan ini merangkum aktivitas penjualan mitra pada periode terpilih. Pesanan yang dibatalkan tetap tercantum pada status dan rincian pesanan, tetapi tidak dihitung dalam unit terjual dan biaya mitra.',
        'status' => 'Status pesanan',
        'listed' => 'Terdaftar',
        'accepted' => 'Diterima',
        'fulfilled' => 'Dipenuhi',
        'cancelled' => 'Dibatalkan',
        'channels' => 'Kinerja kanal penjualan',
        'channel' => 'Kanal',
        'share' => 'Porsi',
        'products' => 'Komposisi produk',
        'product' => 'Produk',
        'product_snapshot' => 'Ringkasan portofolio produk',
        'top_product' => 'Produk teratas berdasarkan unit',
        'product_lines' => 'Lini produk terjual',
        'top_three_share' => 'Porsi unit 3 produk teratas',
        'order_ledger' => 'Rincian pesanan',
        'date' => 'Tanggal',
        'order_id' => 'ID Pesanan',
        'customer' => 'Pelanggan',
        'order_customer' => 'Pesanan / pelanggan',
        'unassigned' => 'Belum ditetapkan',
        'cost' => 'Biaya',
        'no_data' => 'Tidak ada pesanan yang tercatat pada periode laporan ini.',
        'notes' => 'Catatan laporan',
        'notes_copy' => 'Angka berdasarkan data yang tersedia di Portal Mitra saat dokumen dibuat. Unit dan biaya mitra tidak termasuk pesanan yang dibatalkan. Seluruh nilai dinyatakan dalam rupiah Indonesia (IDR).',
        'notes_copy_sample' => 'Dokumen persetujuan ini menggunakan pesanan dan pelanggan fiktif. Perhitungan, alur bagian, pembagian halaman, dan format sama dengan laporan Portal Mitra yang sebenarnya.',
        'prepared_for' => 'Disiapkan untuk',
        'page' => 'HALAMAN',
        'portal_attribution' => 'Disiapkan melalui Portal Mitra Jenang Gemi',
        'dataset' => 'Dataset',
        'dataset_live' => 'DATA PORTAL',
        'dataset_sample' => 'DATA CONTOH - BUKAN UNTUK PEMBUKUAN',
        'active_sales' => 'pesanan penjualan yang tidak dibatalkan',
    ];
    return $language === 'id' ? $id : $en;
}

function jg_partner_report_date(mixed $value, DateTimeZone $timezone): ?DateTimeImmutable
{
    $raw = trim((string) $value);
    if ($raw === '') return null;
    try {
        return (new DateTimeImmutable($raw))->setTimezone($timezone);
    } catch (Throwable) {
        return null;
    }
}

function jg_partner_report_status_kind(array $order): string
{
    $status = strtoupper(trim((string) ($order['status'] ?? 'IS_LISTED')));
    if (in_array($status, ['IS_BEING_FULFILLED', 'PROCESSING'], true)) return 'accepted';
    if (in_array($status, ['FULFILLED', 'COMPLETED', 'SHIPPED'], true)) return 'fulfilled';
    if (in_array($status, ['CANCELLED', 'CANCELED'], true)) return 'cancelled';
    return 'listed';
}

function jg_partner_report_order_units(array $order): int
{
    $items = array_values(array_filter((array) ($order['items'] ?? []), 'is_array'));
    if ($items === []) return max(0, (int) ($order['quantity'] ?? 0));
    return array_sum(array_map(static fn (array $item): int => max(0, (int) ($item['quantity'] ?? 0)), $items));
}

function jg_partner_report_order_cost(array $order): float
{
    $stored = (float) ($order['revenue_total'] ?? 0);
    if ($stored > 0) return $stored;
    return array_reduce((array) ($order['items'] ?? []), static function (float $sum, mixed $item): float {
        if (!is_array($item)) return $sum;
        $quantity = max(0, (int) ($item['quantity'] ?? 0));
        return $sum + (float) ($item['line_revenue'] ?? ((float) ($item['unit_revenue'] ?? $item['partner_price'] ?? 0) * $quantity));
    }, 0.0);
}

function jg_partner_report_aggregate(array $orders, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeZone $timezone): array
{
    $filtered = [];
    foreach ($orders as $order) {
        if (!is_array($order)) continue;
        $timestamp = jg_partner_report_date($order['order_timestamp'] ?? $order['created_at'] ?? '', $timezone);
        if ($timestamp === null || $timestamp < $start || $timestamp >= $end) continue;
        $order['_report_time'] = $timestamp;
        $filtered[] = $order;
    }
    usort($filtered, static fn (array $left, array $right): int => ($left['_report_time'] <=> $right['_report_time']));

    $status = ['listed' => 0, 'accepted' => 0, 'fulfilled' => 0, 'cancelled' => 0];
    $channels = [];
    $products = [];
    $units = 0;
    $cost = 0.0;
    $salesOrders = 0;
    foreach ($filtered as $order) {
        $kind = jg_partner_report_status_kind($order);
        $status[$kind]++;
        $cancelled = $kind === 'cancelled';
        $orderUnits = jg_partner_report_order_units($order);
        $orderCost = jg_partner_report_order_cost($order);
        $channel = trim((string) ($order['marketplace_platform'] ?? '')) ?: 'Unassigned';
        $channels[$channel] ??= ['name' => $channel, 'orders' => 0, 'units' => 0, 'cost' => 0.0, 'cancelled' => 0];
        $channels[$channel]['orders']++;
        if ($cancelled) {
            $channels[$channel]['cancelled']++;
            continue;
        }
        $salesOrders++;
        $units += $orderUnits;
        $cost += $orderCost;
        $channels[$channel]['units'] += $orderUnits;
        $channels[$channel]['cost'] += $orderCost;
        foreach ((array) ($order['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $nameParts = array_values(array_filter([
                trim((string) ($item['product'] ?? $item['sku_label'] ?? $item['sku_code'] ?? 'Product')),
                trim((string) ($item['flavor'] ?? '')),
                trim((string) ($item['size'] ?? '')),
            ]));
            $name = implode(' - ', $nameParts) ?: 'Product';
            $quantity = max(0, (int) ($item['quantity'] ?? 0));
            $lineCost = (float) ($item['line_revenue'] ?? ((float) ($item['unit_revenue'] ?? $item['partner_price'] ?? 0) * $quantity));
            $products[$name] ??= ['name' => $name, 'units' => 0, 'cost' => 0.0];
            $products[$name]['units'] += $quantity;
            $products[$name]['cost'] += $lineCost;
        }
    }
    uasort($channels, static fn (array $left, array $right): int => ($right['cost'] <=> $left['cost']) ?: ($right['units'] <=> $left['units']));
    uasort($products, static fn (array $left, array $right): int => ($right['units'] <=> $left['units']) ?: strcmp($left['name'], $right['name']));
    return [
        'orders' => $filtered,
        'sales_orders' => $salesOrders,
        'units' => $units,
        'cost' => $cost,
        'average' => $salesOrders > 0 ? $units / $salesOrders : 0,
        'status' => $status,
        'channels' => array_values($channels),
        'products' => array_values($products),
    ];
}

function jg_partner_report_currency(float $value, string $language): string
{
    $formatted = number_format($value, 0, $language === 'id' ? ',' : '.', $language === 'id' ? '.' : ',');
    return $language === 'id' ? 'Rp ' . $formatted : 'IDR ' . $formatted;
}

function jg_partner_report_date_label(DateTimeImmutable $date, string $language, bool $includeTime = false): string
{
    $months = $language === 'id'
        ? [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des']
        : [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $label = $language === 'id'
        ? $date->format('j') . ' ' . $months[(int) $date->format('n')] . ' ' . $date->format('Y')
        : $months[(int) $date->format('n')] . ' ' . $date->format('j, Y');
    return $includeTime ? $label . '  ' . $date->format('H:i') : $label;
}

function jg_partner_report_draw_profile_mark(JGPartnerPdfDocument $pdf, array $data, float $x, float $top, float $size): void
{
    $pdf->roundedRect($x, $top, $size, $size, $size * 0.22, '#ffffff', '#d8d8d3', 0.7);
    $asset = $data['icon_asset'] ?? null;
    if (is_array($asset) && is_string($asset['data'] ?? null)) {
        $inset = $size * 0.12;
        $pdf->jpeg($x + $inset, $top + $inset, $size - ($inset * 2), $size - ($inset * 2), $asset['data'], (int) $asset['width'], (int) $asset['height']);
        return;
    }
    $pdf->roundedRect($x + 3, $top + 3, $size - 6, $size - 6, $size * 0.18, $data['accent']);
    $fontSize = $size * (mb_strlen($data['initials']) > 1 ? 0.30 : 0.38);
    $pdf->text($x, $top + ($size * 0.62), $data['initials'], $fontSize, true, '#ffffff', 'center', $size);
}

function jg_partner_report_header(JGPartnerPdfDocument $pdf, array $data, array $copy, bool $firstPage): float
{
    if ($firstPage) {
        $pdf->rect(0, 0, 595.28, 152, '#f4f2ed');
        $pdf->rect(0, 0, 7, 152, $data['accent']);
        $pdf->rect(0, 149, 595.28, 3, $data['accent']);
        jg_partner_report_draw_profile_mark($pdf, $data, 44, 31, 58);
        $pdf->text(118, 42, strtoupper($copy['report_title']), 7.4, true, $data['accent']);
        $nameSize = mb_strlen($data['partner_name']) > 34 ? 17 : (mb_strlen($data['partner_name']) > 24 ? 19.5 : 22);
        $pdf->text(118, 70, (string) $data['partner_name'], $nameSize, true, '#202938');
        $pdf->text(118, 93, $copy['reporting_period'] . '  /  ' . $data['period_label'], 8.7, false, '#59616d');
        $pdf->roundedRect(418, 31, 133, 26, 13, '#ffffff', '#d7d7d1', 0.7);
        $datasetSize = mb_strlen($data['dataset_label']) > 22 ? 5.6 : 6.9;
        $pdf->text(418, 48, $data['dataset_label'], $datasetSize, true, '#4d5664', 'center', 133);
        $pdf->text(418, 78, $copy['document_ref'], 6.5, true, '#7b8188');
        $pdf->text(418, 93, $data['document_ref'], 8.5, true, '#252e3c');
        $pdf->text(418, 113, $data['timezone_label'], 6.7, false, '#6c737c');
        $pdf->line(44, 123, 551, 123, '#d9d8d2', 0.6);
        $pdf->text(44, 139, $copy['portal_attribution'], 6.6, false, '#858883');
        return 176;
    }
    $pdf->rect(0, 0, 595.28, 70, '#f4f2ed');
    $pdf->rect(0, 0, 5, 70, $data['accent']);
    $pdf->rect(0, 68, 595.28, 2, $data['accent']);
    jg_partner_report_draw_profile_mark($pdf, $data, 44, 15, 38);
    $pdf->text(94, 29, $data['partner_name'], 10.2, true, '#202938');
    $pdf->text(94, 45, strtoupper($copy['report_title']), 6.5, true, $data['accent']);
    $pdf->text(378, 29, $data['period_label'], 7.3, true, '#3f4855', 'right', 173);
    $pdf->text(378, 45, $data['document_ref'], 6.5, false, '#747a82', 'right', 173);
    return 92;
}

function jg_partner_report_section_title(JGPartnerPdfDocument $pdf, float $y, string $eyebrow, string $title): float
{
    $pdf->text(44, $y, strtoupper($eyebrow), 7.2, true, '#747b83');
    $pdf->text(44, $y + 19, $title, 15.5, true, '#202938');
    $pdf->line(44, $y + 31, 551, $y + 31, '#deded9', 0.7);
    return $y + 47;
}

function jg_partner_report_new_page(JGPartnerPdfDocument $pdf, array $data, array $copy, bool $sample): float
{
    $pdf->addPage();
    return jg_partner_report_header($pdf, $data, $copy, false);
}

function jg_partner_report_render(array $partner, array $orders, array $options): string
{
    $language = ($options['language'] ?? 'id') === 'en' ? 'en' : 'id';
    $copy = jg_partner_report_dictionary($language);
    $timezone = new DateTimeZone((string) ($options['timezone'] ?? 'Asia/Jakarta'));
    $start = $options['start'] instanceof DateTimeImmutable ? $options['start'] : new DateTimeImmutable('first day of this month', $timezone);
    $end = $options['end'] instanceof DateTimeImmutable ? $options['end'] : new DateTimeImmutable('tomorrow', $timezone);
    $endLabel = $end->modify('-1 day');
    $sample = !empty($options['sample']);
    $sections = array_values(array_intersect((array) ($options['sections'] ?? ['channels', 'products', 'orders']), ['channels', 'products', 'orders']));
    $summary = jg_partner_report_aggregate($orders, $start, $end, $timezone);
    $generated = isset($options['generated_at']) && $options['generated_at'] instanceof DateTimeImmutable
        ? $options['generated_at']->setTimezone($timezone)
        : new DateTimeImmutable('now', $timezone);
    $period = jg_partner_report_date_label($start, $language) . ' - ' . jg_partner_report_date_label($endLabel, $language);
    $reference = strtoupper((string) ($options['document_ref'] ?? ('PR-' . $start->format('Ymd') . '-' . $endLabel->format('Ymd') . '-' . substr(sha1((string) ($partner['name'] ?? 'Partner') . $generated->format(DATE_ATOM)), 0, 6))));
    $partnerName = trim((string) ($partner['name'] ?? 'Partner')) ?: 'Partner';
    $iconAsset = jg_partner_report_icon_asset(isset($options['icon_path']) ? (string) $options['icon_path'] : null);
    $data = [
        'partner_name' => $partnerName,
        'period_label' => $period,
        'document_ref' => $reference,
        'timezone_label' => $copy['timezone'] . ': ' . $timezone->getName(),
        'initials' => jg_partner_report_profile_initials($partnerName),
        'icon_asset' => $iconAsset,
        'accent' => (string) ($iconAsset['accent'] ?? jg_partner_report_fallback_accent($partnerName)),
        'dataset_label' => $sample ? $copy['dataset_sample'] : $copy['dataset_live'],
    ];

    $pdf = new JGPartnerPdfDocument($data['partner_name'] . ' - ' . $copy['report_title']);
    $y = jg_partner_report_header($pdf, $data, $copy, true);

    $cards = [
        [$copy['orders'], number_format($summary['sales_orders']), $copy['active_sales']],
        [$copy['units'], number_format($summary['units']), $copy['orders']],
        [$copy['partner_cost'], jg_partner_report_currency($summary['cost'], $language), 'IDR'],
        [$copy['average_order'], number_format($summary['average'], 1), $copy['units']],
    ];
    $cardWidth = 121.5;
    foreach ($cards as $index => [$label, $value, $caption]) {
        $x = 44 + ($index * ($cardWidth + 7));
        $pdf->roundedRect($x, $y, $cardWidth, 77, 9, '#f6f5f2', '#deded9', 0.7);
        $pdf->text($x + 12, $y + 18, strtoupper($label), 6.7, true, '#737980');
        $valueSize = mb_strlen($value) > 15 ? 11.5 : (mb_strlen($value) > 10 ? 13.5 : 19);
        $pdf->text($x + 12, $y + 45, $value, $valueSize, true, '#202938');
        $pdf->text($x + 12, $y + 64, $caption, 6.6, false, '#8a8d91');
    }
    $y += 101;

    $pdf->roundedRect(44, $y, 507, 74, 10, '#252f40');
    $pdf->text(60, $y + 21, strtoupper($copy['summary']), 7.2, true, $data['accent']);
    $pdf->wrappedText(60, $y + 41, $copy['summary_copy'], 474, 8.3, false, '#eef0f3', 12, 3);
    $y += 97;

    $y = jg_partner_report_section_title($pdf, $y, $copy['summary'], $copy['status']);
    $totalStatuses = max(1, array_sum($summary['status']));
    $statusColors = ['listed' => '#4f82cc', 'accepted' => '#36a86d', 'fulfilled' => '#86b947', 'cancelled' => '#d35d61'];
    foreach (['listed', 'accepted', 'fulfilled', 'cancelled'] as $index => $kind) {
        $x = 44 + (($index % 2) * 257);
        $rowY = $y + (intdiv($index, 2) * 38);
        $count = (int) $summary['status'][$kind];
        $ratio = $count / $totalStatuses;
        $pdf->text($x, $rowY + 9, $copy[$kind], 8.2, true, '#3a424d');
        $pdf->text($x + 222, $rowY + 9, (string) $count, 8.2, true, '#3a424d', 'right', 28);
        $pdf->roundedRect($x, $rowY + 18, 250, 7, 3.5, '#e9e9e6');
        if ($count > 0) $pdf->roundedRect($x, $rowY + 18, max(7, 250 * $ratio), 7, 3.5, $statusColors[$kind]);
    }
    $y += 92;

    if (in_array('channels', $sections, true)) {
        if ($y > 620) $y = jg_partner_report_new_page($pdf, $data, $copy, $sample);
        $y = jg_partner_report_section_title($pdf, $y, $copy['summary'], $copy['channels']);
        $pdf->rect(44, $y, 507, 25, '#ececea');
        $pdf->text(54, $y + 16, strtoupper($copy['channel']), 6.7, true, '#59616b');
        $pdf->text(297, $y + 16, strtoupper($copy['orders']), 6.7, true, '#59616b', 'right', 55);
        $pdf->text(364, $y + 16, strtoupper($copy['units']), 6.7, true, '#59616b', 'right', 46);
        $pdf->text(420, $y + 16, strtoupper($copy['partner_cost']), 6.7, true, '#59616b', 'right', 121);
        $y += 25;
        $channels = array_slice($summary['channels'], 0, 7);
        if ($channels === []) {
            $pdf->wrappedText(54, $y + 20, $copy['no_data'], 480, 9, false, '#66756f');
            $y += 48;
        } else {
            foreach ($channels as $channel) {
                $pdf->line(44, $y + 31, 551, $y + 31, '#e6e6e3', 0.6);
                $pdf->wrappedText(54, $y + 13, (string) $channel['name'], 210, 8.2, true, '#2d3541', 10, 1);
                $pdf->text(297, $y + 15, (string) $channel['orders'], 8.2, false, '#47505b', 'right', 55);
                $pdf->text(364, $y + 15, (string) $channel['units'], 8.2, false, '#47505b', 'right', 46);
                $pdf->text(420, $y + 15, jg_partner_report_currency((float) $channel['cost'], $language), 8.2, true, '#2d3541', 'right', 121);
                $y += 32;
            }
        }
        $y += 22;
    }

    if (in_array('products', $sections, true)) {
        if ($y > 600) $y = jg_partner_report_new_page($pdf, $data, $copy, $sample);
        $y = jg_partner_report_section_title($pdf, $y, $copy['summary'], $copy['products']);
        $products = array_slice($summary['products'], 0, 8);
        $maxUnits = max(1, ...array_map(static fn (array $product): int => (int) $product['units'], $products ?: [['units' => 1]]));
        if ($products === []) {
            $pdf->wrappedText(44, $y + 10, $copy['no_data'], 507, 9, false, '#66756f');
            $y += 42;
        } else {
            foreach ($products as $product) {
                if ($y > 742) $y = jg_partner_report_new_page($pdf, $data, $copy, $sample);
                $pdf->wrappedText(44, $y + 10, (string) $product['name'], 285, 8.1, true, '#2d3541', 10, 1);
                $pdf->text(335, $y + 10, number_format((int) $product['units']) . ' ' . strtolower($copy['units']), 7.8, true, '#5d6670');
                $pdf->text(430, $y + 10, jg_partner_report_currency((float) $product['cost'], $language), 7.8, true, '#2d3541', 'right', 121);
                $pdf->roundedRect(44, $y + 19, 507, 5, 2.5, '#e9e9e6');
                $pdf->roundedRect(44, $y + 19, max(5, 507 * ((int) $product['units'] / $maxUnits)), 5, 2.5, $data['accent']);
                $y += 34;
            }
        }
        $y += 18;
        if ($products !== []) {
            $y = jg_partner_report_section_title($pdf, $y, $copy['products'], $copy['product_snapshot']);
            $topThreeUnits = array_sum(array_map(static fn (array $product): int => (int) $product['units'], array_slice($products, 0, 3)));
            $snapshot = [
                [$copy['top_product'], (string) $products[0]['name'], $data['accent']],
                [$copy['product_lines'], number_format(count($summary['products'])), '#737d8b'],
                [$copy['top_three_share'], number_format($summary['units'] > 0 ? ($topThreeUnits / $summary['units']) * 100 : 0, 0) . '%', '#9a8068'],
            ];
            foreach ($snapshot as $index => [$label, $value, $accent]) {
                $cardWidth = 164;
                $x = 44 + ($index * 171.5);
                $pdf->roundedRect($x, $y, $cardWidth, 78, 9, '#f6f5f2', '#deded9', 0.7);
                $pdf->rect($x, $y, 4, 78, $accent);
                $pdf->text($x + 14, $y + 19, strtoupper($label), 6.4, true, '#737980');
                if ($index === 0) {
                    $pdf->wrappedText($x + 14, $y + 43, $value, 136, 9.2, true, '#202938', 11, 2);
                } else {
                    $pdf->text($x + 14, $y + 54, $value, 20, true, '#202938');
                }
            }
            $y += 100;
        }
    }

    if (in_array('orders', $sections, true)) {
        $y = jg_partner_report_new_page($pdf, $data, $copy, $sample);
        $y = jg_partner_report_section_title($pdf, $y, $copy['report_title'], $copy['order_ledger']);
        $drawLedgerHeader = static function (JGPartnerPdfDocument $pdf, float $top) use ($copy): float {
            $pdf->rect(44, $top, 507, 27, '#263244');
            $headers = [
                [50, 72, $copy['date']],
                [124, 100, $copy['order_customer']],
                [228, 91, $copy['channel']],
                [323, 73, $copy['status']],
                [399, 42, $copy['units']],
                [444, 101, $copy['cost']],
            ];
            foreach ($headers as [$x, $width, $label]) {
                $pdf->text($x, $top + 17, strtoupper($label), 6.2, true, '#e9f2ef', $label === $copy['cost'] ? 'right' : 'left', $width);
            }
            return $top + 27;
        };
        $y = $drawLedgerHeader($pdf, $y);
        if ($summary['orders'] === []) {
            $pdf->wrappedText(54, $y + 24, $copy['no_data'], 480, 9, false, '#66756f');
            $y += 58;
        } else {
            foreach ($summary['orders'] as $index => $order) {
                if ($y > 746) {
                    $y = jg_partner_report_new_page($pdf, $data, $copy, $sample);
                    $y = jg_partner_report_section_title($pdf, $y, $copy['report_title'], $copy['order_ledger']);
                    $y = $drawLedgerHeader($pdf, $y);
                }
                $kind = jg_partner_report_status_kind($order);
                $rowFill = $index % 2 === 0 ? '#f5f7f6' : '#ffffff';
                $pdf->rect(44, $y, 507, 36, $rowFill);
                $date = $order['_report_time'];
                $pdf->text(50, $y + 21, jg_partner_report_date_label($date, $language), 6.5, false, '#43534e');
                $pdf->wrappedText(124, $y + 14, (string) ($order['id'] ?? ''), 96, 6.7, true, '#243832', 8, 1);
                $pdf->wrappedText(124, $y + 26, trim((string) ($order['customer_name'] ?? '')) ?: '-', 96, 5.9, false, '#70807a', 7, 1);
                $platformName = trim((string) ($order['marketplace_platform'] ?? '')) ?: $copy['unassigned'];
                $pdf->wrappedText(228, $y + 20, $platformName, 87, 6.7, false, '#43534e', 8, 1);
                $pdf->text(323, $y + 21, $copy[$kind], 6.7, true, $statusColors[$kind]);
                $pdf->text(399, $y + 21, (string) jg_partner_report_order_units($order), 6.7, true, '#243832', 'right', 42);
                $ledgerCost = $kind === 'cancelled' ? 0.0 : jg_partner_report_order_cost($order);
                $pdf->text(444, $y + 21, jg_partner_report_currency($ledgerCost, $language), 6.7, true, '#243832', 'right', 101);
                $y += 36;
            }
        }
        $y += 24;
    }

    if ($y > 700) $y = jg_partner_report_new_page($pdf, $data, $copy, $sample);
    $y = jg_partner_report_section_title($pdf, $y, $copy['generated'], $copy['notes']);
    $pdf->roundedRect(44, $y, 507, 74, 9, '#f6f5f2', '#deded9', 0.7);
    $notesCopy = $sample ? $copy['notes_copy_sample'] : $copy['notes_copy'];
    $pdf->wrappedText(58, $y + 22, $notesCopy, 475, 8.2, false, '#555d67', 12, 4);
    $pdf->text(58, $y + 60, $copy['generated'] . ': ' . jg_partner_report_date_label($generated, $language, true) . '  /  ' . $copy['dataset'] . ': ' . $data['dataset_label'], 6.9, true, '#777c82');

    $footerPartner = mb_substr($data['partner_name'], 0, 34);
    $pdf->addFooters(strtoupper($footerPartner . '  /  ' . $copy['confidential']), $copy['page']);
    return $pdf->output();
}
