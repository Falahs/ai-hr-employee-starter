<?php

namespace App\Services\Documents;

/**
 * فحص كل ملف يدخل من خارج النظام (بوابة الوظائف ومرفقات الإيميل) قبل ما ينحفظ أو يقرأه أي وكيل.
 *
 * النوع يُحكم من محتوى الملف لا من اسمه، وبعدها لكل نوع فحصه:
 * - PDF: يُرفض لو فيه JavaScript أو تشغيل تلقائي أو ملفات مضمّنة أو نماذج XFA، أو كان مشفّرًا.
 * - Word (docx): يُرفض لو فيه ماكرو، أو كائنات مضمّنة، أو روابط خارجية (قالب أو صورة تُجلب من الإنترنت
 *   لحظة فتح الملف)، أو مسار يطلع خارج الأرشيف، أو كان «قنبلة ضغط».
 * - الصور: يُرفض لو أبعادها أكبر من الحد (قنبلة فك ضغط)، أو نوعها الحقيقي غير اللي تدّعيه. والصورة
 *   المقبولة يُعاد ترميزها، فيسقط منها أي محتوى مدسوس وبيانات الموقع (EXIF).
 * - اسم الملف يُنظَّف: بلا مسار، ولا محارف تحكّم، ولا محارف عكس الاتجاه اللي تخفي الامتداد الحقيقي.
 *
 * فحص واحد للكل: ما فيه مدخل للملفات يتجاوزه.
 */
class UploadGuard
{
    public const PDF = 'application/pdf';

    public const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public const IMAGES = ['image/jpeg', 'image/png', 'image/webp'];

    /** A CV, or a phone photo of one, is well under this. nginx cuts anything much bigger before PHP sees it. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Object streams one PDF may have, and how much they may inflate to in all, before it counts as hidden. */
    public const MAX_OBJECT_STREAMS = 200;

    public const MAX_INFLATED = 40 * 1024 * 1024;

    /** A real CV's document.xml is well under a few MB; far beyond that is a decompression bomb. */
    public const MAX_DOCX_UNPACKED = 40 * 1024 * 1024;

    public const MAX_DOCX_ENTRIES = 400;

    public const MAX_IMAGE_PIXELS = 40_000_000;

    public const MAX_IMAGE_SIDE = 12_000;

    /** Names inside a PDF that run code, act on opening, carry other files or submit data. */
    private const PDF_ACTIVE = ['/JavaScript', '/JS', '/Launch', '/EmbeddedFile', '/EmbeddedFiles', '/RichMedia', '/XFA',
        '/SubmitForm', '/ImportData', '/GoToR', '/GoToE', '/AA', '/Sound', '/Movie'];

    /**
     * @return array{mime: string, reason: null}|array{mime: ?string, reason: string}
     *         the real type, and why the file is refused (null when it passes)
     */
    public function inspect(string $path, ?string $claimedMime = null): array
    {
        $size = @filesize($path);
        if (! $size) {
            return ['mime' => null, 'reason' => 'empty'];
        }
        if ($size > self::MAX_BYTES) {
            return ['mime' => null, 'reason' => 'too_large'];
        }

        $mime = $this->sniff($path);
        if ($mime === null) {
            return ['mime' => null, 'reason' => 'type'];
        }

        $reason = match (true) {
            $mime === self::PDF => $this->pdf($path),
            $mime === self::DOCX => $this->docx($path),
            default => $this->image($path, $mime),
        };

        return ['mime' => $mime, 'reason' => $reason];
    }

