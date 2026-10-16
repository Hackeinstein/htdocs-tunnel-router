<?php
declare(strict_types=1);

/**
 * Creates and removes the portal's public address: one DNS record pointing at the tunnel and one Apache vhost whose
 * document root is public/ — the only folder of the tool that address can ever serve.
 */
class PortalSetup
{
    public function __construct(private ?CloudflareClient $cf, private VhostManager $vm, private ApacheManager $ap, private State $state, private ActivityLog $log, private string $appRoot) {}

    /** @return array{hostname: string} */
    public function enable(string $zoneId, string $subdomain): array
    {
        $cfg = Config::load();
        if (!$this->cf) throw new RouteException('Cloudflare is not configured yet — add your token in Settings first.', 503);
        if (Config::portalHost() !== '') throw new RouteException('The portal is already on at ' . Config::portalHost() . '. Turn it off first to move it.', 409);
        $subdomain = strtolower(trim($subdomain));
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $subdomain)) throw new RouteException('Pick a name for the portal address, e.g. "files" (letters, digits, hyphens).');
        if ($zoneId === '') throw new RouteException('Choose the domain first.');
        try { [$host] = resolveHostname($this->cf, $zoneId, $subdomain); }
        catch (RuntimeException $e) { throw new RouteException("That domain is not available to your Cloudflare token (zone not found). Reload this page and pick it again, or check the token's Zone → Read permission.", 400); }
        catch (Throwable $e) { throw new RouteException('Could not read your domains from Cloudflare: ' . $e->getMessage(), 502); }
        if (!sanitizeHostname($host)) throw new RouteException("Invalid hostname: $host");
        if ($this->state->findRoute($host)) throw new RouteException("$host is already used by a site. Choose another name.", 409);
        // A block left behind by an earlier failed attempt (it points at our public/ folder, no site owns it) is ours to replace.
        $public = rtrim(normalizePath($this->appRoot), '/') . '/public';
        if ($this->vm->hasBlock($host)) {
            if (!str_contains(str_replace('\\', '/', $this->vm->parseBlocks()[$host] ?? ''), $public)) throw new RouteException("$host is already used in the Apache config by something else. Choose another name.", 409);
            $this->vm->removeBlock($host);
        }

        $existing = $this->cf->getDnsRecord($zoneId, $host);
        $ours = $cfg['cloudflare']['tunnel_id'] . '.cfargotunnel.com';
        if ($existing && ($existing['content'] ?? '') !== $ours) throw new RouteException("$host already exists in Cloudflare and points somewhere else (" . ($existing['content'] ?? '?') . '). Choose another name.', 409);
        $rec = $existing ?: $this->cf->createCname($zoneId, $host);

        $undo = function () use ($host, $zoneId, $rec, $existing) {              // put everything back the way it was, whatever step failed
            try { if ($this->vm->hasBlock($host)) $this->vm->removeBlock($host); } catch (Throwable) {}
            if (!$existing) { try { $this->cf->deleteDnsRecord($zoneId, $rec['id']); } catch (Throwable) {} }
        };
        try { $this->vm->addBlock($host, $public, false); }
        catch (Throwable $e) { $undo(); throw new RouteException('Could not write the vhosts file — nothing was changed. ' . $e->getMessage(), 500); }
        try { $r = $this->ap->validateAndReload($this->vm); }
        catch (Throwable $e) { $undo(); try { $this->ap->validateAndReload($this->vm); } catch (Throwable) {} throw new RouteException('Apache could not be reloaded — nothing was changed. ' . $e->getMessage(), 500); }
        if (!$r['ok']) { $undo(); throw new RouteException($r['error'] ?? 'Apache rejected the new vhost — nothing was changed.', 500, ['output' => $r['output'] ?? '']); }
        $cfg['portal'] = array_merge($cfg['portal'], ['hostname' => $host, 'zone_id' => $zoneId, 'dns_record_id' => $rec['id']]);
        Config::save($cfg);
        RouteService::dnsChanged();
        $this->log->append('portal_enable', $host, true, 'DNS + vhost created; document root ' . $public);
        return ['hostname' => $host];
    }

    /**
     * Turn the address off. Never gets stuck: each cleanup step is attempted on its own, and the portal is always marked off
     * afterwards so a different address can be chosen. Whatever could not be removed is returned so it can be shown.
     * @return string[] warnings
     */
    public function disable(): array
    {
        $cfg = Config::load(); $host = Config::portalHost(); $warn = [];
        if ($host === '') throw new RouteException('The portal is already off.', 409);
        try { if ($this->vm->hasBlock($host)) { $this->vm->removeBlock($host); $this->ap->validateAndReload($this->vm); } }
        catch (Throwable $e) { $warn[] = 'The Apache entry could not be removed (' . $e->getMessage() . '). Remove the block for ' . $host . ' from your vhosts file by hand.'; }
        $zone = (string)($cfg['portal']['zone_id'] ?? ''); $rec = (string)($cfg['portal']['dns_record_id'] ?? '');
        if ($zone !== '' && $rec !== '') {
            if (!$this->cf) $warn[] = 'Cloudflare is not configured, so the DNS record for ' . $host . ' was not deleted. Delete it in the Cloudflare dashboard.';
            else { try { $this->cf->deleteDnsRecord($zone, $rec); } catch (Throwable $e) { $warn[] = 'The DNS record for ' . $host . ' could not be deleted (' . $e->getMessage() . '). Delete it in the Cloudflare dashboard.'; } }
        }
        $cfg['portal'] = array_merge($cfg['portal'], ['hostname' => '', 'zone_id' => '', 'dns_record_id' => '']);
        Config::save($cfg);
        try { RouteService::dnsChanged(); } catch (Throwable) {}
        $this->log->append('portal_disable', $host, true, $warn ? 'turned off; ' . implode(' ', $warn) : 'DNS + vhost removed');
        return $warn;
    }
}
