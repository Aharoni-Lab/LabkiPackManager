<?php

declare(strict_types=1);

namespace LabkiPackManager\Util;

/**
 * SemVer
 *
 * Utility class for parsing and comparing semantic version strings.
 * Supports common version formats including prefixes, suffixes, and build metadata.
 *
 * Supported formats:
 *   - Standard: "1.2.3"
 *   - With prefix: "v1.2.3"
 *   - Pre-release: "1.2.3-alpha", "1.2.3-rc.1"
 *   - Build metadata: "1.2.3+build.5"
 *   - Combined: "v1.2.3-rc.1+build.5"
 *   - Partial: "1", "1.2"
 *   - With text: "1.2.3beta", "1.2.x"
 *
 * Note: Pre-release and build metadata are stripped during comparison.
 * This means "1.2.3-alpha" and "1.2.3+build" are considered equal.
 *
 * Usage:
 *   $parsed = SemVer::parse('v1.2.3-alpha');  // [1, 2, 3]
 *   $cmp = SemVer::compare('1.2.3', '1.2.4'); // -1 (first is less)
 *   $same = SemVer::sameMajor('1.0.0', '1.9.9'); // true
 */
final class SemVer {

    /**
     * Parse a version string into [major, minor, patch] components.
     *
     * Handles various formats and normalizes to three integers.
     * Invalid or missing components default to 0.
     *
     * Examples:
     *   parse('1.2.3')           → [1, 2, 3]
     *   parse('v1.2.3')          → [1, 2, 3]
     *   parse('1.2.3-alpha')     → [1, 2, 3]
     *   parse('1.2.3+build.5')   → [1, 2, 3]
     *   parse('1.2')             → [1, 2, 0]
     *   parse('1')               → [1, 0, 0]
     *   parse(null)              → [0, 0, 0]
     *   parse('')                → [0, 0, 0]
     *
     * @param string|null $v Version string to parse
     * @return array{int,int,int} Tuple of [major, minor, patch]
     */
    public static function parse(?string $v): array {
        // Handle null or empty input
        if ($v === null || $v === '') {
            return [0, 0, 0];
        }

        $v = trim((string)$v);

        // Strip leading 'v' or 'V' prefix
        $v = preg_replace('/^[vV]/', '', $v);

        // Strip pre-release and build metadata (anything after +/-)
        $v = preg_split('/[+-]/', $v)[0] ?? $v;

        // Split into parts by dots
        $parts = explode('.', $v);

        // Extract numeric values from each part, stripping non-digits
        $maj = isset($parts[0]) ? (int)preg_replace('/\D/', '', $parts[0]) : 0;
        $min = isset($parts[1]) ? (int)preg_replace('/\D/', '', $parts[1]) : 0;
        $pat = isset($parts[2]) ? (int)preg_replace('/\D/', '', $parts[2]) : 0;

        return [$maj, $min, $pat];
    }

    /**
     * Compare two version strings.
     *
     * Returns:
     *   -1 if $a < $b
     *    0 if $a == $b
     *    1 if $a > $b
     *
     * Comparison is done numerically on [major, minor, patch] components.
     * Pre-release and build metadata are ignored.
     *
     * Examples:
     *   compare('1.2.3', '1.2.3')        → 0
     *   compare('1.2.3', '1.2.4')        → -1
     *   compare('1.3.0', '1.2.9')        → 1
     *   compare('2.0.0', '1.9.9')        → 1
     *   compare('1.2.3-alpha', '1.2.3')  → 0 (suffixes ignored)
     *
     * @param string|null $a First version string
     * @param string|null $b Second version string
     * @return int -1, 0, or 1
     */
    public static function compare(?string $a, ?string $b): int {
        [$A, $B, $C] = self::parse($a);
        [$X, $Y, $Z] = self::parse($b);

        // Compare major version
        if ($A !== $X) {
            return $A <=> $X;
        }

        // Compare minor version
        if ($B !== $Y) {
            return $B <=> $Y;
        }

        // Compare patch version
        if ($C !== $Z) {
            return $C <=> $Z;
        }

        return 0;
    }

    /**
     * Check if two versions have the same major version number.
     *
     * Useful for determining API compatibility (e.g., semver major version changes
     * typically indicate breaking changes).
     *
     * Examples:
     *   sameMajor('1.0.0', '1.9.9')  → true
     *   sameMajor('1.0.0', '2.0.0')  → false
     *   sameMajor('v1.2.3', '1.5.0') → true
     *   sameMajor(null, '0.1.0')     → true (null → 0.0.0)
     *   sameMajor(null, '1.0.0')     → false
     *
     * @param string|null $a First version string
     * @param string|null $b Second version string
     * @return bool True if major versions match
     */
    public static function sameMajor(?string $a, ?string $b): bool {
        return self::parse($a)[0] === self::parse($b)[0];
    }

    /**
     * Check if version $a is greater than version $b.
     *
     * @param string|null $a First version string
     * @param string|null $b Second version string
     * @return bool True if $a > $b
     */
    public static function greaterThan(?string $a, ?string $b): bool {
        return self::compare($a, $b) > 0;
    }

    /**
     * Check if version $a is less than version $b.
     *
     * @param string|null $a First version string
     * @param string|null $b Second version string
     * @return bool True if $a < $b
     */
    public static function lessThan(?string $a, ?string $b): bool {
        return self::compare($a, $b) < 0;
    }

    /**
     * Check if version $a is equal to version $b.
     *
     * Note: Pre-release and build metadata are ignored, so
     * "1.2.3-alpha" equals "1.2.3+build".
     *
     * @param string|null $a First version string
     * @param string|null $b Second version string
     * @return bool True if $a == $b
     */
    public static function equals(?string $a, ?string $b): bool {
        return self::compare($a, $b) === 0;
    }

    /**
     * Format a parsed version array back into a string.
     *
     * @param array{int,int,int} $parts Version components [major, minor, patch]
     * @return string Formatted version string (e.g., "1.2.3")
     */
    public static function format(array $parts): string {
        return implode('.', $parts);
    }
}


