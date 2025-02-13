<?php

namespace Acquia\Cli\Helpers;

use Acquia\Cli\Exception\AcquiaCliException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class SshHelper implements LoggerAwareInterface {

  use LoggerAwareTrait;

  /** @var OutputInterface */
  private $output;

  /**
   * @var LocalMachineHelper
   */
  private $localMachineHelper;

  /**
   * SshHelper constructor.
   *
   * @param OutputInterface $output
   * @param LocalMachineHelper $localMachineHelper
   * @param LoggerInterface $logger
   */
  public function __construct(
      OutputInterface $output,
      LocalMachineHelper $localMachineHelper,
      LoggerInterface $logger
  ) {
    $this->output = $output;
    $this->localMachineHelper = $localMachineHelper;
    $this->setLogger($logger);
  }

  /**
   * Execute the command remotely.
   *
   * @param \AcquiaCloudApi\Response\EnvironmentResponse $environment
   * @param array $command_args
   * @param bool $print_output
   * @param int $timeout
   *
   * @return \Symfony\Component\Process\Process
   * @throws \Acquia\Cli\Exception\AcquiaCliException
   */
  public function executeCommand($environment, array $command_args, $print_output = TRUE, $timeout = NULL): Process {
    $command_summary = $this->getCommandSummary($command_args);

    // Remove site_env arg.
    unset($command_args['alias']);
    $process = $this->sendCommandViaSsh($environment, $command_args, $print_output, $timeout);

    $this->logger->debug('Command: {command} [Exit: {exit}]', [
      'env' => $environment->name,
      'command' => $command_summary,
      'exit' => $process->getExitCode(),
    ]);

    if (!$process->isSuccessful() && $process->getExitCode() === 255) {
      throw new AcquiaCliException($process->getOutput() . $process->getErrorOutput());
    }

    return $process;
  }

  /**
   * Sends a command to an environment via SSH.
   *
   * @param \AcquiaCloudApi\Response\EnvironmentResponse $environment
   * @param array $command
   *   The command to be run on the platform.
   * @param $print_output
   *
   * @param int $timeout
   *
   * @return \Symfony\Component\Process\Process
   * @throws \Exception
   */
  protected function sendCommandViaSsh($environment, $command, $print_output, $timeout = NULL): Process {
    $command = array_values($this->getSshCommand($environment, $command));
    $this->localMachineHelper->checkRequiredBinariesExist(['ssh']);

    return $this->localMachineHelper->execute($command, $this->getOutputCallback(), NULL, $print_output, $timeout);
  }

  /**
   * Return the first item of the $command_args that is not an option.
   *
   * @param array $command_args
   *
   * @return string
   */
  private function firstArguments($command_args): string {
    $result = '';
    while (!empty($command_args)) {
      $first = array_shift($command_args);
      if ($first != '' && $first[0] == '-') {
        return $result;
      }
      $result .= " $first";
    }

    return $result;
  }

  /**
   * @return \Closure
   * @throws \Exception
   */
  private function getOutputCallback(): callable {
    if ($this->localMachineHelper->useTty() === FALSE) {
      $output = $this->output;

      return static function ($type, $buffer) use ($output) {
        $output->write($buffer);
      };
    }

    return static function ($type, $buffer) {};
  }

  /**
   * Return a summary of the command that does not include the
   * arguments. This avoids potential information disclosure in
   * CI scripts.
   *
   * @param array $command_args
   *
   * @return string
   */
  private function getCommandSummary($command_args): string {
    return $this->firstArguments($command_args);
  }

  /**
   * @param $url
   *
   * @return array SSH connection string
   */
  private function getConnectionArgs($url): array {
    return [
      'ssh',
      $url,
      '-t',
      '-o StrictHostKeyChecking=no',
      '-o AddressFamily inet',
      '-o LogLevel=ERROR',
    ];
  }

  /**
   * @param \AcquiaCloudApi\Response\EnvironmentResponse $environment
   * @param $command
   *
   * @return array
   */
  protected function getSshCommand($environment, $command): array {
    return array_merge($this->getConnectionArgs($environment->sshUrl), $command);
  }

}
