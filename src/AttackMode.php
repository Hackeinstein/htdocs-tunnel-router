<?php
declare(strict_types=1);

/**
 * Under Attack mode per zone. Remembers the security level it replaced so turning it off restores
 * that level, and optionally schedules an automatic revert (enforced by tick()).
 */
class AttackMode
{
    private string $file;

    public function __construct(private Insights $in, string $stateDir, private ActivityLog $log)
    {
        $this->file = rtrim(normalizePath($stateDir), '/') . '/attack.json';
    }

    /** [zoneId => {prev, since, revert_at|null}] for zones this tool put under attack. */
    public function active(): array
    {
        $raw = is_file($this->file) ? json_decode((string)file_get_contents($this->file), true) : null;
        return is_array($raw) ? $raw : [];
    }

    public function enable(string $zoneId, ?int $revertIn): void
    {
        $all = $this->active();
        if ($revertIn !== null && ($revertIn < 300 || $revertIn > 30 * 86400)) throw new RouteException('Auto-revert must be between 5 minutes and 30 days');
        $prev = $all[$zoneId]['prev'] ?? $this->in->securityLevel($zoneId); // keep the original level on re-enable
        if ($prev === 'under_attack') $prev = 'medium';                     // already on outside this tool
        $this->in->setZoneSetting($zoneId, 'security_level', 'under_attack');
        $all[$zoneId] = ['prev' => $prev, 'since' => time(), 'revert_at' => $revertIn ? time() + $revertIn : null];
        $this->save($all);
        $this->log->append('under_attack', $zoneId, true, 'on' . ($revertIn ? ", auto-revert in {$revertIn}s" : '') . "; was $prev");
    }

    public function disable(string $zoneId): void
    {
        $all  = $this->active();
        $prev = $all[$zoneId]['prev'] ?? 'medium';
        $this->in->setZoneSetting($zoneId, 'security_level', $prev);
        unset($all[$zoneId]);
        $this->save($all);
        $this->log->append('under_attack', $zoneId, true, "off; restored $prev");
    }

    /** Revert zones whose timer has run out. Returns [{zone, ok, detail}]. */
    public function sweep(): array
    {
        $done = [];
        foreach ($this->active() as $zoneId => $a) {
            if (empty($a['revert_at']) || $a['revert_at'] > time()) continue;
            try { $this->disable($zoneId); $done[] = ['zone' => $zoneId, 'ok' => true, 'detail' => 'restored ' . $a['prev']]; }
            catch (Throwable $e) { $this->log->append('under_attack', $zoneId, false, 'auto-revert failed: ' . $e->getMessage()); $done[] = ['zone' => $zoneId, 'ok' => false, 'detail' => $e->getMessage()]; }
        }
        return $done;
    }

    private function save(array $all): void
    {
        atomicWrite($this->file, jsonPretty((object)$all));
    }
}
