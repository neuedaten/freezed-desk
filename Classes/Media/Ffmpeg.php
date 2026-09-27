<?php

namespace Neuedaten\FreezedDesk\Media;

use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * ffmpeg as a program (desk.ffmpeg: its path), for the still of a video in
 * lists (A8.3) and for packages that build videos. Without the setting,
 * videos show a placeholder; nothing tries to find ffmpeg on its own.
 */
final class Ffmpeg
{
    public function __construct(private readonly ?string $binary)
    {
    }

    public function available(): bool
    {
        return $this->binary !== null && $this->binary !== '' && is_executable($this->binary);
    }

    public function binary(): string
    {
        if (!$this->available()) {
            throw new DeskException($this->binary === null || $this->binary === ''
                ? 'ffmpeg is not configured: set desk.ffmpeg to its path (e.g. /opt/homebrew/bin/ffmpeg).'
                : 'desk.ffmpeg "' . $this->binary . '" is not an executable file.');
        }

        return (string) $this->binary;
    }

    /**
     * Write a JPEG still of a video (from second 1, or the first frame of a
     * shorter one), scaled to the given width.
     */
    public function still(string $video, string $target, int $width = 480): bool
    {
        if (!$this->available()) {
            return false;
        }
        $directory = dirname($target);
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }
        foreach (['1', '0'] as $seek) {
            $this->run(['-y', '-ss', $seek, '-i', $video, '-frames:v', '1', '-vf', 'scale=' . $width . ':-2', '-q:v', '4', $target]);
            if (is_file($target) && filesize($target) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Width, height and duration (seconds) of a video, read from ffmpeg's
     * stream description.
     *
     * @return array{width: int|null, height: int|null, duration: float|null}
     */
    public function probe(string $video): array
    {
        $result = ['width' => null, 'height' => null, 'duration' => null];
        if (!$this->available()) {
            return $result;
        }
        $output = $this->run(['-hide_banner', '-i', $video]);
        if (preg_match('/Video:.*?(\d{2,5})x(\d{2,5})/', $output, $m)) {
            $result['width'] = (int) $m[1];
            $result['height'] = (int) $m[2];
        }
        if (preg_match('/Duration:\s*(\d+):(\d+):([\d.]+)/', $output, $m)) {
            $result['duration'] = (int) $m[1] * 3600 + (int) $m[2] * 60 + (float) $m[3];
        }

        return $result;
    }

    /**
     * Run ffmpeg with the given arguments (escaped), return its combined
     * output. The exit code is in $exitCode.
     *
     * @param string[] $arguments
     */
    public function run(array $arguments, ?int &$exitCode = null): string
    {
        $command = escapeshellarg($this->binary()) . ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1';
        $output = [];
        exec($command, $output, $exitCode);

        return implode("\n", $output);
    }
}
