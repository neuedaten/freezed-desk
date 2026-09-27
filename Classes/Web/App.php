<?php

namespace Neuedaten\FreezedDesk\Web;

use Neuedaten\Freezed\Exception\PathNotAllowedException;
use Neuedaten\FreezedDesk\Cli;
use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Exception\NotFoundException;
use Neuedaten\FreezedDesk\Exception\ValidationException;
use Neuedaten\FreezedDesk\Storage\Actor;
use Neuedaten\FreezedDesk\Web\Controllers\ActionsController;
use Neuedaten\FreezedDesk\Web\Controllers\ApiController;
use Neuedaten\FreezedDesk\Web\Controllers\DashboardController;
use Neuedaten\FreezedDesk\Web\Controllers\FoldersController;
use Neuedaten\FreezedDesk\Web\Controllers\InboxController;
use Neuedaten\FreezedDesk\Web\Controllers\MediaController;
use Neuedaten\FreezedDesk\Web\Controllers\RecordsController;

/**
 * The desk web application: one request in, one response out. Bootstraps
 * the project like the CLI, starts the session, checks Basic auth (when
 * configured) and the CSRF token of every POST, then dispatches.
 */
final class App
{
    public readonly Session $session;

    public readonly View $view;

    public readonly Router $router;

    public function __construct(public readonly DeskContext $context)
    {
        // Everything done through the UI is a person's change (A3.2).
        $context->actAs(Actor::Editor);
        $this->session = new Session($context->config->sessionPath());
        $this->view = new View($context);
        $this->router = new Router();
        $this->registerRoutes();
    }

    /**
     * Entry point for the router script.
     *
     * @param array<string, mixed> $options CLI options forwarded by serve.
     */
    public static function serve(array $options): bool
    {
        $request = Request::fromGlobals();

        try {
            $context = DeskContext::isBooted() ? DeskContext::get() : Cli::bootstrap($options);
            $app = new self($context);
            $response = $app->handle($request);
        } catch (DeskException | PathNotAllowedException $exception) {
            $response = Response::html(self::plainErrorPage('Desk', $exception->getMessage()), 500);
        } catch (\Throwable $exception) {
            $response = Response::html(self::plainErrorPage(get_class($exception), $exception->getMessage() . "\n\n" . $exception->getTraceAsString()), 500);
        }

        $response->send();

        return true;
    }

    public function handle(Request $request): Response
    {
        // Static files of the UI, before session and auth.
        if (str_starts_with($request->path, '/assets/')) {
            $file = $this->view->staticFile(substr($request->path, 8));
            if ($file === null) {
                return Response::html('Not found', 404);
            }

            return Response::file($file, self::mimeOf($file));
        }

        $denied = $this->checkAuth($request);
        if ($denied !== null) {
            return $denied;
        }

        $this->session->start();
        $this->view->share('csrfToken', $this->session->csrfToken());
        $this->view->share('flashes', $this->session->takeFlashes());
        $this->view->share('request', $request);
        $this->view->share('currentPath', $request->path);
        $this->view->share('schemas', $this->context->schemas()->all());
        $this->view->share('builtTypes', $this->context->schemas()->built());
        $this->view->share('deskOnlyTypes', $this->context->schemas()->deskOnly());
        $this->view->share('folderTypes', $this->folderTypes());
        $this->view->share('actions', $this->context->config->actions());
        $this->view->share('inboxEnabled', is_array($this->context->config->get('inbox')) || $this->context->inbox()->count() > 0);
        $this->view->share('locale', $this->context->translator()->locale);
        $this->view->share('assetVersion', $this->assetVersion());
        $this->view->share('authUser', $_SERVER['PHP_AUTH_USER'] ?? null);
        $this->view->share('projectName', $this->context->config->projectName());
        $this->view->share('projectRoot', $this->context->config->projectRoot);
        $this->view->share('extensionNavigation', $this->extensionNavigation());

        $match = $this->router->match($request->method, $request->path);
        if ($match === null) {
            return $this->errorPage(404, $this->context->t('ui.notFound'));
        }

        if ($request->isPost() && !$this->session->checkCsrf($request->post('_token') ?? $request->header('x-csrf-token'))) {
            if ($request->wantsJson()) {
                return Response::json(['error' => $this->context->t('ui.forbidden')], 403);
            }

            return $this->errorPage(403, $this->context->t('ui.forbidden'));
        }

        [$class, $method] = $match['handler'];

        try {
            if ($class === self::class) {
                return $this->$method($request, $match['params']);
            }
            $controller = new $class($this);

            return $controller->$method($request, $match['params']);
        } catch (NotFoundException $exception) {
            return $this->errorPage(404, $exception->getMessage());
        } catch (ValidationException $exception) {
            if ($request->wantsJson()) {
                return Response::json(['error' => $exception->getMessage(), 'errors' => $exception->errors], 422);
            }

            return $this->errorPage(422, $exception->getMessage());
        } catch (DeskException | PathNotAllowedException $exception) {
            if ($request->wantsJson()) {
                return Response::json(['error' => $exception->getMessage()], 400);
            }

            return $this->errorPage(400, $exception->getMessage());
        }
    }

