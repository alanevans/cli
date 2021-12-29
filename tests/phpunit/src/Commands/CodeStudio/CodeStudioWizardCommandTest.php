<?php

namespace Acquia\Cli\Tests\Commands\CodeStudio;

use Acquia\Cli\Command\CodeStudio\CodeStudioWizardCommand;
use Acquia\Cli\Command\Ide\Wizard\IdeWizardCreateSshKeyCommand;
use Acquia\Cli\Command\Ssh\SshKeyCreateCommand;
use Acquia\Cli\Command\Ssh\SshKeyDeleteCommand;
use Acquia\Cli\Command\Ssh\SshKeyUploadCommand;
use Acquia\Cli\Tests\Commands\Ide\IdeRequiredTestTrait;
use Acquia\Cli\Tests\Commands\WizardTestBase;
use Acquia\Cli\Tests\TestBase;
use Gitlab\Api\Projects;
use Gitlab\Api\Schedules;
use Gitlab\Api\Users;
use Gitlab\Client;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

/**
 * Class CodeStudioWizardCommandTest.
 *
 * @property \Acquia\Cli\Command\CodeStudio\CodeStudioWizardCommand $command
 * @package Acquia\Cli\Tests\Commands
 */
class CodeStudioWizardCommandTest extends WizardTestBase {

  use IdeRequiredTestTrait;

  private $gitlabHost = 'gitlabhost';
  private $gitlabToken = 'gitlabtoken';

  private $gitLabProjectId = 33;
  private $gitLabTokenId = 118;

  public function setUp($output = NULL): void {
    parent::setUp($output);
    $this->mockApplicationRequest();
    $this->mockListSshKeysRequest();
    $this->sshKeyFileName = CodeStudioWizardCommand::getSshKeyFilename(WizardTestBase::$application_uuid);
    $this->passphraseFilepath = '~/.codestudio-passphrase';
    IdeRequiredTestTrait::setCloudIdeEnvVars();
    TestBase::setEnvVars(['GITLAB_HOST' => 'code.cloudservices.acquia.io']);
  }

  public function tearDown(): void {
    parent::tearDown();
    IdeRequiredTestTrait::unsetCloudIdeEnvVars();
    TestBase::unsetEnvVars(['GITLAB_HOST' => 'code.cloudservices.acquia.io']);
  }

  /**
   * {@inheritdoc}
   */
  protected function createCommand(): Command {
    return $this->injectCommand(CodeStudioWizardCommand::class);
  }

  public function testCommand() {
    $environments_response = $this->getMockEnvironmentsResponse();
    $selected_environment = $environments_response->_embedded->items[0];
    $this->clientProphecy->request('get', "/applications/{$this::$application_uuid}/environments")->willReturn($environments_response->_embedded->items)->shouldBeCalled();
    $this->mockAccountRequest();

    $permissions_response = $this->getMockResponseFromSpec('/applications/{applicationUuid}/permissions', 'get', 200);
    $permissions = $permissions_response->_embedded->items;
    $permission = reset($permissions);
    $permission->name = "administer environment variables on non-prod";
    $permissions[] = $permission;
    $this->clientProphecy->request('get', "/applications/{$this::$application_uuid}/permissions")->willReturn($permissions)->shouldBeCalled();

    // List uploaded keys.
    $this->mockUploadSshKey();

    $gitlab_client = $this->prophet->prophesize(Client::class);
    $this->mockGitLabUsersMe($gitlab_client);
    $projects = $this->prophet->prophesize(Projects::class);
    $this->mockGitLabProjectsAll($this::$application_uuid, $projects);
    $this->mockGitLabProjectsTokens($projects);
    $projects->update($this->gitLabProjectId, Argument::type('array'));
    $this->mockGitLabVariables($this::$application_uuid, $projects);
    $schedules = $this->prophet->prophesize(Schedules::class);
    $schedules->showAll($this->gitLabProjectId)->willReturn([]);
    $schedules->create($this->gitLabProjectId, Argument::type('array'));
    $gitlab_client->schedules()->willReturn($schedules->reveal());
    $gitlab_client->projects()->willReturn($projects);

    $this->command->setGitLabClient($gitlab_client->reveal());

    $local_machine_helper = $this->mockLocalMachineHelper();
    $local_machine_helper->checkRequiredBinariesExist(['git']);
    $process = $this->mockProcess();
    $local_machine_helper->execute(Argument::containing('clone'), Argument::type('callable'), NULL, FALSE)->willReturn($process->reveal());
    $local_machine_helper->execute(Argument::containing('push'), Argument::type('callable'), Argument::type('string'), FALSE)->willReturn($process->reveal());

    $this->mockGlabConfig($local_machine_helper);
    $this->mockGlabConfigGetToken($local_machine_helper, $this->gitlabHost);

    // Poll Cloud.
    $ssh_helper = $this->mockPollCloudViaSsh($selected_environment);
    $this->command->sshHelper = $ssh_helper->reveal();

    /** @var Filesystem|ObjectProphecy $file_system */
    $file_system = $this->prophet->prophesize(Filesystem::class);
    $this->mockGenerateSshKey($local_machine_helper, $file_system);
    $file_system->remove(Argument::size(2))->shouldBeCalled();
    $this->mockAddSshKeyToAgent($local_machine_helper, $file_system);
    $this->mockSshAgentList($local_machine_helper);
    $local_machine_helper->getFilesystem()->willReturn($file_system->reveal())->shouldBeCalled();
    $this->command->localMachineHelper = $local_machine_helper->reveal();
    $this->application->find(SshKeyCreateCommand::getDefaultName())->localMachineHelper = $this->command->localMachineHelper;
    $this->application->find(SshKeyUploadCommand::getDefaultName())->localMachineHelper = $this->command->localMachineHelper;
    $this->application->find(SshKeyDeleteCommand::getDefaultName())->localMachineHelper = $this->command->localMachineHelper;

    // Remove SSH key if it exists.
    $this->fs->remove(Path::join(sys_get_temp_dir(), $this->sshKeyFileName));

    // Set properties and execute.
    $this->executeCommand([
      '--key' => $this->key,
      '--secret' => $this->secret,
    ], [
      // Would you like to link the project at ... ?
      'y',
    ]);

    // Assertions.
    $this->assertEquals(0, $this->getStatusCode());
  }

