<?php

final class SimplePdfDocument {
    private const PAGE_WIDTH = 595.28;
    private const PAGE_HEIGHT = 841.89;

    private float $marginLeft = 50;
    private float $marginRight = 50;
    private float $marginTop = 50;
    private float $marginBottom = 50;

    /** @var list<string> */
    private array $pageStreams = [];
    private int $currentPage = -1;
    private float $cursorY = 0;

    public function addBlankLine(float $height = 8): void {
        $this->ensureSpace($height);
        $this->cursorY -= $height;
    }

    public function addHeading(string $text, int $level = 1): void {
        $sizes = [1 => 16, 2 => 13, 3 => 11, 4 => 10];
        $size = $sizes[$level] ?? 10;
        $this->addWrappedText($text, $size, true, 4);
        $this->addBlankLine(4);
    }

    public function addParagraph(string $text, float $fontSize = 10): void {
        $this->addWrappedText($text, $fontSize, false, 2);
        $this->addBlankLine(2);
    }

    public function addBullet(string $text, float $fontSize = 10): void {
        $this->addWrappedText('- ' . $text, $fontSize, false, 2, 12);
    }

    public function addCodeLine(string $text, float $fontSize = 8): void {
        $this->addWrappedText($text, $fontSize, false, 1, 8);
    }

    public function output(): string {
        if ($this->currentPage < 0) {
            $this->startPage();
        }

        $pageCount = count($this->pageStreams);
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $nextObject = 3;

        $fontRegularObject = $nextObject++;
        $objects[$fontRegularObject] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $fontBoldObject = $nextObject++;
        $objects[$fontBoldObject] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

        $pageObjectIds = [];
        foreach ($this->pageStreams as $index => $stream) {
            $contentObject = $nextObject++;
            $pageObject = $nextObject++;
            $pageObjectIds[] = $pageObject;

            $streamLength = strlen($stream);
            $objects[$contentObject] = "<< /Length {$streamLength} >>\nstream\n{$stream}\nendstream";
            $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '
                . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT . '] /Resources << /Font << /F1 '
                . $fontRegularObject . ' 0 R /F2 ' . $fontBoldObject . ' 0 R >> >> /Contents '
                . $contentObject . ' 0 R >>';
        }

        $kidsRefs = implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageObjectIds));
        $objects[2] = '<< /Type /Pages /Kids [' . $kidsRefs . '] /Count ' . $pageCount . ' >>';

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        $objectNumber = 1;
        foreach ($objects as $content) {
            $offsets[$objectNumber] = strlen($pdf);
            $pdf .= $objectNumber . " 0 obj\n" . $content . "\nendobj\n";
            $objectNumber++;
        }

        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . count($offsets) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < count($offsets); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . count($offsets) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefPos}\n%%EOF";

        return $pdf;
    }

    private function startPage(): void {
        $this->currentPage++;
        $this->pageStreams[$this->currentPage] = '';
        $this->cursorY = self::PAGE_HEIGHT - $this->marginTop;
    }

    private function ensureSpace(float $height): void {
        if ($this->currentPage < 0) {
            $this->startPage();
        }
        if ($this->cursorY - $height < $this->marginBottom) {
            $this->startPage();
        }
    }

    private function addWrappedText(
        string $text,
        float $fontSize,
        bool $bold,
        float $gapAfter,
        float $indent = 0
    ): void {
        $text = $this->sanitizeText($text);
        if ($text === '') {
            return;
        }

        $maxWidth = self::PAGE_WIDTH - $this->marginLeft - $this->marginRight - $indent;
        $maxChars = max(20, (int) floor($maxWidth / ($fontSize * 0.52)));
        foreach ($this->wrapText($text, $maxChars) as $line) {
            $this->ensureSpace($fontSize + 2);
            $x = $this->marginLeft + $indent;
            $font = $bold ? '/F2' : '/F1';
            $y = $this->cursorY;
            $this->pageStreams[$this->currentPage] .= "BT {$font} {$fontSize} Tf {$x} {$y} Td ("
                . $this->escape($line) . ") Tj ET\n";
            $this->cursorY -= max($fontSize + 2, $gapAfter + $fontSize);
        }
    }

    /** @return list<string> */
    private function wrapText(string $text, int $maxChars): array {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? trim($text);
        if ($text === '') {
            return [];
        }
        if (strlen($text) <= $maxChars) {
            return [$text];
        }

        $lines = [];
        while ($text !== '') {
            if (strlen($text) <= $maxChars) {
                $lines[] = $text;
                break;
            }

            $slice = substr($text, 0, $maxChars + 1);
            $breakAt = strrpos($slice, ' ');
            if ($breakAt === false || $breakAt < (int) ($maxChars * 0.4)) {
                $breakAt = $maxChars;
            }

            $lines[] = trim(substr($text, 0, $breakAt));
            $text = ltrim(substr($text, $breakAt));
        }

        return $lines;
    }

    private function sanitizeText(string $text): string {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $replacements = [
            '—' => '-',
            '–' => '-',
            '“' => '"',
            '”' => '"',
            '‘' => "'",
            '’' => "'",
            '…' => '...',
            '₱' => 'PHP',
        ];
        $text = strtr($text, $replacements);
        $text = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $text) ?? $text;

        return trim($text);
    }

    private function escape(string $text): string {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
