<?php
/**
 * IP addresses and CIDR ranges for Lockdown.
 *
 * @package WPEasy\ShipStatic
 * @since   2.3.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Lockdown;

defined('ABSPATH') || exit;

/**
 * Parses, normalises and matches IPv4/IPv6 ranges. Everything that reaches a
 * generated file goes through parse(), which rebuilds the text from the binary
 * address, so raw input never reaches `.htaccess` or the gate.
 */
final class Cidr {

    /**
     * Normalise an address or range to "network/prefix", or null when invalid.
     * A bare address becomes /32 (IPv4) or /128 (IPv6); host bits are cleared.
     *
     * @param string $input E.g. "203.0.113.7", "203.0.113.0/24", "2001:db8::/64".
     */
    public static function parse(string $input): ?string {
        $input = trim($input);
        if (!preg_match('#^([0-9A-Fa-f:.]+)(?:/(\d{1,3}))?$#', $input, $m)) {
            return null;
        }

        $bin = self::to_binary($m[1]);
        if ($bin === null) {
            return null;
        }

        $max    = strlen($bin) * 8;
        $prefix = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $max;
        if ($prefix < 1 || $prefix > $max) {
            return null;
        }

        return inet_ntop(self::mask($bin, $prefix)) . '/' . $prefix;
    }

    /**
     * The range an unlock-link visitor is added as: the address itself for
     * IPv4, its /64 for IPv6 (IPv6 addresses change within a /64 every day).
     *
     * @param string $ip Remote address.
     */
    public static function for_visitor(string $ip): ?string {
        $bin = self::to_binary($ip);
        if ($bin === null) {
            return null;
        }

        return self::parse(inet_ntop($bin) . (strlen($bin) === 4 ? '/32' : '/64'));
    }

    /**
     * Whether an address falls inside a normalised range.
     *
     * @param string $cidr From parse().
     * @param string $ip   Address.
     */
    public static function contains(string $cidr, string $ip): bool {
        [$net, $prefix] = explode('/', $cidr) + ['', ''];
        $net_bin        = self::to_binary($net);
        $ip_bin         = self::to_binary($ip);
        if ($net_bin === null || $ip_bin === null || strlen($net_bin) !== strlen($ip_bin)) {
            return false;
        }

        return self::mask($ip_bin, (int) $prefix) === $net_bin;
    }

    /**
     * Whether an address is in any of the ranges.
     *
     * @param array<int,string> $cidrs Normalised ranges.
     * @param string            $ip    Address.
     */
    public static function any(array $cidrs, string $ip): bool {
        foreach ($cidrs as $cidr) {
            if (self::contains($cidr, $ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A regular expression matching every address in the range as a web server
     * writes it in REMOTE_ADDR (inet_ntop form: lowercase, no leading zeros,
     * the longest run of 2+ zero groups as "::"). For mod_rewrite, which has no
     * CIDR matching that LiteSpeed is known to support.
     *
     * @param string $cidr From parse().
     */
    public static function regex(string $cidr): string {
        [$net, $prefix] = explode('/', $cidr);
        $bin            = (string) self::to_binary($net);
        $prefix         = (int) $prefix;

        return strlen($bin) === 4 ? self::regex_v4($bin, $prefix) : self::regex_v6($bin, $prefix);
    }

    /**
     * IPv4: whole octets literally, a partial octet as an alternation of its
     * values, the rest as any octet. An IPv4-mapped IPv6 form is accepted too.
     *
     * @param string $bin    4-byte network address.
     * @param int    $prefix Prefix length.
     */
    private static function regex_v4(string $bin, int $prefix): string {
        $parts = [];
        for ($i = 0; $i < 4; $i++) {
            $octet = ord($bin[$i]);
            $bits  = max(0, min(8, $prefix - $i * 8));
            if ($bits === 8) {
                $parts[] = (string) $octet;
            } elseif ($bits === 0) {
                $parts[] = '\d{1,3}';
            } else {
                $parts[] = '(' . implode('|', range($octet, $octet + (1 << (8 - $bits)) - 1)) . ')';
            }
        }

        return '^(::ffff:)?' . implode('\.', $parts) . '$';
    }

    /**
     * IPv6, for prefixes on a group boundary (/16 … /128; other prefixes are
     * widened to the boundary below — a wider match, never a narrower one).
     * Every way inet_ntop can print an address in the range is an alternative:
     * the fixed groups written out, or with a run of zero groups compressed.
     *
     * @param string $bin    16-byte network address.
     * @param int    $prefix Prefix length.
     */
    private static function regex_v6(string $bin, int $prefix): string {
        $groups = array_values((array) unpack('n8', $bin));
        $fixed  = intdiv($prefix, 16);
        if ($fixed === 0) {
            return '^[0-9a-f:]+$';
        }
        $hex = array_map(static fn(int $g): string => dechex($g), array_slice($groups, 0, $fixed));

        $any  = '[0-9a-f]{1,4}';
        $alts = [];

        // No compression inside the fixed part: all fixed groups written out.
        // (A "::" can still follow, compressing zeros in the free part.)
        $head   = implode(':', $hex);
        $alts[] = $head . ($fixed === 8 ? '$' : ':(:|' . $any . ')');

        // A zero run starting inside the fixed part, compressed. "::" doesn't
        // say how long the run is; the number of groups after it does (8 in
        // all), so each alternative pins that count.
        $free = 8 - $fixed;
        for ($a = 0; $a < $fixed; $a++) {
            for ($b = $a; $b < $fixed; $b++) {
                if ($groups[$b] !== 0) {
                    break;
                }
                $before = implode(':', array_slice($hex, 0, $a));
                if ($b === $fixed - 1) {
                    // The run covers the rest of the fixed part and may continue
                    // into the free part: at most $free groups follow.
                    $alts[] = $before . '::' . ($free > 0 ? '(' . $any . '(:' . $any . '){0,' . ($free - 1) . '})?' : '') . '$';
                } elseif ($b - $a >= 1) {
                    // The run ends inside the fixed part: exactly the rest follows.
                    $after  = implode(':', array_slice($hex, $b + 1));
                    $alts[] = $before . '::' . $after . ($free > 0 ? '(:' . $any . '){' . $free . '}' : '') . '$';
                }
            }
        }

        $alts = array_values(array_unique($alts));

        return '^(' . implode('|', array_map(static fn(string $s): string => str_replace('.', '\.', $s), $alts)) . ')';
    }

    /**
     * Packed address, with IPv4-mapped IPv6 reduced to IPv4.
     *
     * @param string $ip Address text.
     */
    private static function to_binary(string $ip): ?string {
        $bin = @inet_pton(trim($ip)); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input is expected; false is handled.
        if ($bin === false) {
            return null;
        }
        if (strlen($bin) === 16 && strncmp($bin, str_repeat("\0", 10) . "\xff\xff", 12) === 0) {
            return substr($bin, 12);
        }

        return $bin;
    }

    /**
     * Clear the host bits.
     *
     * @param string $bin    Packed address.
     * @param int    $prefix Prefix length.
     */
    private static function mask(string $bin, int $prefix): string {
        $out = '';
        for ($i = 0, $n = strlen($bin); $i < $n; $i++) {
            $bits = max(0, min(8, $prefix - $i * 8));
            $out .= chr(ord($bin[$i]) & ((0xff << (8 - $bits)) & 0xff));
        }

        return $out;
    }
}