  protected function mockGlabConfig(ObjectProphecy $local_machine_helper): ObjectProphecy {
    $process = $this->prophet->prophesize(Process::class);
    $process->isSuccessful()->willReturn(TRUE);
    $process->getExitCode()->willReturn(0);
    $process->getOutput()->willReturn($this->gitlabHost);
    $local_machine_helper->execute([
      'glab',
      'config',
      'get',
      'host',
    ], NULL, NULL, FALSE)->willReturn($process->reveal())->shouldBeCalled();
    return $process;
  }

  protected function mockGlabConfigGetToken(ObjectProphecy $local_machine_helper, $gitlab_host): ObjectProphecy {
    $process = $this->prophet->prophesize(Process::class);
    $process->isSuccessful()->willReturn(TRUE);
    $process->getExitCode()->willReturn(0);
    $process->getOutput()->willReturn($this->gitlabToken);
    $local_machine_helper->execute([
      'glab',
      'config',
      'get',
      'token',
      '--host=' . $gitlab_host,
    ], NULL, NULL, FALSE)->willReturn($process->reveal())->shouldBeCalled();
    return $process;
  }

  /**
   * @param $application_uuid
   * @param \Prophecy\Prophecy\ObjectProphecy $gitlab_client
   */
  protected function mockGitLabProjectsAll($application_uuid, $projects): void {
    $all = [
      0 =>
        [
          'id' => $this->gitLabProjectId,
          'description' => '',
          'name' => 'codestudiodemo',
          'name_with_namespace' => 'Matthew Grasmick / codestudiodemo',
          'path' => 'codestudiodemo',
          'path_with_namespace' => 'matthew.grasmick/codestudiodemo',
          'default_branch' => 'master',
          'topics' =>
            [
              0 => 'Acquia Cloud Application',
            ],
          'http_url_to_repo' => 'https://code.cloudservices.acquia.io/matthew.grasmick/codestudiodemo.git',
          'web_url' => 'https://code.cloudservices.acquia.io/matthew.grasmick/codestudiodemo',
        ]
    ];
    $projects->all(['search' => $this::$application_uuid])->willReturn($all);
  }

  /**
   * @param \Prophecy\Prophecy\ObjectProphecy $gitlab_client
   */
  protected function mockGitLabUsersMe(ObjectProphecy $gitlab_client): void {
    $users = $this->prophet->prophesize(Users::class);
    $me = [];
    $users->me()->willReturn($me);
    $gitlab_client->users()->willReturn($users->reveal());
  }

  /**
   * @param \Prophecy\Prophecy\ObjectProphecy $projects
   */
  protected function mockGitLabProjectsTokens(ObjectProphecy $projects): void {
    $tokens = [
      0 =>
        [
          'id' => $this->gitLabTokenId,
          'name' => 'acquia-codestudio',
          'revoked' => FALSE,
          'created_at' => '2021-12-28T20:08:21.629Z',
          'scopes' =>
            [
              0 => 'api',
              1 => 'write_repository',
            ],
          'user_id' => 154,
          'active' => TRUE,
          'expires_at' => NULL,
          'access_level' => 40,
        ],
    ];
    $projects->projectAccessTokens($this->gitLabProjectId)->willReturn($tokens);
    $projects->deleteProjectAccessToken($this->gitLabProjectId, $this->gitLabTokenId);
    $token = $tokens[0];
    $token['token'] = 'token';
    $projects->createProjectAccessToken($this->gitLabProjectId, Argument::type('array'))->willReturn($token);
  }

  /**
   * @param $application_uuid
   * @param \Prophecy\Prophecy\ObjectProphecy $projects
   */
  protected function mockGitLabVariables($application_uuid, ObjectProphecy $projects): void {
    $variables = [];
    $projects->variables($this->gitLabProjectId)->willReturn($variables);
    $projects->addVariable($this->gitLabProjectId, Argument::type('string'), Argument::type('string'), Argument::type('bool'), NULL, Argument::type('array'));
  }

}