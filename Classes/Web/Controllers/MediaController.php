<?php

namespace Neuedaten\FreezedDesk\Web\Controllers;

use Neuedaten\Freezed\Services\FileService;
use Neuedaten\FreezedDesk\Exception\NotFoundException;
use Neuedaten\FreezedDesk\Exception\ValidationException;
use Neuedaten\FreezedDesk\Media\Media;
use Neuedaten\FreezedDesk\Web\App;
use Neuedaten\FreezedDesk\Web\Request;
use Neuedaten\FreezedDesk\Web\Response;

class MediaController extends Controller
{
    private const PER_PAGE = 60;

    private const THUMB_WIDTH = 480;

    public function index(Request $request, array $params): Response
    {
        $q = trim((string) $request->get('q', ''));
        $kind = (string) $request->get('kind', 'all');
        $page = max(1, (int) $request->get('page', 1));
        $media = $this->context->media();

        $all = $media->all($q, $kind);
        $total = count($all);
        $items = array_slice($all, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        return $this->view('Media/Index', [
            'items' => array_map(ApiController::mediaJson(...), $items),
            'usage' => $media->usageCounts(),
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / self::PER_PAGE),
            'q' => $q,
            'kind' => $kind,
            'maxBytes' => (int) $this->context->config->get('upload.maxBytes', 0),
        ]);
    }

    public function upload(Request $request, array $params): Response
    {
        $files = $request->files['files'] ?? null;
        if (!is_array($files)) {
            throw new ValidationException(['file' => $this->t('media.notFound')]);
        }

        $entries = isset($files['name']) && is_array($files['name'])
            ? array_map(static fn (int $i): array => [
                'name' => $files['name'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i],
            ], array_keys($files['name']))
            : [$files];

        $tmpDirectory = $this->context->config->dataPath() . '/desk.tmp';
        if (!is_dir($tmpDirectory)) {
            @mkdir($tmpDirectory, 0700, true);
        }

        $stored = [];
        $errors = [];
        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? 'file');
            if ((int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $errors[$name] = 'Upload error ' . (int) ($entry['error'] ?? -1);
                continue;
            }
            $tmp = $tmpDirectory . '/' . bin2hex(random_bytes(8));
            if (!is_uploaded_file((string) $entry['tmp_name']) || !move_uploaded_file((string) $entry['tmp_name'], $tmp)) {
                $errors[$name] = 'Upload failed';
                continue;
            }
            try {
                $stored[] = $this->context->media()->store($tmp, $name, [], move: true);
            } catch (ValidationException $exception) {
                $errors[$name] = $exception->getMessage();
            } finally {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }

        if ($request->wantsJson()) {
            return Response::json([
                'media' => array_map(ApiController::mediaJson(...), $stored),
                'errors' => $errors,
            ], $stored === [] && $errors !== [] ? 422 : 200);
        }

        if ($stored !== []) {
            $this->flash('success', $this->t('ui.uploadOk', ['count' => count($stored)]));
        }
        foreach ($errors as $name => $error) {
            $this->flash('error', $name . ': ' . $error);
        }

        return $this->redirect('/media');
    }

    public function show(Request $request, array $params): Response
    {
        $media = $this->media($params);

        return $this->view('Media/Show', [
            'media' => $media,
            'usages' => $this->context->media()->usages($media->id),
            'url' => '/media/file/' . $media->file,
            'thumb' => $media->isImage() ? '/media/thumb/' . $media->id : null,
            'snippet' => $media->isImage()
                ? sprintf("{freezed:image(src: '%s', context: '%s', width: 1200)}", $media->file, $this->context->config->mediaRootName())
                : sprintf("{freezed:resource(path: '%s', context: '%s')}", $media->file, $this->context->config->mediaRootName()),
        ]);
    }

    public function update(Request $request, array $params): Response
    {
        $media = $this->media($params);

        $meta = [
            'alt' => (string) $request->post('alt', ''),
            'caption' => (string) $request->post('caption', ''),
            'credit' => (string) $request->post('credit', ''),
            'license' => (string) $request->post('license', ''),
        ];
        $focal = $request->post('focal');
        if (is_array($focal) && ($focal['x'] ?? '') !== '' && ($focal['y'] ?? '') !== '') {
            $meta['focal'] = ['x' => (float) $focal['x'], 'y' => (float) $focal['y']];
        } else {
            $meta['focal'] = null;
        }

        $updated = $this->context->media()->update($media->id, $meta);

        if ($request->wantsJson()) {
            return Response::json(['media' => ApiController::mediaJson($updated)]);
        }
        $this->flash('success', $this->t('ui.saved'));

        return $this->redirect('/media/' . $media->id);
    }

    public function delete(Request $request, array $params): Response
    {
        $media = $this->media($params);
        try {
            $this->context->media()->delete($media->id, force: (bool) $request->post('force'));
        } catch (ValidationException $exception) {
            $this->flash('error', $exception->getMessage());

            return $this->redirect('/media/' . $media->id);
        }
        $this->flash('success', $this->t('ui.deleted'));

        return $this->redirect('/media');
    }

    /**
     * The original file, checked to lie below the media root.
     */
    public function file(Request $request, array $params): Response
    {
        $relative = (string) ($params['path'] ?? '');
        $root = $this->context->media()->root();
        $path = FileService::resolvePathBelow($root, $relative, 'Media file "' . $relative . '"');
        $real = realpath($path);
        if ($real === false || !is_file($real) || !FileService::isInside($real, realpath($root) ?: $root)) {
            throw new NotFoundException($this->t('ui.notFound'));
        }

        return Response::file($real, App::mimeOf($real));
    }

    /**
     * A small JPEG for lists and pickers, cached below dataPath/desk.cache.
     * Falls back to the original when GD is missing or the format is not
     * one GD resizes well (SVG, GIF).
     */
    public function thumb(Request $request, array $params): Response
    {
        $media = $this->media($params);
        $source = $this->context->media()->absolutePath($media);
        if (!is_file($source)) {
            throw new NotFoundException($this->t('ui.notFound'));
        }

        if (!$media->isImage() || $media->isSvg() || $media->mime === 'image/gif' || !function_exists('imagecreatefromstring')
            || ($media->width !== null && $media->width <= self::THUMB_WIDTH)) {
            return Response::file($source, $media->mime);
        }

        $cacheDirectory = $this->context->config->dataPath() . '/desk.cache/thumbs';
        $cacheFile = $cacheDirectory . '/' . $media->id . '-' . substr($media->hash, 0, 8) . '.jpg';

        if (!is_file($cacheFile)) {
            if (!is_dir($cacheDirectory)) {
                @mkdir($cacheDirectory, 0777, true);
            }
            $image = @imagecreatefromstring((string) file_get_contents($source));
            if ($image === false) {
                return Response::file($source, $media->mime);
            }
            $width = imagesx($image);
            $height = imagesy($image);
            $targetWidth = min(self::THUMB_WIDTH, $width);
            $targetHeight = (int) round($height * $targetWidth / $width);
            $thumb = imagecreatetruecolor($targetWidth, $targetHeight);
            $white = imagecolorallocate($thumb, 255, 255, 255);
            imagefill($thumb, 0, 0, $white);
            imagecopyresampled($thumb, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
            imagejpeg($thumb, $cacheFile, 82);
        }

        return Response::file($cacheFile, 'image/jpeg');
    }

    private function media(array $params): Media
    {
        return $this->context->media()->require($this->intParam($params, 'id'));
    }
}