    /** The type from the first bytes, for the four types the reader reads; anything else is null. */
    public function sniff(string $path): ?string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 16);

        return match (true) {
            str_starts_with($head, '%PDF-') => self::PDF,
            str_starts_with($head, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($head, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($head, "PK\x03\x04") && $this->isDocx($path) => self::DOCX,
            default => null,
        };
    }

    /**
     * The PDF's object structure is scanned, never the bytes of its pictures and fonts: binary data matches
     * «/JS» by chance often enough to refuse real CVs. So stream bodies are cut out, and only object
     * streams (/Type /ObjStm, where dictionaries hide compressed) are inflated and scanned as well.
     * Encrypted files are refused: their insides cannot be checked.
     */
    private function pdf(string $path): ?string
    {
        $raw = (string) file_get_contents($path);
        $structure = '';
        $objects = [];
        $inflatedTotal = 0;
        $at = 0;
        while (preg_match('#stream\r?\n#', $raw, $m, PREG_OFFSET_CAPTURE, $at)) {
            $start = $m[0][1];
            $bodyStart = $start + strlen($m[0][0]);
            $end = strpos($raw, 'endstream', $bodyStart);
            if ($end === false) {
                break;
            }
            $before = substr($raw, $at, $start - $at);
            $structure .= $before;
            // the stream's own dictionary is the text just before the keyword
            $dict = substr($before, (int) strrpos($before, 'obj'));
            if (preg_match('#/Type\s*/ObjStm#', $dict)) {
                if (count($objects) >= self::MAX_OBJECT_STREAMS) {
                    return 'pdf_hidden';
                }
                $decoded = $this->decodeStream(rtrim(substr($raw, $bodyStart, $end - $bodyStart), "\r\n"), $dict, self::MAX_INFLATED - $inflatedTotal);
                if ($decoded === null) {
                    // compressed in a way we cannot open: what it hides cannot be checked, so it is not accepted
                    return 'pdf_hidden';
                }
                $inflatedTotal += strlen($decoded);
                $objects[] = $decoded;
            }
            $at = $end + 9;
        }
        $structure .= substr($raw, $at);

        if (preg_match('#/Encrypt\b#', $structure)) {
            return 'pdf_encrypted';
        }
        foreach ([$structure, ...$objects] as $c) {
            // names may be written with #xx escapes (/J#61vaScript): decode them before matching
            $c = preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn ($h) => chr(hexdec($h[1])), $c);
            foreach (self::PDF_ACTIVE as $name) {
                if (preg_match('#'.preg_quote($name, '#').'(?![A-Za-z])#', $c)) {
                    return 'pdf_active';
                }
            }
        }

        return null;
    }

    /**
     * A stream body through its /Filter chain («/FlateDecode», or «[/ASCIIHexDecode /FlateDecode]» to slip past
     * a scanner that only inflates). Null for a filter we do not decode, or when the budget runs out.
     */
    private function decodeStream(string $body, string $dict, int $budget): ?string
    {
        $filters = [];
        if (preg_match('#/Filter\s*\[([^\]]*)\]#', $dict, $m)) {
            preg_match_all('#/(\w+)#', $m[1], $f);
            $filters = $f[1];
        } elseif (preg_match('#/Filter\s*/(\w+)#', $dict, $m)) {
            $filters = [$m[1]];
        }
        foreach ($filters as $filter) {
            if ($budget <= 0) {
                return null;
            }
            $body = match ($filter) {
                'FlateDecode', 'Fl' => @gzuncompress($body, $budget) ?: @gzinflate($body, $budget),
                'ASCIIHexDecode', 'AHx' => @hex2bin((string) preg_replace('/[^0-9A-Fa-f]/', '', strstr($body, '>', true) ?: $body)),
                'ASCII85Decode', 'A85' => self::ascii85($body),
                default => false,
            };
            if (! is_string($body)) {
                return null;
            }
        }

        return strlen($body) <= $budget ? $body : null;
    }

    private static function ascii85(string $s): string|false
    {
        $s = (string) preg_replace('/\s+/', '', $s);
        $s = str_starts_with($s, '<~') ? substr($s, 2) : $s;
        $s = str_contains($s, '~>') ? substr($s, 0, (int) strpos($s, '~>')) : $s;
        $out = '';
        $n = strlen($s);
        for ($i = 0; $i < $n;) {
            if ($s[$i] === 'z') {
                $out .= "\0\0\0\0";
                $i++;

                continue;
            }
            $chunk = substr($s, $i, 5);
            $i += 5;
            $pad = 5 - strlen($chunk);
            $chunk = str_pad($chunk, 5, 'u');
            $v = 0;
            for ($j = 0; $j < 5; $j++) {
                $c = ord($chunk[$j]) - 33;
                if ($c < 0 || $c > 84) {
                    return false;
                }
                $v = $v * 85 + $c;
            }
            $out .= substr(pack('N', $v & 0xFFFFFFFF), 0, 4 - $pad);
        }

        return $out;
    }

    private function isDocx(string $path): bool
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return false;
        }
        $ok = $zip->locateName('word/document.xml') !== false && $zip->locateName('[Content_Types].xml') !== false;
        $zip->close();

        return $ok;
    }

    private function docx(string $path): ?string
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return 'type';
        }
        try {
            if ($zip->numFiles > self::MAX_DOCX_ENTRIES) {
                return 'docx_bomb';
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                $name = (string) $st['name'];
                $total += (int) $st['size'];
                if ($total > self::MAX_DOCX_UNPACKED) {
                    return 'docx_bomb';
                }
                if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                    return 'docx_path';
                }
                $lower = strtolower($name);
                // macros, embedded files (OLE packages run on a double-click), ActiveX controls
                if (str_ends_with($lower, 'vbaproject.bin') || str_ends_with($lower, 'vbadata.xml') || str_contains($lower, 'word/embeddings/')
                    || str_contains($lower, 'word/activex/') || str_contains($lower, 'oleobject')) {
                    return 'docx_active';
                }
                if (str_ends_with($lower, '.rels') && (int) $st['size'] < 2 * 1024 * 1024) {
                    // a template, image or frame fetched from the internet the moment someone opens the file
                    if (preg_match('/TargetMode\s*=\s*["\']External["\']/i', (string) $zip->getFromIndex($i, 2 * 1024 * 1024))
                        || preg_match('#relationships/aFChunk#i', (string) $zip->getFromIndex($i, 2 * 1024 * 1024))) {
                        return 'docx_external';
                    }
                }
            }
            // the text parts: fields that run a program (DDE) or fetch from outside when the file opens, and
            // «altChunk», a whole other document (HTML, RTF) carried inside this one
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                if (! preg_match('#^word/(document|header\d*|footer\d*|footnotes|endnotes|comments|glossary/document)\.xml$#', (string) $st['name'])) {
                    continue;
                }
                $xml = (string) $zip->getFromIndex($i, self::MAX_DOCX_UNPACKED);
                if (preg_match('/<w:altChunk\b/i', $xml)
                    || preg_match('/(?:instrText[^>]*>|w:instr\s*=\s*["\'])\s*(?:DDE|DDEAUTO|INCLUDETEXT|INCLUDEPICTURE|LINK|IMPORT)\b/i', $xml)) {
                    return 'docx_active';
                }
            }
            $types = (string) $zip->getFromName('[Content_Types].xml');
            if (stripos($types, 'macroEnabled') !== false || stripos($types, 'vbaProject') !== false) {
                return 'docx_active';
            }
        } finally {
            $zip->close();
        }

        return null;
    }

    private function image(string $path, string $mime): ?string
    {
        $info = @getimagesize($path);
        if (! $info || ($info['mime'] ?? null) !== $mime) {
            return 'type';
        }
        [$w, $h] = $info;
        if ($w < 1 || $h < 1 || $w > self::MAX_IMAGE_SIDE || $h > self::MAX_IMAGE_SIDE || $w * $h > self::MAX_IMAGE_PIXELS) {
            return 'image_bomb';
        }

        return null;
    }

    /**
     * Rewrites an accepted image through the decoder, in place: whatever rode along in the file (a script in a
     * comment, a second file appended, the GPS position in EXIF) does not survive. No-op without GD.
     */
    public function reencodeImage(string $path, string $mime): void
    {
        if (! function_exists('imagecreatefromstring')) {
            return;
        }
        $img = @imagecreatefromstring((string) file_get_contents($path));
        if (! $img) {
            return;
        }
        match ($mime) {
            'image/png' => imagepng($img, $path, 6),
            'image/webp' => function_exists('imagewebp') ? imagewebp($img, $path, 85) : null,
            default => imagejpeg($img, $path, 88),
        };
    }

    /**
     * A display name only (files are stored under a random name): no path, no control or direction
     * characters (U+202E can turn «cv‮fdp.exe» into what looks like «cv.exe.pdf»), the extension of
     * the real type, and short enough to show.
     */
    public static function cleanName(string $name, string $mime): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $base = (string) preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $base);
        $base = trim((string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $base), " .\t");
        $base = mb_substr($base !== '' ? $base : 'file', 0, 80);
        $ext = match ($mime) {
            self::PDF => 'pdf', self::DOCX => 'docx', 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg',
        };

        return $base.'.'.$ext;
    }
}
