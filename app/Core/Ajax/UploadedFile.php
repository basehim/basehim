<?php

declare(strict_types=1);

namespace App\Core\Ajax;

/**
 * One file sent to an AJAX action that declares `files` (1.2.87).
 *
 * The gateway builds these only after it has checked the file: it arrived
 * whole (no PHP upload error), it is a file PHP received in this request, it
 * is within the action's size limit and its extension is one the action
 * accepts. What it has not done is decide where the file goes: the handler
 * either stores it privately with moveTo() (an app's own storage), adds it
 * to the media library ($api->media()->fromUpload($file)), or just reads it
 * and lets PHP delete it at the end of the request.
 *
 *     $file = $ctx->file('file');
 *     $file->name();        // "my-theme.zip" — the client's name, as a bare file name
 *     $file->extension();   // "zip"
 *     $file->size();        // bytes that arrived (never what the browser claimed)
 *     $file->mime();        // detected from the content
 *     $file->sha256();
 *     $file->moveTo($this->appStorageDir('themes'), 'my-theme-1.2.0.zip');
 *
 * Nothing here trusts the client's name for anything but display and the
 * extension check: moveTo() never writes outside the folder it is given, and
 * never overwrites a file unless asked to.
 */
final class UploadedFile
{
    private ?string $mime = null;
    private ?string $sha256 = null;
    private bool $moved = false;

    /**
     * @param string $field     the form field it arrived in
     * @param string $tmpPath   PHP's temporary file
     * @param string $clientName the name the browser sent (any path stripped)
     * @param int    $size      size on disk
     */
    public function __construct(
        private readonly string $field,
        private string $tmpPath,
        private readonly string $clientName,
        private readonly int $size,
        private readonly bool $isHttpUpload = true,
    ) {}