    public function methodNotAllowed(Request $request, array $params): Response
    {
        return $this->errorPage(405, 'Method not allowed.');
    }

    public function errorPage(int $status, string $message): Response
    {
        try {
            $body = $this->view->render('Error/Index', ['status' => $status, 'message' => $message]);
        } catch (\Throwable) {
            $body = self::plainErrorPage((string) $status, $message);
        }

        return Response::html($body, $status);
    }

    private function registerRoutes(): void
    {
        $r = $this->router;

        $r->add('GET', '/', [DashboardController::class, 'index'], 'status');

        $r->add('GET', '/types/{type}', [RecordsController::class, 'index'], 'list');
        $r->add('GET', '/types/{type}/new', [RecordsController::class, 'create'], 'schema');
        $r->add('POST', '/types/{type}/new', [RecordsController::class, 'store'], 'put');
        $r->add('POST', '/types/{type}/bulk', [RecordsController::class, 'bulk'], 'publish');
        $r->add('POST', '/types/{type}/reorder', [RecordsController::class, 'reorder'], 'reorder');
        $r->add('POST', '/types/{type}/actions/{name}/run', [RecordsController::class, 'bulkAction'], 'action');
        $r->add('GET', '/types/{type}/{id}', [RecordsController::class, 'edit'], 'get');
        $r->add('POST', '/types/{type}/{id}', [RecordsController::class, 'update'], 'put');
        $r->add('POST', '/types/{type}/{id}/status', [RecordsController::class, 'status'], 'publish');
        $r->add('POST', '/types/{type}/{id}/delete', [RecordsController::class, 'delete'], 'delete');
        $r->add('POST', '/types/{type}/{id}/actions/{name}/run', [RecordsController::class, 'action'], 'action');
        $r->add('GET', '/types/{type}/{id}/preview', [RecordsController::class, 'preview'], 'preview-url');
        $r->add('GET', '/types/{type}/{id}/revisions', [RecordsController::class, 'revisions'], 'revisions');
        $r->add('POST', '/types/{type}/{id}/revisions/{revision}/restore', [RecordsController::class, 'restore'], 'restore');

        $r->add('GET', '/folders/{type}', [FoldersController::class, 'index'], 'ui:folder types are read from content/ by the core; the CLI reads the files');

        $r->add('GET', '/media', [MediaController::class, 'index'], 'media:list');
        $r->add('POST', '/media/upload', [MediaController::class, 'upload'], 'media:add');
        $r->add('GET', '/media/thumb/{id}', [MediaController::class, 'thumb'], 'ui:a thumbnail for the browser; media:get gives the path');
        $r->add('GET', '/media/file/{path...}', [MediaController::class, 'file'], 'ui:the file for the browser; media:get gives the path');
        $r->add('GET', '/media/{id}', [MediaController::class, 'show'], 'media:get');
        $r->add('POST', '/media/{id}', [MediaController::class, 'update'], 'media:update');
        $r->add('POST', '/media/{id}/delete', [MediaController::class, 'delete'], 'media:delete');

        $r->add('GET', '/inbox', [InboxController::class, 'index'], 'inbox:list');
        $r->add('POST', '/inbox/fetch', [InboxController::class, 'fetch'], 'inbox');
        $r->add('GET', '/inbox/{id}', [InboxController::class, 'show'], 'inbox:show');
        $r->add('POST', '/inbox/{id}', [InboxController::class, 'update'], 'inbox:set');

        $r->add('GET', '/actions', [ActionsController::class, 'index'], 'actions');
        $r->add('POST', '/actions/{name}/run', [ActionsController::class, 'run'], 'action');

        $r->add('GET', '/api/search', [ApiController::class, 'search'], 'list');
        $r->add('GET', '/api/media', [ApiController::class, 'media'], 'media:list');
        $r->add('GET', '/api/records/{id}', [ApiController::class, 'record'], 'get');

        foreach ($this->context->extensions() as $extension) {
            $extension->routes($r);
        }
    }

