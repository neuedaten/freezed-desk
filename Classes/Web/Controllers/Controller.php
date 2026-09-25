<?php

namespace Neuedaten\FreezedDesk\Web\Controllers;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\NotFoundException;
use Neuedaten\FreezedDesk\Schema\TypeSchema;
use Neuedaten\FreezedDesk\Web\App;
use Neuedaten\FreezedDesk\Web\Request;
use Neuedaten\FreezedDesk\Web\Response;

abstract class Controller
{
    protected readonly DeskContext $context;

    public function __construct(protected readonly App $app)
    {
        $this->context = $app->context;
    }

    protected function view(string $template, array $variables = [], int $status = 200): Response
    {
        return Response::html($this->app->view->render($template, $variables), $status);
    }

    protected function redirect(string $location): Response
    {
        return Response::redirect($location);
    }

    protected function flash(string $type, string $message): void
    {
        $this->app->session->flash($type, $message);
    }

    protected function t(string $key, array $params = []): string
    {
        return $this->context->t($key, $params);
    }

    protected function schema(string $type): TypeSchema
    {
        if (!$this->context->schemas()->has($type)) {
            throw new NotFoundException($this->t('ui.notFound'));
        }

        return $this->context->schemas()->get($type);
    }

    protected function intParam(array $params, string $name): int
    {
        $value = $params[$name] ?? '';
        if (!ctype_digit((string) $value)) {
            throw new NotFoundException($this->t('ui.notFound'));
        }

        return (int) $value;
    }

    /** @return int[] */
    protected function idList(Request $request, string $key = 'ids'): array
    {
        $raw = $request->post($key, []);
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        $ids = [];
        foreach ((array) $raw as $id) {
            if (is_int($id) || ctype_digit((string) $id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
