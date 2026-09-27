<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Desk;

use Neuedaten\FreezedDesk\Config\DeskConfig;
use PHPUnit\Framework\TestCase;

/**
 * The project's name in the header of the UI: desk.projectName, else the
 * site's siteName, else the project folder.
 */
final class ProjectNameTest extends TestCase
{
    public function testConfiguredNameComesFirst(): void
    {
        self::assertSame('Perlen', (new DeskConfig('/work/perlen-website', ['projectName' => ' Perlen '], 'perlen.an.der.ruhr'))->projectName());
    }

    public function testSiteNameWhenNothingIsConfigured(): void
    {
        self::assertSame('perlen.an.der.ruhr', (new DeskConfig('/work/perlen-website', [], 'perlen.an.der.ruhr'))->projectName());
    }

    public function testFolderNameAsLastResort(): void
    {
        self::assertSame('perlen-social-test', (new DeskConfig('/work/perlen-social-test/', ['projectName' => ''], '  '))->projectName());
    }
}
