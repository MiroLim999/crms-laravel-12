<?php

namespace App\Services\Ocr;

use App\Models\User;
use App\Services\AuditLogger;
use Symfony\Component\Process\Process;

/**
 * Starts and stops the FastAPI OCR service, so a Super Admin never has to type
 *   uvicorn ml.api.main:app --host 127.0.0.1 --port 8001
 * into a terminal.
 *
 * Security notes, because this class spawns an OS process from a web request:
 *
 * - Every part of the command line comes from `config('services.ocr')`, never from
 *   the request. Nothing user-supplied is interpolated.
 * - The command is built as an argv array and handed to Symfony Process, so there
 *   is no shell string to inject into.
 * - The bind address is forced to a loopback host. The service has no
 *   authentication of its own, so exposing it on a routable interface would hand
 *   the GPU and the model files to the network.
 * - Both routes are behind the `ocr.manage` gate, i.e. Super Admin only.
 *
 * Set `OCR_MANAGED=false` to disable process control entirely, which is what a
 * deployment running the service under a real supervisor should do.
 */
class EngineProcess
{
    /** How long to wait for /health after starting, in seconds. */
    private const STARTUP_TIMEOUT = 60;

    public function __construct(
        private readonly OcrClient $client,
        private readonly AuditLogger $audit,
    ) {}

    // -------------------------------------------------------------------- reading

    /**
     * Combined view: is the process ours, is it alive, and does it answer?
     *
     * A service someone started by hand is still reported as reachable - it just
     * has no PID we know about, so Stop is not offered for it.
     *
     * @return array{managed: bool, running: bool, pid: int|null, reachable: bool, device: string|null, busy: bool, job: array<string, mixed>|null, error: string|null, url: string, command: string}
     */
    public function status(): array
    {
        // Shared with the other collaborators on this page via the client's
        // per-request cache, so the whole page costs one probe.
        $health = $this->client->health();
        $pid = $this->recordedPid();

        // A stale PID file outlives a crash, so verify the process really exists.
        if ($pid !== null && ! $this->isAlive($pid)) {
            $this->forgetPid();
            $pid = null;
        }

        // A reachable service with no tracked PID was started outside CRMS. Resolve
        // its listener only for diagnostics; ownership is never inferred from a port.
        $listener = ($pid === null && $health['reachable']) ? $this->listenerPid() : null;

        return [
            'managed' => $this->isManaged(),
            'running' => $pid !== null,
            'pid' => $pid,
            'owned' => $pid !== null,
            'listener_pid' => $listener,
            // CRMS may stop only a process whose PID it recorded when starting it.
            'stoppable' => $pid !== null,
            'reachable' => $health['reachable'],
            'device' => $health['device'],
            'busy' => (bool) ($health['busy'] ?? false),
            'job' => $health['job'] ?? null,
            'error' => $health['error'],
            'url' => $this->baseUrl(),
            'command' => $this->displayCommand(),
        ];
    }

    /**
     * PID of whatever is listening on our configured port, whether we started it
     * or not. Null when the port is free.
     */
    public function listenerPid(): ?int
    {
        $port = $this->port();

        if (windows_os()) {
            $process = new Process(['netstat', '-ano', '-p', 'TCP'], base_path(), timeout: 15);
            $process->run();

            foreach (preg_split('/\R/', $process->getOutput()) ?: [] as $line) {
                // e.g. "  TCP    127.0.0.1:8001    0.0.0.0:0    LISTENING    81580"
                // The \s after the port stops :8001 also matching :80010.
                if (preg_match('/^\s*TCP\s+\S+:'.$port.'\s+\S+\s+LISTENING\s+(\d+)/i', $line, $m) === 1) {
                    return (int) $m[1];
                }
            }

            return null;
        }

        $process = Process::fromShellCommandline(
            sprintf('lsof -ti tcp:%d -sTCP:LISTEN 2>/dev/null | head -n 1', $port),
            base_path(),
            timeout: 15,
        );
        $process->run();

        $pid = (int) trim($process->getOutput());

        return $pid > 0 ? $pid : null;
    }

