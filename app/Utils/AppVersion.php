<?php

namespace App\Utils;

class AppVersion
{
    public const SUFFIX = '-cenobitedk';

    /**
     * Keep the upstream version tag and mark this fork.
     * "1.2.3" becomes "1.2.3-cenobitedk". An empty tag becomes "unknown-cenobitedk".
     */
    public static function withForkSuffix(?string $version): string
    {
        $version = trim((string) $version);

        if ($version === '') {
            $version = 'unknown';
        }

        if (str_ends_with($version, self::SUFFIX)) {
            return $version;
        }

        return $version.self::SUFFIX;
    }
}
