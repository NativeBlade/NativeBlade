<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Commands;

use Illuminate\Console\Command;
use NativeBlade\Commands\Concerns\PublishesAgentContext;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\NullOutput;

/**
 * .nativeblade/CONTEXT.md is the framework's own briefing for AI coding
 * agents. It is written by install and rewritten by update, so a tool that
 * includes it never carries a stale copy of how the installed version works.
 */
final class AgentContextTest extends TestCase
{
    use WithTempBasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTempBasePath();
    }

    protected function tearDown(): void
    {
        $this->tearDownTempBasePath();
        parent::tearDown();
    }

    private function makeCommand(): Command
    {
        $cmd = new class extends Command {
            use PublishesAgentContext;

            protected $signature = 'dummy:context';

            public function publish(): void
            {
                $this->publishAgentContext();
            }
        };
        $cmd->setLaravel($this->app);
        $cmd->setOutput(new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new NullOutput()
        ));

        return $cmd;
    }

    #[Test]
    public function writes_the_context_file_with_the_installed_version(): void
    {
        $this->makeCommand()->publish();

        $path = base_path('.nativeblade/CONTEXT.md');
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);
        self::assertStringStartsWith('# NativeBlade ', $content);
        self::assertStringNotContainsString('{{VERSION}}', $content);
        self::assertStringContainsString('nativeblade:doctor', $content);
        self::assertStringContainsString('Never wait inside PHP', $content);
    }

    #[Test]
    public function rewrites_the_file_on_every_run(): void
    {
        mkdir(base_path('.nativeblade'), 0755, true);
        file_put_contents(base_path('.nativeblade/CONTEXT.md'), 'stale');

        $this->makeCommand()->publish();

        self::assertStringNotContainsString('stale', (string) file_get_contents(base_path('.nativeblade/CONTEXT.md')));
    }
}
