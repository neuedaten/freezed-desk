<?php

namespace Neuedaten\FreezedDesk\Web\Controllers;

use Neuedaten\FreezedDesk\Web\Request;
use Neuedaten\FreezedDesk\Web\Response;

/**
 * JSON endpoints for the UI's pickers: records for relation fields, media
 * for image fields.
 */
class ApiController extends Controller
{
    public function search(Request $request, array $params): Response
    {
        $types = array_filter(array_map('trim', explode(',', (string) $request->get('types', ''))));
        $q = trim((string) $request->get('q', ''));
        $limit = max(1, min(50, (int) $request->get('limit', 20)));

        $results = [];
        foreach ($types as $type) {
            if (!$this->context->schemas()->has($type)) {
                continue;
            }
            $schema = $this->context->schemas()->get($type);
            $items = $this->context->repository()->find($type)->notArchived()->search($q)->ordered()->limit($limit)->all();
            foreach ($items as $item) {
                $results[] = [
                    'id' => $item->id,
                    'type' => $item->type,
                    'typeLabel' => $schema->labelSingular,
                    'slug' => $item->slug,
                    'title' => $item->title,
                    'status' => $item->status->value,
                    'value' => $item->type . ':' . $item->id,
                ];
            }
        }

        return Response::json(['results' => array_slice($results, 0, $limit)]);
    }

    public function record(Request $request, array $params): Response
    {
        $item = $this->context->repository()->require($this->intParam($params, 'id'));

        return Response::json([
            'id' => $item->id,
            'type' => $item->type,
            'slug' => $item->slug,
            'title' => $item->title,
            'status' => $item->status->value,
        ]);
    }

    public function media(Request $request, array $params): Response
    {
        $q = trim((string) $request->get('q', ''));
        $kind = (string) $request->get('kind', 'all');
        $limit = max(1, min(200, (int) $request->get('limit', 60)));
        $offset = max(0, (int) $request->get('offset', 0));

        $results = [];
        foreach ($this->context->media()->all($q, $kind, $limit, $offset) as $media) {
            $results[] = self::mediaJson($media);
        }

        return Response::json(['results' => $results]);
    }

    /** @return array<string, mixed> */
    public static function mediaJson(\Neuedaten\FreezedDesk\Media\Media $media): array
    {
        return $media->toVariables() + [
            'thumb' => $media->isImage() ? '/media/thumb/' . $media->id : null,
            'url' => '/media/file/' . $media->file,
            'isImage' => $media->isImage(),
        ];
    }
}