    /**
     * Build from one $_FILES entry, or say why not. Never throws.
     *
     * @param array{name?:mixed,tmp_name?:mixed,error?:mixed,size?:mixed} $entry
     * @return array{0:?self,1:?string} [file, problem]
     */
    public static function fromEntry(string $field, array $entry, bool $requireHttpUpload = true): array
    {
        if (is_array($entry['name'] ?? null) || is_array($entry['tmp_name'] ?? null)) {
            return [null, 'Send one file in this field.'];
        }
        $error = (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return [null, self::errorMessage($error)];
        }
        $tmp = (string) ($entry['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp) || ($requireHttpUpload && !is_uploaded_file($tmp))) {
            return [null, 'The file did not arrive. Try again.'];
        }
        $size = (int) @filesize($tmp);
        if ($size <= 0) {
            return [null, 'The file is empty.'];
        }
        return [new self($field, $tmp, self::cleanName((string) ($entry['name'] ?? '')), $size, $requireHttpUpload), null];
    }

    /** What a PHP upload error means, for the person uploading. */
    public static function errorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than this server accepts.',
            UPLOAD_ERR_PARTIAL => 'The file only partly arrived. Try again.',
            UPLOAD_ERR_NO_FILE => 'No file was selected.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not store the upload (no writable temporary folder).',
            UPLOAD_ERR_EXTENSION => 'The server refused this file.',
            default => 'The server could not receive the file (upload error ' . $code . ').',
        };
    }

    /**
     * The client's name reduced to a bare, printable file name: no folders,
     * no control characters, at most 200 bytes. Never empty.
     */
    public static function cleanName(string $name): string
    {
        // Repair the encoding first: a name in Latin-1 (caf\xE9.jpg) would
        // otherwise fail the /u pattern below and lose its extension.
        if (!mb_check_encoding($name, 'UTF-8')) $name = mb_convert_encoding($name, 'UTF-8', 'ISO-8859-1');
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name, " .\t");
        if (strlen($name) > 200) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $keep = 200 - ($ext !== '' ? strlen($ext) + 1 : 0);
            $name = mb_strcut(pathinfo($name, PATHINFO_FILENAME), 0, max(1, $keep), 'UTF-8') . ($ext !== '' ? '.' . $ext : '');
        }
        return $name !== '' ? $name : 'upload';
    }

    public function field(): string { return $this->field; }

    /** The browser's file name, cleaned (see cleanName()). For display, not for paths. */
    public function name(): string { return $this->clientName; }

    /** Lower-case extension of the client's name, without the dot ('' when none). */
    public function extension(): string
    {
        return strtolower((string) pathinfo($this->clientName, PATHINFO_EXTENSION));
    }

    /** Bytes on disk. */
    public function size(): int { return $this->size; }

    /** MIME type detected from the content ('application/octet-stream' when unknown). */
    public function mime(): string
    {
        if ($this->mime === null) {
            $m = null;
            if (function_exists('finfo_open') && is_file($this->tmpPath)) {
                $f = @finfo_open(FILEINFO_MIME_TYPE);
                if ($f) { $m = @finfo_file($f, $this->tmpPath) ?: null; finfo_close($f); }
            }
            $this->mime = is_string($m) && $m !== '' ? $m : 'application/octet-stream';
        }
        return $this->mime;
    }

    public function sha256(): string
    {
        return $this->sha256 ??= (string) hash_file('sha256', $this->tmpPath);
    }

    /** [width, height] for a raster image, or null. */
    public function imageSize(): ?array
    {
        $d = @getimagesize($this->tmpPath);
        return $d ? [(int) $d[0], (int) $d[1]] : null;
    }

    /**
     * The temporary file, for reading (ZipArchive, getimagesize, hashing).
     * Do not delete or move it by hand; use moveTo().
     */
    public function path(): string
    {
        if ($this->moved) throw new \LogicException('The upload was already moved to ' . basename($this->tmpPath) . '.');
        return $this->tmpPath;
    }

    /** The file's contents, refusing files larger than $max bytes. */
    public function contents(int $max = 16_777_216): string
    {
        if ($this->size > $max) throw new \LengthException('The file is too large to read into memory.');
        return (string) file_get_contents($this->path());
    }

    public function wasMoved(): bool { return $this->moved; }

    /** True when this came from the browser (false when a test or importer built it). */
    public function isHttpUpload(): bool { return $this->isHttpUpload; }

    /**
     * Move the file into $dir under $name (default: the client's name),
     * creating $dir if needed. Returns the absolute path written.
     *
     * $name must be a bare file name (letters, digits, dot, dash, underscore,
     * at most 200 characters); anything else is refused, so a name taken from
     * a request can never become a path. An existing file is not replaced
     * unless $overwrite is true.
     *
     * @throws \RuntimeException
     */
    public function moveTo(string $dir, ?string $name = null, bool $overwrite = false): string
    {
        if ($this->moved) throw new \RuntimeException('This upload was already moved.');
        $name ??= self::safeStoredName($this->clientName);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}$/', $name) || str_contains($name, '..')) {
            throw new \RuntimeException('The stored file name must be a plain name like "release-1.2.0.zip".');
        }
        // Not under a name a web server would run, even in private storage
        // (nginx does not read the .htaccess that protects it).
        foreach (explode('.', strtolower($name)) as $i => $part) {
            if ($i > 0 && in_array($part, \App\Services\MediaService::NEVER_ALLOWED, true)) {
                throw new \RuntimeException('Files cannot be stored as ".' . $part . '".');
            }
        }
        $dir = rtrim($dir, '/\\');
        if ($dir === '' || str_contains($dir, "\0")) throw new \RuntimeException('No folder to move the upload into.');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create the folder for the upload.');
        }
        $dest = $dir . '/' . $name;
        if (!$overwrite && file_exists($dest)) {
            throw new \RuntimeException('A file named ' . $name . ' already exists there.');
        }
        $ok = $this->isHttpUpload ? @move_uploaded_file($this->tmpPath, $dest) : @rename($this->tmpPath, $dest);
        if (!$ok) {
            throw new \RuntimeException('Could not store the upload (is the folder writable?).');
        }
        @chmod($dest, 0644);
        $this->moved = true;
        $this->tmpPath = $dest;
        return $dest;
    }

    /** A name safe to store: ASCII letters, digits, dot, dash, underscore. */
    public static function safeStoredName(string $name): string
    {
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $stem = (string) pathinfo($name, PATHINFO_FILENAME);
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $stem);
            if (is_string($t)) $stem = $t;
        }
        $stem = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $stem), '-'));
        $stem = substr($stem !== '' ? $stem : 'upload', 0, 150);
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?? '';
        return $stem . ($ext !== '' ? '.' . substr($ext, 0, 10) : '');
    }

    /** What the gateway's idempotency hash includes for this file. */
    public function fingerprint(): string
    {
        return $this->field . ':' . $this->size . ':' . $this->sha256();
    }
}
