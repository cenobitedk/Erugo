<?php

namespace Tests\Unit;

use App\Utils\AppVersion;
use PHPUnit\Framework\TestCase;

class AppVersionTest extends TestCase
{
    public function test_appends_fork_suffix_to_the_version_tag(): void
    {
        $this->assertSame('1.2.3-cenobitedk', AppVersion::withForkSuffix('1.2.3'));
        $this->assertSame('v1.2.3-cenobitedk', AppVersion::withForkSuffix('v1.2.3'));
        $this->assertSame('RC 0.0.18-cenobitedk', AppVersion::withForkSuffix('RC 0.0.18'));
    }

    public function test_does_not_append_the_suffix_twice(): void
    {
        $this->assertSame('1.2.3-cenobitedk', AppVersion::withForkSuffix('1.2.3-cenobitedk'));
    }

    public function test_empty_version_still_identifies_the_fork(): void
    {
        $this->assertSame('unknown-cenobitedk', AppVersion::withForkSuffix(null));
        $this->assertSame('unknown-cenobitedk', AppVersion::withForkSuffix(''));
        $this->assertSame('unknown-cenobitedk', AppVersion::withForkSuffix('unknown'));
    }
}
