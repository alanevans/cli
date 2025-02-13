<?php

namespace Acquia\Cli\Tests\Commands;

use Acquia\Cli\Command\NewCommand;
use Acquia\Cli\Tests\CommandTestBase;
use Prophecy\Prophecy\ObjectProphecy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

/**
 * Class NewCommandTest.
 *
 * @property \Acquia\Cli\Command\NewCommand $command
 * @package Acquia\Cli\Tests\Commands
 */
class NewCommandTest extends CommandTestBase {

  protected $newProjectDir;

  /**
   * {@inheritdoc}
   */
  protected function createCommand(): Command {
    return $this->injectCommand(NewCommand::class);
  }

  public function provideTestNewCommand(): array {
    return [
      ['acquia/drupal-recommended-project'],
      ['acquia/drupal-minimal-project'],
      ['acquia/drupal-minimal-project', 'test-dir'],
    ];
  }

  /**
   * Tests the 'new' command.
   *
   * @dataProvider provideTestNewCommand
   *
   * @param string $project
   * @param string $directory
   *
   * @throws \Exception
   */
  public function testNewCommand(string $project, string $directory = 'drupal'): void {
    $this->newProjectDir = Path::makeAbsolute($directory, $this->projectFixtureDir);

    $process = $this->prophet->prophesize(Process::class);
    $process->isSuccessful()->willReturn(TRUE);
    $process->getExitCode()->willReturn(0);

    $local_machine_helper = $this->mockLocalMachineHelper();

    $this->mockGetFilesystem($local_machine_helper);
    $local_machine_helper->checkRequiredBinariesExist(["composer"])->shouldBeCalled();
    $this->mockExecuteComposerCreate($this->newProjectDir, $local_machine_helper, $process, $project);
    $local_machine_helper->checkRequiredBinariesExist(["git"])->shouldBeCalled();
    $this->mockExecuteGitInit($local_machine_helper, $this->newProjectDir, $process);
    $this->mockExecuteGitAdd($local_machine_helper, $this->newProjectDir, $process);
    $this->mockExecuteGitCommit($local_machine_helper, $this->newProjectDir, $process);

    $this->command->localMachineHelper = $local_machine_helper->reveal();
    $inputs = [
      // Choose a starting project
      $project,
    ];
    $this->executeCommand([
      'directory' => $directory,
    ], $inputs);
    $this->prophet->checkPredictions();
    $output = $this->getDisplay();
    $this->assertStringContainsString('Choose a starting project', $output);
    $this->assertStringContainsString($project, $output);
    $this->assertStringContainsString('New 💧 Drupal project created in ' . $this->newProjectDir, $output);

  }

  /**
   * @param string $project_dir
   * @param \Prophecy\Prophecy\ObjectProphecy $local_machine_helper
   * @param \Prophecy\Prophecy\ObjectProphecy $process
   * @param string $project
   *
   * @return void
  */
  protected function mockExecuteComposerCreate(
    string $project_dir,
    ObjectProphecy $local_machine_helper,
    ObjectProphecy $process,
    $project
  ): void {
    $command = [
      'composer',
      'create-project',
      $project,
      $project_dir,
      '--no-interaction',
    ];
    $local_machine_helper
      ->execute($command)
      ->willReturn($process->reveal())
      ->shouldBeCalled();
  }

  /**
   * @param \Prophecy\Prophecy\ObjectProphecy $local_machine_helper
   * @param string $project_dir
   * @param \Prophecy\Prophecy\ObjectProphecy $process
   *
   * @return void
  */
  protected function mockExecuteGitInit(
    ObjectProphecy $local_machine_helper,
    string $project_dir,
    ObjectProphecy $process
  ) {
    $command = [
      'git',
      'init',
      '--initial-branch=main',
    ];
    $local_machine_helper
      ->execute($command, NULL, $project_dir)
      ->willReturn($process->reveal())
      ->shouldBeCalled();
  }

  /**
   * @param \Prophecy\Prophecy\ObjectProphecy $local_machine_helper
   * @param string $project_dir
   * @param \Prophecy\Prophecy\ObjectProphecy $process
   *
   * @return void
  */
  protected function mockExecuteGitAdd(
    ObjectProphecy $local_machine_helper,
    string $project_dir,
    ObjectProphecy $process
  ): void {
    $command = [
      'git',
      'add',
      '-A',
    ];
    $local_machine_helper
      ->execute($command, NULL, $project_dir)
      ->willReturn($process->reveal())
      ->shouldBeCalled();
  }

  /**
   * @param \Prophecy\Prophecy\ObjectProphecy $local_machine_helper
   * @param string $project_dir
   * @param \Prophecy\Prophecy\ObjectProphecy $process
   */
  protected function mockExecuteGitCommit(
    ObjectProphecy $local_machine_helper,
    string $project_dir,
    ObjectProphecy $process
  ): void {
    $command = [
      'git',
      'commit',
      '--message',
      'Initial commit.',
      '--quiet',
    ];
    $local_machine_helper
      ->execute($command, NULL, $project_dir)
      ->willReturn($process->reveal())
      ->shouldBeCalled();
  }

}
