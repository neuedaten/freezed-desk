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
        'video/mp4' => 'mp4',
    ];

    /** @var array<int, Media|null> */
    private array $cache = [];

    private ?MediaFields $fields = null;

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

    /** The project's extra fields on media (A7). */
    public function fields(): MediaFields
    {
        return $this->fields ??= new MediaFields($this->context);
    }

    public function ffmpeg(): Ffmpeg
    {
        $binary = $this->context->config->get('ffmpeg');

        return new Ffmpeg(is_string($binary) ? $binary : null);
    }

    /**
     * A still of a video for lists and pickers (A8.3), cached below
     * dataPath/desk.cache; null when ffmpeg is not configured or fails.
     */
    public function poster(Media $media, int $width = 480): ?string
    {
        if (!$media->isVideo()) {
            return null;
        }
        $file = $this->context->config->dataPath() . '/desk.cache/posters/' . $media->id . '-' . substr($media->hash, 0, 8) . '-' . $width . '.jpg';
        if (is_file($file)) {
            return $file;
        }

        return $this->ffmpeg()->still($this->absolutePath($media), $file, $width) ? $file : null;
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
        // Generated files are no duplicates of uploads (A8.2).
        $row = $this->context->database()->fetchOne('SELECT * FROM media WHERE hash = :hash AND origin != \'generated\' ORDER BY id LIMIT 1', ['hash' => $hash]);

        return $row === null ? null : $this->cache[(int) $row['id']] = Media::fromRow($row);
    }

    /**
     * @param string      $kind           "all", "images", "videos" or "files"
     * @param string|null $origin         Only this origin (upload, import, generated).
     * @param bool        $withGenerated  False hides generated files (the library's default view, A8.2).
     * @param array<int, \Closure(Media): bool> $filters Extra conditions, e.g. on extra fields.
     * @return Media[]
     */
    public function all(string $search = '', string $kind = 'all', int $limit = 0, int $offset = 0, ?string $origin = null, bool $withGenerated = true, array $filters = []): array
    {
        $sql = 'SELECT * FROM media WHERE 1 = 1';
        $params = [];

        if ($kind === 'images') {
            $sql .= ' AND mime LIKE \'image/%\'';
        } elseif ($kind === 'videos') {
            $sql .= ' AND mime LIKE \'video/%\'';
        } elseif ($kind === 'files') {
            $sql .= ' AND mime NOT LIKE \'image/%\' AND mime NOT LIKE \'video/%\'';
        }
        if ($origin !== null && $origin !== '') {
            $sql .= ' AND origin = :origin';
            $params['origin'] = $origin;
        } elseif (!$withGenerated) {
            $sql .= ' AND origin != \'generated\'';
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
        if (($limit > 0 || $offset > 0) && $filters === []) {
            $sql .= ' LIMIT ' . ($limit > 0 ? (int) $limit : -1) . ' OFFSET ' . (int) $offset;
        }

        $media = array_map(Media::fromRow(...), $this->context->database()->fetchAll($sql, $params));
        if ($filters !== []) {
            foreach ($filters as $filter) {
                $media = array_values(array_filter($media, $filter));
            }
            if ($limit > 0 || $offset > 0) {
                $media = array_slice($media, $offset, $limit > 0 ? $limit : null);
            }
        }

        return $media;
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
    public function store(string $sourcePath, string $originalName, array $meta = [], bool $move = true, string $origin = 'upload'): Media
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

        [$width, $height] = $this->dimensions($target, $mime);
        $now = Database::now();
        $extra = $this->fields()->defaults($origin);
        if (isset($meta['extra']) && is_array($meta['extra'])) {
            $extra = $this->fields()->merge($extra, $meta['extra']);
        }

        $this->context->database()->execute(
            'INSERT INTO media (file, hash, mime, size, width, height, alt, caption, credit, license, focal_x, focal_y, original_name, created_at, updated_at, extra, origin)
             VALUES (:file, :hash, :mime, :size, :width, :height, :alt, :caption, :credit, :license, :fx, :fy, :name, :created, :updated, :extra, :origin)',
            [
                'extra' => json_encode((object) $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'origin' => in_array($origin, Media::ORIGINS, true) ? $origin : 'upload',
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

        $extra = $media->extra;
        if (array_key_exists('extra', $meta) && is_array($meta['extra'])) {
            $extra = $this->fields()->merge($extra, $meta['extra']);
        }

        $this->context->database()->execute(
            'UPDATE media SET alt = :alt, caption = :caption, credit = :credit, license = :license, focal_x = :fx, focal_y = :fy, extra = :extra, updated_at = :updated WHERE id = :id',
            [
                'extra' => json_encode((object) $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
        if (is_file($path) && !is_link($path) && !$this->context->isDryRun()) {
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
        $extra = is_array($export['extra'] ?? null) ? array_intersect_key($export['extra'], $this->fields()->all()) : [];
        if ($existing !== null) {
            return $this->update($existing->id, $meta + ['extra' => $extra]);
        }
        $origin = in_array($export['origin'] ?? null, Media::ORIGINS, true) ? (string) $export['origin'] : 'upload';
        $generatedBy = is_array($export['generatedBy'] ?? null) ? $export['generatedBy'] : null;

        $absolute = $this->root() . '/' . $file;
        $exists = is_file($absolute);
        $now = Database::now();

        $this->context->database()->execute(
            'INSERT INTO media (file, hash, mime, size, width, height, alt, caption, credit, license, focal_x, focal_y, original_name, created_at, updated_at, extra, origin, generated_by, generated_key)
             VALUES (:file, :hash, :mime, :size, :width, :height, :alt, :caption, :credit, :license, :fx, :fy, :name, :created, :updated, :extra, :origin, :generatedBy, :generatedKey)',
            [
                'extra' => json_encode((object) $this->fields()->merge($this->fields()->defaults($origin), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'origin' => $origin,
                'generatedBy' => $generatedBy === null ? null : json_encode($generatedBy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'generatedKey' => $generatedBy === null ? null : self::generatedKey($generatedBy),
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

    /**
     * Add a file a package generated from a record (a rendered tile, a
     * reel), A8.5. $generatedBy is {type, id, generator}; a file generated
     * before with the same three is replaced in place -- same media id, so
     * references stay valid -- instead of piling up. Generated files live in
     * generated/<type>/<id>/ below the media root, are hidden in the library
     * by default and are no duplicates of uploads.
     *
     * @param array<string, mixed> $meta alt, caption, credit, license, focal, extra
     * @param array{type: string, id: int, generator: string} $generatedBy
     */
    public function addGenerated(string $path, array $meta, array $generatedBy): Media
    {
        $this->assertWritable();
        if (!is_file($path)) {
            throw new DeskException('Generated file not found: ' . $path);
        }
        foreach (['type', 'id', 'generator'] as $key) {
            if (!isset($generatedBy[$key]) || $generatedBy[$key] === '') {
                throw new DeskException('addGenerated() needs generatedBy.' . $key . '.');
            }
        }
        $generatedBy = ['type' => (string) $generatedBy['type'], 'id' => (int) $generatedBy['id'], 'generator' => (string) $generatedBy['generator']];
        $key = self::generatedKey($generatedBy);

        $mime = self::detectMime($path);
        $hash = hash_file('sha256', $path);
        $extension = self::EXTENSIONS[$mime] ?? strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $baseName = Slugger::slugify(str_replace([':', '/'], '-', $generatedBy['generator'])) ?: 'generated';
        $relative = 'generated/' . Slugger::slugify($generatedBy['type']) . '/' . $generatedBy['id'] . '/' . $baseName . '-' . substr($hash, 0, 8) . '.' . $extension;

        $existingRow = $this->context->database()->fetchOne('SELECT * FROM media WHERE generated_key = :key', ['key' => $key]);
        $existing = $existingRow === null ? null : Media::fromRow($existingRow);
        if ($existing !== null && $existing->hash === $hash && is_file($this->absolutePath($existing))) {
            return $existing;
        }

        $target = FileService::resolvePathBelow($this->root(), $relative, 'Generated file');
        if (!$this->context->isDryRun()) {
            if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0777, true) && !is_dir(dirname($target))) {
                throw new DeskException('Could not create ' . dirname($target) . '.');
            }
            if (!@copy($path, $target)) {
                throw new DeskException('Could not write ' . $target . '.');
            }
            @chmod($target, 0644);
        }
        [$width, $height] = $this->dimensions($path, $mime);
        $now = Database::now();
        $values = [
            'file' => $relative,
            'hash' => $hash,
            'mime' => $mime,
            'size' => (int) filesize($path),
            'width' => $width,
            'height' => $height,
            'alt' => trim((string) ($meta['alt'] ?? '')),
            'caption' => trim((string) ($meta['caption'] ?? '')),
            'credit' => trim((string) ($meta['credit'] ?? '')),
            'license' => trim((string) ($meta['license'] ?? '')),
            'fx' => isset($meta['focal']['x']) ? self::clamp((float) $meta['focal']['x']) : null,
            'fy' => isset($meta['focal']['y']) ? self::clamp((float) $meta['focal']['y']) : null,
            'name' => basename($relative),
            'updated' => $now,
            'extra' => json_encode((object) $this->fields()->merge($existing?->extra ?? $this->fields()->defaults('generated'), is_array($meta['extra'] ?? null) ? $meta['extra'] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'generatedBy' => json_encode($generatedBy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'key' => $key,
        ];

        if ($existing !== null) {
            $old = $this->absolutePath($existing);
            $this->context->database()->execute(
                'UPDATE media SET file = :file, hash = :hash, mime = :mime, size = :size, width = :width, height = :height, alt = :alt, caption = :caption, credit = :credit, license = :license, focal_x = :fx, focal_y = :fy, original_name = :name, updated_at = :updated, extra = :extra, generated_by = :generatedBy, generated_key = :key WHERE id = :id',
                $values + ['id' => $existing->id]
            );
            if ($old !== $target && is_file($old) && !$this->context->isDryRun()) {
                @unlink($old);
            }
            unset($this->cache[$existing->id]);

            return $this->require($existing->id);
        }

        $this->context->database()->execute(
            'INSERT INTO media (file, hash, mime, size, width, height, alt, caption, credit, license, focal_x, focal_y, original_name, created_at, updated_at, extra, origin, generated_by, generated_key)
             VALUES (:file, :hash, :mime, :size, :width, :height, :alt, :caption, :credit, :license, :fx, :fy, :name, :created, :updated, :extra, \'generated\', :generatedBy, :key)',
            $values + ['created' => $now]
        );

        return $this->require($this->context->database()->lastInsertId());
    }

    /**
     * Generated files whose record is gone or archived (A8.4), optionally
     * only those older than $olderThanDays. Deleted unless $dryRun; the list
     * of what was (or would be) removed comes back.
     *
     * @return Media[]
     */
    public function pruneGenerated(bool $orphanedOnly = true, ?int $olderThanDays = null, bool $dryRun = false): array
    {
        $this->assertWritable();
        $cutoff = $olderThanDays === null ? null : (new \DateTimeImmutable('-' . $olderThanDays . ' days'))->format('Y-m-d\TH:i:sP');
        $pruned = [];
        foreach ($this->all(origin: 'generated') as $media) {
            if ($cutoff !== null && strcmp($media->updatedAt, $cutoff) > 0) {
                continue;
            }
            $item = isset($media->generatedBy['id']) ? $this->context->repository()->get((int) $media->generatedBy['id']) : null;
            $orphaned = $item === null || $item->status->value === 'archived';
            if ($orphanedOnly && !$orphaned) {
                continue;
            }
            $pruned[] = $media;
            if (!$dryRun) {
                $this->delete($media->id, force: true);
            }
        }

        return $pruned;
    }

    /** @param array{type: string, id: int, generator: string} $generatedBy */
    private static function generatedKey(array $generatedBy): string
    {
        return $generatedBy['type'] . ':' . $generatedBy['id'] . ':' . $generatedBy['generator'];
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
    private function dimensions(string $path, string $mime): array
    {
        if (str_starts_with($mime, 'video/')) {
            $probe = $this->ffmpeg()->probe($path);

            return [$probe['width'], $probe['height']];
        }
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