    /**
     * Basic authentication, only when desk.auth is configured. The password
     * hash comes from an environment variable, never from the config file.
     */
    private function checkAuth(Request $request): ?Response
    {
        $auth = $this->context->config->get('auth');
        if (!is_array($auth)) {
            return null;
        }

        $user = (string) ($auth['user'] ?? '');
        $hashEnv = (string) ($auth['passwordHashEnv'] ?? '');
        $hash = $hashEnv !== '' ? (string) getenv($hashEnv) : '';

        if ($user === '' || $hash === '') {
            return Response::html(self::plainErrorPage('Desk', 'desk.auth is set, but "user" or the environment variable named by "passwordHashEnv" is empty.'), 500);
        }

        $givenUser = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
        $givenPassword = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');

        if ($givenUser !== '' && hash_equals($user, $givenUser) && password_verify($givenPassword, $hash)) {
            return null;
        }

        return Response::html('Authentication required.', 401)
            ->withHeader('WWW-Authenticate', 'Basic realm="Desk", charset="UTF-8"');
    }

    /**
     * Content types of the core that read from folders: shown read-only.
     *
     * @return array<string, array{slug: string, directory: string, count: int}>
     */
    private function folderTypes(): array
    {
        $types = [];
        foreach ($this->context->builtTypes() as $slug) {
            $config = $this->context->contentTypeConfig($slug) ?? [];
            if (\Neuedaten\Freezed\Services\ContentSourceService::hasCustomSource($config)) {
                continue;
            }
            $directory = \Neuedaten\Freezed\Domain\Source\DirectoryContentSource::getTypeDirectory($slug);
            $types[$slug] = [
                'slug' => $slug,
                'directory' => $directory,
                'count' => count(glob($directory . '/*', GLOB_ONLYDIR) ?: []),
            ];
        }

        return $types;
    }

    /**
     * Sidebar links of the extensions.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extensionNavigation(): array
    {
        $links = [];
        foreach ($this->context->extensions() as $extension) {
            foreach ($extension->navigation($this->context) as $link) {
                $links[] = $link + ['badge' => null, 'level' => ''];
            }
        }

        return $links;
    }

    /**
     * A short hash of the UI's css and js, so browsers pick up changes.
     */
    private function assetVersion(): string
    {
        $parts = [];
        foreach (['css/desk.css', 'css/theme.css', 'js/desk.js'] as $asset) {
            $file = $this->view->staticFile($asset);
            $parts[] = $file === null ? '0' : (string) filemtime($file);
        }

        return substr(md5(implode(':', $parts)), 0, 8);
    }

    public static function mimeOf(string $file): string
    {
        return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'css' => 'text/css; charset=UTF-8',
            'js' => 'application/javascript; charset=UTF-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'pdf' => 'application/pdf',
            'json' => 'application/json; charset=UTF-8',
            default => 'application/octet-stream',
        };
    }

    private static function plainErrorPage(string $title, string $message): string
    {
        return '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>'
            . '<body style="font: 15px/1.5 system-ui, sans-serif; max-width: 60em; margin: 3em auto; padding: 0 1em">'
            . '<h1 style="font-size: 1.3em">' . htmlspecialchars($title) . '</h1>'
            . '<pre style="white-space: pre-wrap">' . htmlspecialchars($message) . '</pre></body>';
    }
}
