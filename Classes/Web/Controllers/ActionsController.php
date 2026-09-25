<?php

namespace Neuedaten\FreezedDesk\Web\Controllers;

use Neuedaten\FreezedDesk\Exception\NotFoundException;
use Neuedaten\FreezedDesk\Storage\Database;
use Neuedaten\FreezedDesk\Web\Request;
use Neuedaten\FreezedDesk\Web\Response;

/**
 * The shell commands from desk.actions as buttons. Output is streamed to
 * the browser as the command runs; the exit code closes the stream.
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
        $command = $this->context->config->actions()[$name] ?? throw new NotFoundException($this->t('ui.notFound'));
        $projectRoot = $this->context->config->projectRoot;
        $repository = $this->context->repository();

        return Response::streamed(function (callable $write) use ($command, $name, $projectRoot, $repository): void {
            set_time_limit(0);
            ignore_user_abort(true);
            $started = microtime(true);
            $write('$ ' . $command . "\n");

            $process = proc_open(
                $command . ' 2>&1',
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w']],
                $pipes,
                $projectRoot,
                array_merge($_ENV, ['FREEZED_ROOT' => $projectRoot, 'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'])
            );
            if (!is_resource($process)) {
                $write("Could not start the command.\n[exit 1]\n");
                return;
            }

            stream_set_blocking($pipes[1], false);
            while (true) {
                $chunk = fread($pipes[1], 8192);
                if ($chunk !== false && $chunk !== '') {
                    $write($chunk);
                    continue;
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $rest = stream_get_contents($pipes[1]);
                    if (is_string($rest) && $rest !== '') {
                        $write($rest);
                    }
                    break;
                }
                usleep(50000);
            }
            fclose($pipes[1]);
            $exitCode = proc_close($process);
            if ($exitCode === -1 && isset($status['exitcode'])) {
                $exitCode = (int) $status['exitcode'];
            }

            $write(sprintf("\n[exit %d] %.1f s\n", $exitCode, microtime(true) - $started));

            $record = json_encode(['at' => Database::now(), 'exit' => $exitCode, 'seconds' => round(microtime(true) - $started, 1)]);
            $repository->setSetting('action:' . $name, (string) $record);
            if ($name === 'build') {
                $repository->setSetting('lastBuild', (string) $record);
            }
        });
    }
}