    /**
     * Does this PID look like a Python process?
     *
     * Checked before adopting a port holder we did not start. Killing whatever
     * happens to be on the port would be reckless - if something else is bound
     * there, the Super Admin needs to be told, not have it terminated.
     */
    private function looksLikePython(int $pid): bool
    {
        return stripos($this->processName($pid), 'python') !== false;
    }

    private function processName(int $pid): string
    {
        if (windows_os()) {
            $tasklist = new Process(
                ['tasklist', '/FI', "PID eq {$pid}", '/NH', '/FO', 'CSV'],
                base_path(),
                timeout: 15,
            );
            $tasklist->run();

            if (str_contains($tasklist->getOutput(), (string) $pid)) {
                return $tasklist->getOutput();
            }

            return $this->windowsProcessPath($pid);
        }

        $process = Process::fromShellCommandline("ps -p {$pid} -o comm=", base_path(), timeout: 10);
        $process->run();

        return $process->getOutput();
    }

    /**
     * Query a Windows process without PowerShell. The managed PowerShell host is
     * broken on this machine and must not decide whether a valid PID is forgotten.
     */
    private function windowsProcessPath(int $pid): string
    {
        $probe = <<<'PY'
import ctypes
import sys

kernel32 = ctypes.windll.kernel32
handle = kernel32.OpenProcess(0x1000, False, int(sys.argv[1]))
if not handle:
    raise SystemExit(1)

try:
    size = ctypes.c_ulong(32768)
    path = ctypes.create_unicode_buffer(size.value)
    if not kernel32.QueryFullProcessImageNameW(handle, 0, path, ctypes.byref(size)):
        raise SystemExit(1)
    print(path.value)
finally:
    kernel32.CloseHandle(handle)
PY;

        $process = new Process(
            [$this->resolvedPython(), '-c', $probe, (string) $pid],
            base_path(),
            timeout: 15,
        );
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : '';
    }

    /**
     * Tail of the service's own output. The only way to explain a start that
     * failed before the process could answer /health.
     *
     * @return list<string>
     */
    public function logTail(int $lines = 40): array
    {
        $collected = [];

        foreach ([$this->errorLogPath(), $this->logPath()] as $path) {
            if (! is_file($path)) {
                continue;
            }

            $content = trim((string) file_get_contents($path));
            if ($content === '') {
                continue;
            }

            $collected = array_merge($collected, preg_split('/\R/', $content) ?: []);
        }

        return array_slice($collected, -$lines);
    }

    // ------------------------------------------------------------------ lifecycle

    /**
     * Launch the service in the background and wait for it to answer /health.
     *
     * @throws OcrServiceException
     */
    public function start(User $actor): array
    {
        $this->guardManaged();

        if ($this->client->health(fresh: true)['reachable']) {
            throw new OcrServiceException('The OCR service is already running.');
        }

        if (($pid = $this->recordedPid()) !== null && $this->isAlive($pid)) {
            throw new OcrServiceException(
                "A service process (PID {$pid}) is already running but not answering. Stop it first."
            );
        }

        $this->prepareStorage();
        $this->truncateLogs();

        try {
            $pid = windows_os() ? $this->spawnWindows() : $this->spawnPosix();
        } catch (\Throwable $e) {
            // A launcher timeout used to escape this service and become Laravel's
            // unhandled 500 page. Process failures belong in the workspace UI,
            // alongside the service logs that explain how to fix them.
            $this->appendToErrorLog('launcher failed: '.$e->getMessage());

            throw new OcrServiceException(
                'Could not start the OCR service. '.$this->firstLogLine(),
                previous: $e,
            );
        }

        if ($pid === null) {
            throw new OcrServiceException(
                'Could not start the OCR service. '.$this->firstLogLine()
            );
        }

        // Record the child immediately. If Python exits during import, status()
        // removes the stale pidfile; if it stays alive but never becomes healthy,
        // the Stop button can still clean it up instead of orphaning it.
        if ($pid > 0) {
            $this->rememberPid($pid);
        }

        // Model weights are not loaded at boot, so this is only the web server
        // coming up - normally a couple of seconds on a warm start, up to a minute
        // on the first cold start while PyTorch imports.
        if (! $this->waitForHealth()) {
            throw new OcrServiceException(
                'The service was launched but did not answer within '
                .self::STARTUP_TIMEOUT.'s. '.$this->firstLogLine()
            );
        }

        // Prefer the listener in case a launcher or reloader handed work to a
        // child. Normally it is the same PID returned by Start-Process / $!.
        $listener = $this->listenerPid();
        $resolvedPid = $listener ?? ($pid > 0 ? $pid : null);

        if ($resolvedPid !== null && $resolvedPid > 0) {
            $this->rememberPid($resolvedPid);
        }

        $this->audit->log(
            'ocr_engine.started',
            null,
            new: ['pid' => $resolvedPid, 'url' => $this->baseUrl()],
            description: 'Started the OCR service from the OCR workspace.',
            actor: $actor,
        );

        return $this->status();
    }

