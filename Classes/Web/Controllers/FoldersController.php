<?php

namespace Neuedaten\FreezedDesk\Web\Controllers;

use Neuedaten\Freezed\Domain\Source\DirectoryContentSource;
use Neuedaten\Freezed\Services\ContentSourceService;
use Neuedaten\FreezedDesk\Exception\NotFoundException;
use Neuedaten\FreezedDesk\Web\Request;
use Neuedaten\FreezedDesk\Web\Response;

/**
 * Folder-based content types of the core (pages): listed read-only, with
 * the folder path, because folder pages belong to the editor.
 */
class FoldersController extends Controller
{
    public function index(Request $request, array $params): Response
    {
        $type = (string) $params['type'];
        $config = $this->context->contentTypeConfig($type);
        if ($config === null || ContentSourceService::hasCustomSource($config)) {
            throw new NotFoundException($this->t('ui.notFound'));
        }

        $directory = DirectoryContentSource::getTypeDirectory($type);
        $items = [];
        foreach (glob($directory . '/*', GLOB_ONLYDIR) ?: [] as $folder) {
            $items[] = [
                'slug' => basename($folder),
                'path' => $folder,
                'hasVariables' => is_file($folder . '/variables.php'),
                'templates' => array_map(static fn (string $f): string => basename($f), glob($folder . '/*.html') ?: []),
                'modified' => date('Y-m-d H:i', (int) filemtime($folder)),
            ];
        }

        return $this->view('Folders/Index', [
            'type' => $type,
            'directory' => $directory,
            'items' => $items,
            'config' => $config,
        ]);
    }
}
