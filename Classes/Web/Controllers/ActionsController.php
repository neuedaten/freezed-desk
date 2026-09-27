<?php

namespace Neuedaten\FreezedDesk\Web\Controllers;

use Neuedaten\FreezedDesk\Actions\ActionRunner;
use Neuedaten\FreezedDesk\Web\Request;
use Neuedaten\FreezedDesk\Web\Response;

/**
 * The shell commands from desk.actions as buttons. Output is streamed to
 * the browser as the command runs; the exit code closes the stream. The
 * same commands run from the CLI with `action <name>` (A1.8).
 */
class ActionsController extends Controller
{
    public function index(Request $request, array $params): Response
    {
        $actions = [];
        foreach ($this->context->config->actions() as $name => $command) {
            $last = json_decode((string) $this->context->repository()->setting('action:' . $name, ''), true);
            $actions[] = ['name' => $name, 'command' => $command, 'last' => is_array($last) ? $last : null];
        }

        return $this->view('Actions/Index', ['actionList' => $actions]);
    }

    public function run(Request $request, array $params): Response
    {
        $name = (string) $params['name'];
        $runner = new ActionRunner($this->context);
        $command = $runner->globalCommand($name);
        $repository = $this->context->repository();

        return Response::streamed(function (callable $write) use ($runner, $command, $name, $repository): void {
            set_time_limit(0);
            ignore_user_abort(true);
            $started = microtime(true);
            $write('$ ' . $command . "\n");
            $exitCode = $runner->run($command, $write, 'action:' . $name);
            $write(sprintf("\n[exit %d] %.1f s\n", $exitCode, microtime(true) - $started));
            if ($name === 'build') {
                $repository->setSetting('lastBuild', (string) $repository->setting('action:build', ''));
            }
        });
    }
}