    /**
     * Stop the service.
     *
     * @throws OcrServiceException
     */
    public function stop(User $actor, bool $force = false): array
    {
        $this->guardManaged();

        $pid = $this->recordedPid();

        if ($pid === null) {
            $listener = $this->listenerPid();

            if ($listener !== null) {
                throw new OcrServiceException(sprintf(
                    'The OCR service on port %d was started outside CRMS (PID %d). '
                    .'Stop it from the terminal or process manager that launched it.',
                    $this->port(),
                    $listener,
                ));
            }

            throw new OcrServiceException(sprintf(
                'Nothing is running on port %d, so there is nothing to stop.',
                $this->port(),
            ));
        }

        $adopted = false;

        // Killing the GPU out from under a running job loses hours of work, so it
        // takes a second, explicit confirmation.
        if (! $force) {
            $health = $this->client->health(fresh: true);
            if ($health['reachable'] && ($health['busy'] ?? false)) {
                $job = $health['job'] ?? [];

                throw new OcrServiceException(sprintf(
                    'A %s job (%s) is still running at %s%%. Cancel it first, or confirm '
                    .'stopping anyway - killing the process loses the run.',
                    $job['type'] ?? 'GPU',
                    $job['id'] ?? '?',
                    $job['percent'] ?? 0,
                ));
            }
        }

        $this->terminate($pid);
        $this->forgetPid();
        $this->client->forgetHealth();

        $this->audit->log(
            'ocr_engine.stopped',
            null,
            old: ['pid' => $pid, 'adopted' => $adopted],
            description: implode(' ', array_filter([
                $force
                    ? 'Force-stopped the OCR service, abandoning any running job.'
                    : 'Stopped the OCR service from the OCR workspace.',
                // Worth recording: this was not a process the app had started.
                $adopted ? "Adopted PID {$pid} from port {$this->port()}." : null,
            ])),
            actor: $actor,
        );

        return $this->status();
    }

    // -------------------------------------------------------------------- spawning

    /**
     * Windows: ask the configured Python interpreter to launch uvicorn as a
     * detached child and print its PID.
     *
     * Using Python avoids Windows PowerShell entirely. On this machine the managed
     * PowerShell host can fail with credential error 8009001d after creating the
     * child, leaving FastAPI online but untracked. The child writes directly to log
     * files, so it inherits none of Symfony Process's pipes.
     */
    private function spawnWindows(): ?int
    {
        $python = $this->resolvedPython();
        $command = [$python, ...$this->arguments()];

        $launcher = <<<'PY'
import json
import subprocess
import sys

command = json.loads(sys.argv[1])
working_directory = sys.argv[2]
stdout_path = sys.argv[3]
stderr_path = sys.argv[4]

with open(stdout_path, "ab", buffering=0) as stdout, open(stderr_path, "ab", buffering=0) as stderr:
    process = subprocess.Popen(
        command,
        cwd=working_directory,
        stdin=subprocess.DEVNULL,
        stdout=stdout,
        stderr=stderr,
        close_fds=True,
        creationflags=subprocess.DETACHED_PROCESS | subprocess.CREATE_NEW_PROCESS_GROUP,
    )

print(process.pid)
PY;

        $process = new Process(
            [
                $python,
                '-c',
                $launcher,
                json_encode($command, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                base_path(),
                $this->logPath(),
                $this->errorLogPath(),
            ],
            base_path(),
            timeout: 30,
        );
        $process->run();

        if (! $process->isSuccessful()) {
            $this->appendToErrorLog(
                'start failed: '.trim($process->getErrorOutput() ?: $process->getOutput())
            );

            return null;
        }

        $pid = (int) trim($process->getOutput());

        return $pid > 0 ? $pid : null;
    }

    /**
     * POSIX: detach with setsid and report the shell's $! back.
     */
    private function spawnPosix(): ?int
    {
        $command = collect([$this->resolvedPython(), ...$this->arguments()])
            ->map(fn (string $part) => escapeshellarg($part))
            ->implode(' ');

        $line = sprintf(
            'setsid %s > %s 2> %s < /dev/null & echo $!',
            $command,
            escapeshellarg($this->logPath()),
            escapeshellarg($this->errorLogPath()),
        );

        $process = Process::fromShellCommandline($line, base_path(), timeout: 30);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->appendToErrorLog(trim($process->getErrorOutput()));

            return null;
        }

        return (int) trim($process->getOutput()) ?: null;
    }

