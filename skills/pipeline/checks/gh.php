<?php

/**
 * gh from `$cwd`, without a shell and with no stdin; stdout and stderr apart, so JSON stays JSON.
 *
 * @return array{0: int, 1: string, 2: string}
 */
function pipeline_gh_run(string $cwd, array $args): array
{
    $stderr = tmpfile();
    $process = proc_open(['gh', ...$args], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => $stderr], $pipes, $cwd);
    if (! is_resource($process)) {
        return [127, '', 'gh could not be started'];
    }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code = proc_close($process);
    rewind($stderr);
    $err = trim((string) stream_get_contents($stderr));
    fclose($stderr);

    return [$code, trim((string) $out), $err];
}
