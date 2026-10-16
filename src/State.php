<?php
declare(strict_types=1);

class State
{
    private string $routesFile;

    public function __construct(string $stateDir)
    {
        $this->routesFile = rtrim(normalizePath($stateDir), '/') . '/routes.json';
    }

    public function loadRoutes(): array
    {
        if (!file_exists($this->routesFile)) return [];
        $raw = json_decode(file_get_contents($this->routesFile), true);
        return is_array($raw) ? $raw : [];
    }

    public function saveRoutes(array $routes): void
    {
        atomicWrite($this->routesFile, jsonPretty(array_values($routes)));
    }

    public function findRoute(string $hostname): ?array
    {
        foreach ($this->loadRoutes() as $r) {
            if (($r['hostname'] ?? '') === $hostname) return $r;
        }
        return null;
    }

    public function upsertRoute(array $route): void
    {
        withLock($this->routesFile, fn() => $this->upsertUnlocked($route));
    }

    private function upsertUnlocked(array $route): void
    {
        $routes   = $this->loadRoutes();
        $hostname = $route['hostname'];
        $found    = false;
        foreach ($routes as &$r) {
            if ($r['hostname'] === $hostname) {
                $r     = array_merge($r, $route, ['updated_at' => nowUtc()]);
                $found = true;
                break;
            }
        }
        unset($r);
        if (!$found) {
            $route['created_at'] = $route['created_at'] ?? nowUtc();
            $route['updated_at'] = nowUtc();
            $routes[]            = $route;
        }
        $this->saveRoutes($routes);
    }

    public function removeRoute(string $hostname): void
    {
        withLock($this->routesFile, function () use ($hostname) {
            $routes = array_filter($this->loadRoutes(), fn($r) => ($r['hostname'] ?? '') !== $hostname);
            $this->saveRoutes(array_values($routes));
        });
    }
}