    /**
     * Kill the process and its children. uvicorn may have spawned DataLoader
     * workers, so the whole tree has to go or the GPU stays held.
     */
    private function terminate(int $pid): void
    {
        $command = windows_os()
            ? ['taskkill', '/PID', (string) $pid, '/T', '/F']
            : ['kill', '-TERM', (string) $pid];

        $process = new Process($command, base_path(), timeout: 20);
        $process->run();
    }

    private function isAlive(int $pid): bool
    {
        if (windows_os()) {
            // `tasklist` is first choice, but Windows Store / sandboxed processes may
            // not appear in it for non-elevated callers.
            $tasklist = new Process(
                ['tasklist', '/FI', "PID eq {$pid}", '/NH', '/FO', 'CSV'],
                base_path(),
                timeout: 15,
            );
            $tasklist->run();

            if (str_contains($tasklist->getOutput(), (string) $pid)) {
                return true;
            }

            // `tasklist` can miss WindowsApps processes in a web-server
            // context. Verify the PID through Python's Win32 API instead of the
            // broken managed PowerShell host.
            return $this->windowsProcessPath($pid) !== '';
        }

        // Signal 0 tests for existence without touching the process.
        return function_exists('posix_kill') ? @posix_kill($pid, 0) : true;
    }

    private function waitForHealth(): bool
    {
        $deadline = microtime(true) + self::STARTUP_TIMEOUT;

        while (microtime(true) < $deadline) {
            // Must be fresh: the whole point is that the answer is changing.
            if ($this->client->health(fresh: true)['reachable']) {
                return true;
            }
            usleep(750_000);
        }

        return false;
    }

    // ------------------------------------------------------------------- the command

    /**
     * Resolve the Python executable to a full path on Windows.
     *
     * `Start-Process` does not resolve Windows App Execution Aliases consistently.
     * Asking the configured interpreter for `sys.executable` gives us the real
     * Python binary and, importantly, the same environment in which uvicorn and
     * torch are installed. Fall back to `where` / `which` if that probe fails.
     *
     * The display command still shows the bare name so the UI doesn't print a
     * user-specific AppData path that won't mean anything to someone else.
     */
    private ?string $resolvedPython = null;

    private function python(): string
    {
        return (string) config('services.ocr.python', 'python');
    }

    private function resolvedPython(): string
    {
        if ($this->resolvedPython !== null) {
            return $this->resolvedPython;
        }

        $configured = $this->python();

        // If an explicit executable path was provided, trust it.
        if (str_contains($configured, DIRECTORY_SEPARATOR) && is_file($configured)) {
            return $this->resolvedPython = $configured;
        }

        if (($reported = $this->probePython($configured)) !== null) {
            return $this->resolvedPython = $reported;
        }

        // `where.exe` on Windows can return several installations. Test each one:
        // PHP's bare-command lookup can skip the Windows Store alias and land on
        // another Python that exists but does not have uvicorn installed.
        $finder = windows_os()
            ? ['where.exe', $configured]
            : ['which', $configured];

        $process = new Process($finder, base_path(), timeout: 10);
        $process->run();

        foreach (preg_split('/\R/', $process->getOutput()) ?: [] as $candidate) {
            $candidate = trim($candidate);

            if ($candidate !== '' && ($reported = $this->probePython($candidate)) !== null) {
                return $this->resolvedPython = $reported;
            }
        }

        return $this->resolvedPython = $configured;
    }

