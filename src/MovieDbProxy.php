<?php
declare(strict_types=1);

class MovieDbProxy
{
    private string $url;
    private string $cacheDir;
    private string $cacheFile;
    private int $cacheTtl = 300;

    private array $dnsServers = [
        '9.9.9.9', // Quad9
        '1.1.1.1', // Cloudflare
        '64.6.65.6', // Neustar
    ];

    public function __construct(string $url)
    {
        $this->url = $url;

        $this->cacheDir = __DIR__ . '/../cache';
        if (!is_dir($this->cacheDir)) mkdir($this->cacheDir, 0755, true);

        $this->cacheFile = $this->cacheDir . '/last_dns';

        $this->prioritizeLastDns();
    }

    public function fetch(): ?string
    {
        $this->cleanupOldCaches();

        $cached = $this->getCachedResponse($this->url);
        if ($cached !== null) return $cached;

        foreach ($this->dnsServers as $dns) {
            $ips = $this->resolveDomain($dns);
            if (empty($ips)) continue;

            foreach ($ips as $ip) {
                $response = $this->requestApi($ip);
                if ($response !== null) {
                    $this->cacheDns($dns);
                    $this->setCachedResponse($this->url, $response);
                    return $response;
                }
            }
        }

        $this->clearCache();
        return null;
    }

    private function getCachePath(string $url): string
    {
        return $this->cacheDir . '/' . md5($url);
    }

    private function getCachedResponse(string $url): ?string
    {
        $file = $this->getCachePath($url);

        if (!file_exists($file)) return null;

        if (time() - filemtime($file) > $this->cacheTtl) {
            unlink($file);
            return null;
        }

        return file_get_contents($file) ?: null;
    }

    private function setCachedResponse(string $url, string $response): void
    {
        $file = $this->getCachePath($url);
        file_put_contents($file, $response);
    }

    private function cleanupOldCaches(): void
    {
        foreach (glob($this->cacheDir . '/*') as $file) {
            if (basename($file) === 'last_dns') continue;

            if (is_file($file) && (time() - filemtime($file) > $this->cacheTtl)) {
                unlink($file);
            }
        }
    }

    private function prioritizeLastDns(): void
    {
        if (!file_exists($this->cacheFile)) return;

        $lastDns = trim(file_get_contents($this->cacheFile));
        if ($lastDns === '') return;

        $this->dnsServers = array_filter($this->dnsServers, fn($ip) => $ip !== $lastDns);
        array_unshift($this->dnsServers, $lastDns);
    }

    private function resolveDomain(string $dnsServer): array
    {
        $host = parse_url($this->url, PHP_URL_HOST);
        $resolver = new Net_DNS2_Resolver(['nameservers' => [$dnsServer]]);

        try {
            $response = $resolver->query($host, 'A');
        } catch (Exception) {
            return [];
        }

        $ips = [];
        foreach ($response->answer as $record) {
            if ($record->type === 'A' && filter_var($record->address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
                $ips[] = $record->address;
            }
        }

        return $ips;
    }

    private function requestApi(string $ip): ?string
    {
        $host = parse_url($this->url, PHP_URL_HOST);

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_RESOLVE => ["{$host}:443:{$ip}"]
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return $response !== false ? $response : null;
    }

    private function cacheDns(string $dns): void
    {
        file_put_contents($this->cacheFile, $dns);
    }

    private function clearCache(): void
    {
        if (file_exists($this->cacheFile)) unlink($this->cacheFile);
    }
}
