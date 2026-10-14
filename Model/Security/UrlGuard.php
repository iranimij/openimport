<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Security;

use Iranimij\OpenImport\Model\Config;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * Keeps URL sources away from the server's own network (SSRF): only http and https, no credentials in the URL,
 * and the host must resolve to public addresses, unless an administrator allowed the host name under
 * Stores › Configuration › iranimij › OpenImport › Safety. Every redirect hop is checked again by the client.
 */
class UrlGuard
{
    /**
     * @param Config $config
     */
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * Checks a URL and returns the IP address the request must go to (so the checked address is the one used).
     *
     * @param string $url
     * @return array{0: array<string, mixed>, 1: string} parse_url() parts and the IP address to connect to
     * @throws PipelineException
     */
    public function check(string $url): array
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- the framework has no URL parser without a request
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            throw new PipelineException('Enter a full web address, starting with https:// or http://.');
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new PipelineException(sprintf('Only http and https addresses can be read, not %s.', $scheme));
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new PipelineException('Put the user name and password into the source settings, not into the address.');
        }
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $parts['host'] = $host;
        $allowed = in_array($host, $this->config->getAllowedHosts(), true);
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            if (!$allowed && !$this->isPublic($literal)) {
                throw new PipelineException($this->refused($host));
            }

            return [$parts, $literal];
        }
        $addresses = $this->resolve($host);
        if ($addresses === []) {
            throw new PipelineException(sprintf('The host %s could not be found (DNS).', $host));
        }
        foreach ($addresses as $address) {
            if (!$allowed && !$this->isPublic($address)) {
                throw new PipelineException($this->refused($host));
            }
        }

        return [$parts, $addresses[0]];
    }

    /**
     * @param string $address
     * @return bool whether an IP address is public (no private, loopback, link-local or reserved range)
     */
    public function isPublic(string $address): bool
    {
        // IPv4-mapped IPv6 (::ffff:a.b.c.d) is judged as the IPv4 address it carries.
        if (str_contains($address, ':')) {
            $packed = inet_pton($address);
            if ($packed !== false && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
                return $this->isPublic((string) inet_ntop(substr($packed, 12)) ?: '');
            }
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // Ranges PHP's filter lets through: IPv4 link-local, CGNAT, IPv4-mapped IPv6, IPv6 unique local.
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($address);
            $ranges = [['169.254.0.0', 16], ['100.64.0.0', 10], ['0.0.0.0', 8], ['192.0.0.0', 24]];
            foreach ($ranges as [$network, $bits]) {
                if ((($long ^ ip2long($network)) >> (32 - $bits)) === 0) {
                    return false;
                }
            }

            return true;
        }
        $packed = inet_pton($address);
        if ($packed === false) {
            return false;
        }
        $first = ord($packed[0]);

        return ($first & 0xfe) !== 0xfc && !($first === 0xfe && (ord($packed[1]) & 0xc0) === 0x80);
    }

    /**
     * @param string $host
     * @return string[] IPv4 and IPv6 addresses
     */
    private function resolve(string $host): array
    {
        $addresses = [];
        // DNS failures are PHP warnings; a failed lookup is just "not found" here.
        set_error_handler(static fn (): bool => true);
        try {
            foreach ([DNS_A, DNS_AAAA] as $type) {
                // phpcs:ignore Magento2.Functions.DiscouragedFunction -- no framework DNS lookup exists
                $records = dns_get_record($host, $type);
                foreach (is_array($records) ? $records : [] as $record) {
                    $address = $record['ip'] ?? $record['ipv6'] ?? null;
                    if (is_string($address)) {
                        $addresses[] = $address;
                    }
                }
            }
            if ($addresses === []) {
                $addresses = gethostbynamel($host) ?: [];
            }
        } finally {
            restore_error_handler();
        }

        return array_values(array_unique($addresses));
    }

    /**
     * @param string $host
     * @return string
     */
    private function refused(string $host): string
    {
        return sprintf(
            '%s points to a private or local address. OpenImport only reads public addresses, unless an administrator'
            . ' allows this host under Stores › Configuration › iranimij › OpenImport › Safety.',
            $host
        );
    }
}