    /**
     * Return the real executable behind a Python alias if it has our web runner.
     */
    private function probePython(string $executable): ?string
    {
        $process = new Process(
            [$executable, '-c', 'import sys, uvicorn; print(sys.executable)'],
            base_path(),
            timeout: 15,
        );
        $process->run();

        $reported = trim($process->getOutput());

        return $process->isSuccessful() && $reported !== '' ? $reported : null;
    }

    /**
     * @return list<string>
     */
    private function arguments(): array
    {
        return [
            '-m', 'uvicorn',
            (string) config('services.ocr.module', 'ml.api.main:app'),
            '--host', $this->host(),
            '--port', (string) $this->port(),
        ];
    }

    /**
     * The bind address, forced to loopback.
     *
     * The service has no authentication of its own. Binding it anywhere routable
     * would publish unauthenticated model management - including dataset and model
     * deletion - to the network.
     */
    private function host(): string
    {
        $host = (string) config('services.ocr.host', '127.0.0.1');

        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true) ? $host : '127.0.0.1';
    }

    private function port(): int
    {
        $port = (int) config('services.ocr.port', 8001);

        return $port > 0 && $port <= 65535 ? $port : 8001;
    }

    private function baseUrl(): string
    {
        return (string) config('services.ocr.url', 'http://127.0.0.1:8001');
    }

    /** Shown in the UI so the equivalent manual command is never a mystery. */
    public function displayCommand(): string
    {
        return $this->python().' '.implode(' ', $this->arguments());
    }

    private function isManaged(): bool
    {
        return (bool) config('services.ocr.managed', true);
    }

    private function guardManaged(): void
    {
        if (! $this->isManaged()) {
            throw new OcrServiceException(
                'Process control is disabled (OCR_MANAGED=false). Start and stop the '
                .'service with whatever supervises it.'
            );
        }
    }

    // -------------------------------------------------------------------- pid + logs

    private function directory(): string
    {
        return storage_path('app/ocr-engine');
    }

    private function pidPath(): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.'engine.pid';
    }

    private function logPath(): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.'engine.out.log';
    }

    private function errorLogPath(): string
    {
        // Start-Process refuses to point stdout and stderr at the same file.
        return $this->directory().DIRECTORY_SEPARATOR.'engine.err.log';
    }

    private function prepareStorage(): void
    {
        if (! is_dir($this->directory())) {
            mkdir($this->directory(), 0o755, true);
        }
    }

    private function truncateLogs(): void
    {
        foreach ([$this->logPath(), $this->errorLogPath()] as $path) {
            file_put_contents($path, '');
        }
    }

    private function appendToErrorLog(string $message): void
    {
        if ($message !== '') {
            $this->prepareStorage();
            file_put_contents($this->errorLogPath(), $message.PHP_EOL, FILE_APPEND);
        }
    }

    private function firstLogLine(): string
    {
        $lines = array_values(array_filter(
            array_map('trim', $this->logTail(10)),
            fn (string $line) => $line !== '',
        ));

        return $lines === [] ? 'Check storage/app/ocr-engine/engine.err.log.' : end($lines);
    }

    private function recordedPid(): ?int
    {
        if (! is_file($this->pidPath())) {
            return null;
        }

        $pid = (int) trim((string) file_get_contents($this->pidPath()));

        return $pid > 0 ? $pid : null;
    }

    private function rememberPid(int $pid): void
    {
        $this->prepareStorage();
        file_put_contents($this->pidPath(), (string) $pid);
    }

    private function forgetPid(): void
    {
        if (is_file($this->pidPath())) {
            @unlink($this->pidPath());
        }
    }
}
