<?php

namespace App\Concerns;

use Illuminate\Console\View\TaskResult;

trait RunsTasks
{
    /**
     * Run components->task() with a bool-returning callback, correctly
     * rendering DONE/FAIL. Illuminate\Console\View\Components\Task only
     * shows FAIL when the callback returns TaskResult::Failure->value —
     * a plain `false` falls through to the default DONE branch.
     */
    protected function runTask(string $label, callable $callback): bool
    {
        $ok = false;

        $this->components->task($label, function () use ($callback, &$ok) {
            $ok = (bool) $callback();

            return $ok ? TaskResult::Success->value : TaskResult::Failure->value;
        });

        return $ok;
    }
}
