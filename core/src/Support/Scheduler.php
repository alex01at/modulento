<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use Closure;
use LogicException;
use Modulento\Core\App;
use PDO;
use Throwable;

/**
 * Everything time-driven (deadlines, clean-ups, later payouts and automatic
 * order transitions) is a task registered here and run by bin/cron.php from
 * the system cron. Nothing time-driven may ever hang on a page view.
 */
final class Scheduler
{
    private const LOCK_NAME = 'modulento_scheduler';

    /** @var array<string, array{interval: int, handler: Closure}> */
    private array $tasks = [];

    public function __construct(private PDO $db)
    {
    }

    /** @param Closure(App): void $handler */
    public function register(string $name, int $everyMinutes, Closure $handler): void
    {
        if (isset($this->tasks[$name])) {
            throw new LogicException("Task \"{$name}\" is already registered");
        }
        if ($everyMinutes < 1) {
            throw new LogicException("Task \"{$name}\" needs an interval of at least one minute");
        }

        $this->tasks[$name] = ['interval' => $everyMinutes, 'handler' => $handler];
    }

    /** @return string[] */
    public function taskNames(): array
    {
        return array_keys($this->tasks);
    }

    /**
     * Runs every task whose interval has elapsed. A failing task is
     * recorded and does not stop the others.
     *
     * @return array<string, string> task name => "ok" or the error message
     */
    public function runDue(App $app): array
    {
        // Two overlapping cron invocations must not run the same task twice.
        if ((int) $this->db->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)")->fetchColumn() !== 1) {
            return [];
        }

        try {
            $lastStarted = $this->db
                ->query('SELECT name, last_started_at FROM task_run')
                ->fetchAll(PDO::FETCH_KEY_PAIR);

            $results = [];
            foreach ($this->tasks as $name => $task) {
                if (!self::isDue($lastStarted[$name] ?? null, $task['interval'], time())) {
                    continue;
                }
                $results[$name] = $this->run($name, $task['handler'], $app);
            }

            return $results;
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }
    }

    public static function isDue(?string $lastStartedAt, int $everyMinutes, int $now): bool
    {
        if ($lastStartedAt === null) {
            return true;
        }

        // A few seconds of tolerance, so a task with a 5-minute interval
        // is not skipped by a cron run that starts 4:59 after the last one.
        return strtotime($lastStartedAt . ' UTC') + $everyMinutes * 60 - 30 <= $now;
    }

    private function run(string $name, Closure $handler, App $app): string
    {
        $start = $this->db->prepare(
            "INSERT INTO task_run (name, last_started_at, last_status) VALUES (:name, NOW(), 'running')
             ON DUPLICATE KEY UPDATE last_started_at = NOW(), last_status = 'running', last_error = NULL"
        );
        $start->execute(['name' => $name]);

        $status = 'ok';
        $error = null;

        try {
            $handler($app);
        } catch (Throwable $e) {
            $status = 'failed';
            $error = $e::class . ': ' . $e->getMessage();
        }

        $finish = $this->db->prepare(
            'UPDATE task_run SET last_finished_at = NOW(), last_status = :status, last_error = :error WHERE name = :name'
        );
        $finish->execute(['status' => $status, 'error' => $error, 'name' => $name]);

        return $error ?? 'ok';
    }
}
