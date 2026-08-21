<?php
declare(strict_types=1);

function hsg_add_action(string $event, callable $callback, int $priority = 10): void {
    $GLOBALS['hsg_hooks'][$event][$priority][] = $callback;
}

function hsg_do_action(string $event, array $payload = []): void {
    $groups = $GLOBALS['hsg_hooks'][$event] ?? [];
    if (!$groups) return;
    ksort($groups);
    foreach ($groups as $callbacks) {
        foreach ($callbacks as $callback) $callback($payload);
    }
}
