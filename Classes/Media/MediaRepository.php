<?php

namespace Neuedaten\FreezedDesk\Media;

use Neuedaten\Freezed\Services\FileService;
use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Exception\NotFoundException;
use Neuedaten\FreezedDesk\Exception\ValidationException;
use Neuedaten\FreezedDesk\Schema\Slugger;
use Neuedaten\FreezedDesk\Storage\Database;
use Neuedaten\FreezedDesk\Storage\Item;

/**
 * The media library: files below the media root plus their metadata.
 *
 * store() takes a file (an upload, or a file for desk:seed), checks its
 * MIME type against desk.upload.mimeTypes, refuses an SVG with script, and
 * moves it to YYYY/MM/<name>-<hash8>.<ext>. A file with a hash the library
 * already knows is not stored twice; the existing record is returned.
 */
final class MediaRepository
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/svg+xml' => 'svg',
        'application/pdf' => 'pdf',
    ];

    /** @var array<int, Media|null> */
    private array $cache = [];

    public function __construct(private readonly DeskContext $context)
    {
    }

    public function root(): string
    {
        return $this->context->config->mediaPath();
    }

    public function absolutePath(Media $media): string
    {
        return $this->root() . '/' . $media->file;
    }

    // ----------------------------------------------------------- read ---

    public function get(int $id): ?Media
    {
        if (array_key_exists($id, $this->cache)) {
            return $this->cache[$id];
        }
        $row = $this->context->database()->fetchOne('SELECT * FROM media WHERE id = :id', ['id' => $id]);

        return $this->cache[$id] = $row === null ? null : Media::fromRow($row);
    }

    public function require(int $id): Media
    {
        return $this->get($id) ?? throw new NotFoundException('Media #' . $id . ' does not exist.');
    }

    public function findByFile(string $file): ?Media
    {
        $row = $this->context->database()->fetchOne('SELECT * FROM media WHERE file = :file', ['file' => ltrim($file, '/')]);

        return $row === null ? null : $this->cache[(int) $row['id']] = Media::fromRow($row);
    }

    public function findByHash(string $hash): ?Media
    {
        $row = $this->context->database()->fetchOne('SELECT * FROM media WHERE hash = :hash ORDER BY id LIMIT 1', ['hash' => $hash]);

        return $row === null ? null : $this->cache[(int) $row['id']] = Media::fromRow($row);
    }

    /**
     * @param string $kind "all", "images" or "files"
     * @return Media[]
     */
    public function all(string $search = '', string $kind = 'all', int $limit = 0, int $offset = 0): array
    {
        $sql = 'SELECT * FROM media WHERE 1 = 1';
        $params = [];

        if ($kind === 'images') {
            $sql .= ' AND mime LIKE \'image/%\'';
        } elseif ($kind === 'files') {
            $sql .= ' AND mime NOT LIKE \'image/%\'';
        }

        $i = 0;
        foreach (preg_split('/\s+/', trim($search)) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            $sql .= sprintf(' AND (file LIKE :w%1$d OR alt LIKE :w%1$d OR caption LIKE :w%1$d OR credit LIKE :w%1$d OR original_name LIKE :w%1$d)', $i);
            $params['w' . $i] = '%' . $word . '%';
            $i++;
        }

        $sql .= ' ORDER BY created_at DESC, id DESC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        }

        return array_map(Media::fromRow(...), $this->context->database()->fetchAll($sql, $params));
    }

    public function count(): int
    {
        return (int) $this->context->database()->fetchValue('SELECT COUNT(*) FROM media');
    }

    /**
     * Records that use a media file, with the field they use it in.
     *
     * @return array<int, array{item: Item, field: string}>
     */
    public function usages(int $mediaId): array
    {
        $rows = $this->context->database()->fetchAll(
            'SELECT i.*, u.field AS via FROM media_usage u JOIN items i ON i.id = u.item_id WHERE u.media_id = :id ORDER BY i.type, i.title COLLATE NOCASE',
            ['id' => $mediaId]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[] = ['item' => Item::fromRow($row), 'field' => (string) $row['via']];
        }

        return $result;
    }

    /** @return array<int, int> media id => number of records using it */
    public function usageCounts(): array
    {
        $counts = [];
        foreach ($this->context->database()->fetchAll('SELECT media_id, COUNT(DISTINCT item_id) AS n FROM media_usage GROUP BY media_id') as $row) {
            $counts[(int) $row['media_id']] = (int) $row['n'];
        }

        return $counts;
    }

    // ---------------------------------------------------------- write ---

    /**
     * Add a file to the library.
     *
     * @param string $sourcePath   The file to take (moved when $move is true, copied otherwise).
     * @param string $originalName The name it had (for the slug and the record).
     * @param array<string, mixed> $meta alt, caption, credit, license, focal
     * @throws ValidationException When the file is refused.
     */
    public function store(string $sourcePath, string $originalName, array $meta = [], bool $move = true): Media
    {
        $this->assertWritable();

        if (!is_file($sourcePath)) {
            throw new ValidationException(['file' => $this->context->t('media.notFound')]);
        }

        $size = (int) filesize($sourcePath);
        $maxBytes = (int) $this->context->config->get('upload.maxBytes', 0);
        if ($maxBytes > 0 && $size > $maxBytes) {
            throw new ValidationException(['file' => $this->context->t('media.tooLarge', ['max' => self::formatBytes($maxBytes)])]);
        }

        $mime = self::detectMime($sourcePath);
        $allowed = (array) $this->context->config->get('upload.mimeTypes', []);
        if (!in_array($mime, $allowed, true)) {
            throw new ValidationException(['file' => $this->context->t('media.typeNotAllowed', ['type' => $mime])]);
        }

        if ($mime === 'image/svg+xml' && self::svgContainsScript($sourcePath)) {
            throw new ValidationException(['file' => $this->context->t('media.svgScript')]);
        }

        $hash = hash_file('sha256', $sourcePath);
        $existing = $this->findByHash($hash);
        if ($existing !== null && is_file($this->absolutePath($existing))) {
            if ($move) {
                @unlink($sourcePath);
            }

            return $existing;
        }

        $extension = self::EXTENSIONS[$mime] ?? strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $baseName = Slugger::slugify(pathinfo($originalName, PATHINFO_FILENAME));
        if ($baseName === '') {
            $baseName = 'file';
        }
        $relative = date('Y/m') . '/' . $baseName . '-' . substr($hash, 0, 8) . '.' . $extension;
        $target = FileService::resolvePathBelow($this->root(), $relative, 'Upload "' . $originalName . '"');

        $directory = dirname($target);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new DeskException('Could not create ' . $directory . '.');
        }

        $ok = $move ? @rename($sourcePath, $target) : @copy($sourcePath, $target);
        if (!$ok) {
            // An upload from another file system cannot be renamed; copy then.
            if (!@copy($sourcePath, $target)) {
                throw new DeskException('Could not write ' . $target . '.');
            }
            if ($move) {
                @unlink($sourcePath);
            }
        }
        @chmod($target, 0644);

        [$width, $height] = self::dimensions($target, $mime);
        $now = Database::now();

        $this->context->database()->execute(
            'INSERT INTO media (file, hash, mime, size, width, height, alt, caption, credit, license, focal_x, focal_y, original_name, created_at, updated_at)
             VALUES (:file, :hash, :mime, :size, :width, :height, :alt, :caption, :credit, :license, :fx, :fy, :name, :created, :updated)',
            [
                'file' => $relative,
                'hash' => $hash,
                'mime' => $mime,
                'size' => $size,
                'width' => $width,
                'height' => $height,
                'alt' => trim((string) ($meta['alt'] ?? '')),
                'caption' => trim((string) ($meta['caption'] ?? '')),
                'credit' => trim((string) ($meta['credit'] ?? '')),
                'license' => trim((string) ($meta['license'] ?? '')),
                'fx' => isset($meta['focal']['x']) ? self::clamp((float) $meta['focal']['x']) : null,
                'fy' => isset($meta['focal']['y']) ? self::clamp((float) $meta['focal']['y']) : null,
                'name' => $originalName,
                'created' => $now,
                'updated' => $now,
            ]
        );

        return $this->require($this->context->database()->lastInsertId());
    }

    /**
     * Update the editorial metadata of a file.
     *
     * @param array<string, mixed> $meta alt, caption, credit, license, focal => [x, y] (0..1) or null
     */
    public function update(int $id, array $meta): Media
    {
        $this->assertWritable();
        $media = $this->require($id);

        $focalX = $media->focalX;
        $focalY = $media->focalY;
        if (array_key_exists('focal', $meta)) {
            if (is_array($meta['focal']) && isset($meta['focal']['x'], $meta['focal']['y'])) {
                $focalX = self::clamp((float) $meta['focal']['x']);
                $focalY = self::clamp((float) $meta['focal']['y']);
            } else {
                $focalX = $focalY = null;
            }
        }

        $this->context->database()->execute(
            'UPDATE media SET alt = :alt, caption = :caption, credit = :credit, license = :license, focal_x = :fx, focal_y = :fy, updated_at = :updated WHERE id = :id',
            [
                'alt' => trim((string) ($meta['alt'] ?? $media->alt)),
                'caption' => trim((string) ($meta['caption'] ?? $media->caption)),
                'credit' => trim((string) ($meta['credit'] ?? $media->credit)),
                'license' => trim((string) ($meta['license'] ?? $media->license)),
                'fx' => $focalX,
                'fy' => $focalY,
                'updated' => Database::now(),
                'id' => $id,
            ]
        );
        unset($this->cache[$id]);

        return $this->require($id);
    }

    /**
     * Remove a file and its record. Refused while a record uses it, unless
     * forced (the records then lose the reference on their next save).
     */
    public function delete(int $id, bool $force = false): void
    {
        $this->assertWritable();
        $media = $this->require($id);

        $usages = $this->usages($id);
        if ($usages !== [] && !$force) {
            throw new ValidationException(['_' => $this->context->t('media.inUse', ['count' => count($usages)])]);
        }

        $path = $this->absolutePath($media);
        if (is_file($path) && !is_link($path)) {
            @unlink($path);
        }
        $this->context->database()->execute('DELETE FROM media_usage WHERE media_id = :id', ['id' => $id]);
        $this->context->database()->execute('DELETE FROM media WHERE id = :id', ['id' => $id]);
        unset($this->cache[$id]);
    }

    /**
     * Compare library and folder: records whose file is gone, files no
     * record knows.
     *
     * @return array{missing: Media[], orphans: string[], unused: Media[]}
     */
    public function check(): array
    {
        $root = $this->root();
        $known = [];
        $missing = [];
        $unused = [];
        $usage = $this->usageCounts();

        foreach ($this->all() as $media) {
            $known[$media->file] = true;
            if (!is_file($this->absolutePath($media))) {
                $missing[] = $media;
            } elseif (!isset($usage[$media->id])) {
                $unused[] = $media;
            }
        }

        $orphans = [];
        if (is_dir($root)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile() || in_array($file->getFilename(), ['.DS_Store', 'Thumbs.db'], true)) {
                    continue;
                }
                $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
                if (!isset($known[$relative])) {
                    $orphans[] = $relative;
                }
            }
            sort($orphans);
        }

        return ['missing' => $missing, 'orphans' => $orphans, 'unused' => $unused];
    }

    /**
     * Insert a record from the JSON export (the file is expected to exist
     * already; media files are not part of the export).
     *
     * @param array<string, mixed> $export
     */
    public function importRow(array $export): Media
    {
        $this->assertWritable();
        $file = ltrim((string) ($export['file'] ?? ''), '/');
        if ($file === '') {
            throw new DeskException('Media export entry without "file".');
        }

        $existing = $this->findByFile($file);
        $meta = [
            'alt' => $export['alt'] ?? '',
            'caption' => $export['caption'] ?? '',
            'credit' => $export['credit'] ?? '',
            'license' => $export['license'] ?? '',
            'focal' => $export['focal'] ?? null,
        ];
        if ($existing !== null) {
            return $this->update($existing->id, $meta);
        }

        $absolute = $this->root() . '/' . $file;
        $exists = is_file($absolute);
        $now = Database::now();

        $this->context->database()->execute(
            'INSERT INTO media (file, hash, mime, size, width, height, alt, caption, credit, license, focal_x, focal_y, original_name, created_at, updated_at)
             VALUES (:file, :hash, :mime, :size, :width, :height, :alt, :caption, :credit, :license, :fx, :fy, :name, :created, :updated)',
            [
                'file' => $file,
                'hash' => $exists ? hash_file('sha256', $absolute) : (string) ($export['hash'] ?? ''),
                'mime' => $exists ? self::detectMime($absolute) : (string) ($export['mime'] ?? 'application/octet-stream'),
                'size' => $exists ? (int) filesize($absolute) : (int) ($export['size'] ?? 0),
                'width' => $export['width'] ?? null,
                'height' => $export['height'] ?? null,
                'alt' => trim((string) $meta['alt']),
                'caption' => trim((string) $meta['caption']),
                'credit' => trim((string) $meta['credit']),
                'license' => trim((string) $meta['license']),
                'fx' => isset($meta['focal']['x']) ? self::clamp((float) $meta['focal']['x']) : null,
                'fy' => isset($meta['focal']['y']) ? self::clamp((float) $meta['focal']['y']) : null,
                'name' => (string) ($export['originalName'] ?? basename($file)),
                'created' => (string) ($export['createdAt'] ?? $now),
                'updated' => $now,
            ]
        );

        return $this->require($this->context->database()->lastInsertId());
    }

    public function version(): ?string
    {
        $value = $this->context->database()->fetchValue('SELECT MAX(updated_at) FROM media');

        return $value === null ? null : (string) $value;
    }

    // -------------------------------------------------------- helpers ---

    /**
     * The core's SVG rule (FileService::svgContainsScript), applied to a
     * file whatever its name: uploads arrive under a temporary name.
     */
    public static function svgContainsScript(string $path): bool
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            return true;
        }

        return (bool) preg_match(
            '/<\s*script\b|<\s*foreignObject\b|\bon[a-z]+\s*=|javascript\s*:|<\s*set\b[^>]*attributeName\s*=\s*["\']?on/i',
            $content
        );
    }

    public static function detectMime(string $path): string
    {
        $mime = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $path);
                if (is_string($detected)) {
                    $mime = $detected;
                }
            }
        }
        if ($mime === null || $mime === 'text/plain' || $mime === 'text/xml' || $mime === 'application/xml') {
            // finfo reports SVG as text/xml or text/plain now and then.
            $head = (string) @file_get_contents($path, false, null, 0, 2048);
            if (preg_match('/<svg[\s>]/i', $head)) {
                return 'image/svg+xml';
            }
        }

        return $mime ?? 'application/octet-stream';
    }

    /** @return array{0: int|null, 1: int|null} */
    private static function dimensions(string $path, string $mime): array
    {
        if ($mime === 'image/svg+xml') {
            $content = (string) @file_get_contents($path, false, null, 0, 4096);
            if (preg_match('/<svg[^>]*\swidth="([\d.]+)(?:px)?"[^>]*\sheight="([\d.]+)(?:px)?"/i', $content, $m)) {
                return [(int) round((float) $m[1]), (int) round((float) $m[2])];
            }
            if (preg_match('/viewBox="[\d.\-]+\s+[\d.\-]+\s+([\d.]+)\s+([\d.]+)"/i', $content, $m)) {
                return [(int) round((float) $m[1]), (int) round((float) $m[2])];
            }

            return [null, null];
        }
        if (!str_starts_with($mime, 'image/')) {
            return [null, null];
        }
        $info = @getimagesize($path);
        if ($info === false) {
            return [null, null];
        }

        return [(int) $info[0], (int) $info[1]];
    }

    private static function clamp(float $value): float
    {
        return round(max(0.0, min(1.0, $value)), 4);
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }

        return $bytes . ' B';
    }

    private function assertWritable(): void
    {
        if ($this->context->readOnly) {
            throw new DeskException('The desk database is open read-only.');
        }
        $this->context->config->validateMediaRoot();
    }
}
