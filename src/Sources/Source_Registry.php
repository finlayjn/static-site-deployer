<?php

namespace SSD\Sources;

use SSD\Settings;

/**
 * Holds the available export sources and resolves the active one.
 *
 * The built-in crawler works everywhere (and is required in WordPress
 * Playground); Simply Static is offered on hosts where it is installed.
 */
class Source_Registry
{
    /** @var Export_Source[]|null */
    private static $sources = null;

    /**
     * @return Export_Source[]
     */
    public static function all(): array
    {
        if (null === self::$sources) {
            self::$sources = [
                new Crawler_Source(),
                new Simply_Static_Source(),
            ];
        }
        return self::$sources;
    }

    /**
     * Returns a source by slug, or null when unknown.
     */
    public static function get(string $slug): ?Export_Source
    {
        foreach (self::all() as $source) {
            if ($source->slug() === $slug) {
                return $source;
            }
        }
        return null;
    }

    /**
     * Resolves the source that should handle exports.
     *
     * Playground always uses the crawler. Otherwise an explicit, available
     * choice wins; failing that we default to Simply Static when installed and
     * the crawler as the universal fallback.
     */
    public static function active(): Export_Source
    {
        if (Settings::is_playground()) {
            return self::get('crawler') ?? self::all()[0];
        }

        $chosen = self::get((string) Settings::get('export_source'));
        if (null !== $chosen && $chosen->is_available()) {
            return $chosen;
        }

        $simply_static = self::get('simply_static');
        if (null !== $simply_static && $simply_static->is_available()) {
            return $simply_static;
        }

        return self::get('crawler') ?? self::all()[0];
    }
}

