<?php

namespace MailPoet\Test\Cron;

use Codeception\Stub;
use Codeception\Stub\Expected;
use MailPoet\Cron\CronHelper;
use MailPoet\Cron\CronTrigger;
use MailPoet\Cron\CronWorkerInterface;
use MailPoet\Cron\CronWorkerRunner;
use MailPoet\Cron\Daemon;
use MailPoet\Cron\DaemonHttpRunner;
use MailPoet\Cron\Triggers\WordPress;
use MailPoet\Cron\Workers\SimpleWorker;
use MailPoet\Cron\Workers\WorkersFactory;
use MailPoet\DI\ContainerWrapper;
use MailPoet\Settings\SettingsController;
use MailPoet\Settings\SettingsRepository;
use MailPoet\WP\Functions as WPFunctions;

class DaemonHttpRunnerTest extends \MailPoetTest {

  /** @var SettingsController */
  private $settings;

  function _before() {
    parent::_before();
    $this->settings = SettingsController::getInstance();
    $this->cron_helper = ContainerWrapper::getInstance()->get(CronHelper::class);
  }

  function testItConstructs() {
    $this->settings->set(
      CronHelper::DAEMON_SETTING,
      []
    );
    $daemon = ContainerWrapper::getInstance()->get(DaemonHttpRunner::class);
    expect(strlen($daemon->timer))->greaterOrEquals(5);
    expect(strlen($daemon->token))->greaterOrEquals(5);
  }

  function testItDoesNotRunWithoutRequestData() {
    $daemon = $this->make(
      DaemonHttpRunner::class,
      [
        'abortWithError' => function($message) {
          return $message;
        },
      ]
    );
    expect($daemon->run(false))->equals('Invalid or missing request data.');
  }

  function testItDoesNotRunWhenThereIsInvalidOrMissingToken() {
    $daemon = $this->make(
      DaemonHttpRunner::class,
      [
        'abortWithError' => function($message) {
          return $message;
        },
      ]
    );
    $daemon->settings_daemon_data = [
      'token' => 123,
    ];
    expect($daemon->run(['token' => 456]))->equals('Invalid or missing token.');
  }

  function testItStoresErrorMessageAndContinuesExecutionWhenWorkersThrowException() {
    $data = [
      'token' => 123,
    ];

    $run = 0;
    $cron_worker_runner_mock = $this->make(CronWorkerRunner::class, [
      'run' => function () use (&$run) {
        $run++;
        if ($run === 1) {
          throw new \Exception('Message');
        } elseif ($run === 2) {
          throw new \Exception();
        }
      },
    ]);

    $daemon = new Daemon($this->cron_helper, $cron_worker_runner_mock, $this->createWorkersFactoryMock());
    $daemon_http_runner = $this->make(DaemonHttpRunner::class, [
      'pauseExecution' => null,
      'callSelf' => null,
    ]);
    $this->settings->set(CronHelper::DAEMON_SETTING, $data);
    $daemon_http_runner->__construct($daemon, $this->cron_helper, SettingsController::getInstance(), $this->di_container->get(WordPress::class));
    $daemon_http_runner->run($data);
    $updated_daemon = $this->settings->get(CronHelper::DAEMON_SETTING);
    expect($updated_daemon['last_error'][0]['message'])->equals('Message');
    expect($updated_daemon['last_error'][1]['message'])->equals('');
  }

  function testItCanPauseExecution() {
    $daemon = $this->makeEmpty(Daemon::class);
    $daemon_http_runner = $this->make(DaemonHttpRunner::class, [
      'pauseExecution' => Expected::exactly(1, function($pause_delay) {
        expect($pause_delay)->lessThan($this->cron_helper->getDaemonExecutionLimit());
        expect($pause_delay)->greaterThan($this->cron_helper->getDaemonExecutionLimit() - 1);
      }),
      'callSelf' => null,
      'terminateRequest' => null,
    ]);
    $data = [
      'token' => 123,
    ];
    $this->settings->set(CronHelper::DAEMON_SETTING, $data);
    $daemon_http_runner->__construct($daemon, $this->cron_helper, SettingsController::getInstance(), $this->di_container->get(WordPress::class));
    $daemon_http_runner->run($data);
  }


  function testItTerminatesExecutionWhenDaemonIsDeleted() {
    $daemon = $this->make(Daemon::class, [
      'run' => function () {
        $this->settings->delete(CronHelper::DAEMON_SETTING);
      },
    ]);

    $daemon_http_runner = $this->make(DaemonHttpRunner::class, [
      'pauseExecution' => null,
      'terminateRequest' => Expected::exactly(1),
      'callSelf' => Expected::never(),
    ]);
    $data = [
      'token' => 123,
    ];
    $this->settings->set(CronHelper::DAEMON_SETTING, $data);

    $daemon_http_runner->__construct($daemon, $this->cron_helper, SettingsController::getInstance(), $this->di_container->get(WordPress::class));
    $daemon_http_runner->run($data);
  }

  function testItTerminatesExecutionWhenDaemonTokenChangesAndKeepsChangedToken() {
    $daemon_http_runner = $this->make(DaemonHttpRunner::class, [
      'pauseExecution' => null,
      'terminateRequest' => Expected::exactly(1),
      'callSelf' => Expected::never(),
    ]);
    $data = [
      'token' => 123,
    ];
    $this->settings->set(CronHelper::DAEMON_SETTING, $data);

    $daemon = $this->make(Daemon::class, [
      'run' => function () {
        $this->settings->set(
          CronHelper::DAEMON_SETTING,
          ['token' => 567]
        );
      },
    ]);
    $daemon_http_runner->__construct($daemon, $this->cron_helper, SettingsController::getInstance(), $this->di_container->get(WordPress::class));
    $daemon_http_runner->run($data);
    $data_after_run = $this->settings->get(CronHelper::DAEMON_SETTING);
    expect($data_after_run['token'])->equals(567);
  }

  function testItTerminatesExecutionWhenDaemonIsDeactivated() {
    $daemon = $this->make(DaemonHttpRunner::class, [
      'pauseExecution' => null,
      'terminateRequest' => Expected::exactly(1),
      'callSelf' => Expected::never(),
    ]);
    $data = [
      'token' => 123,
      'status' => CronHelper::DAEMON_STATUS_INACTIVE,
    ];
    $this->settings->set(CronHelper::DAEMON_SETTING, $data);
    $daemon->__construct($this->makeEmpty(Daemon::class), $this->cron_helper, SettingsController::getInstance(), $this->di_container->get(WordPress::class));
    $daemon->run($data);
  }

  function testItTerminatesExecutionWhenWPTriggerStopsCron() {
    $daemon = $this->make(Daemon::class, [
      'run' => null,
    ]);
    $daemon_http_runner = $this->make(DaemonHttpRunner::class, [
      'checkWPTriggerExecutionRequirements' => false,
      'pauseExecution' => null,
      'terminateRequest' => Expected::exactly(1),
      'callSelf' => Expected::never(),
    ]);
    $data = [
      'token' => 123,
    ];
    $this->settings->set(CronHelper::DAEMON_SETTING, $data);
    $this->settings->set(CronTrigger::SETTING_NAME . '.method', 'WordPress');
    WPFunctions::get()->addFilter('mailpoet_cron_enable_self_deactivation', '__return_true');
    $daemon_http_runner->__construct($daemon, $this->cron_helper, SettingsController::getInstance(), $this->di_container->get(WordPress::class));
    $daemon_http_runner->run($data);
    WPFunctions::get()->removeAllFilters('mailpoet_cron_enable_self_deactivation');
  }

  function testItUpdatesDaemonTokenDuringExecution() {
    $daemon_http_runner = $this->make(DaemonHttpRunner::class, [
      'pauseExecution' => null,
      'callSelf' => null,
      'terminateRequest' => null,
    ]);
    $data = [
      'token' => 123,
    ];
    $this->settings->set(CronHelper::DAEMON_SETTING, $data);
    $cron_worker_runner = $this->make(CronWorkerRunner::class, [
      'run' => null,
    ]);
    $daemon = new Daemon($this->cron_helper, $cron_worker_runner, $this->createWorkersFactoryMock());
    $daemon_http_runner->__construct($daemon, $this->cron_helper, SettingsController::getInstance(), $this->di_container->get(WordPress::class));
    $daemon_http_runner->run($data);
    $updated_daemon = $this->settings->get(CronHelper::DAEMON_SETTING);
    expect($updated_daemon['token'])->equals($daemon_http_runner->token);
  }

  function testItUpdatesTimestampsDuringExecution() {
    $run = 0;
    $cron_worker_runner_mock = $this->make(CronWorkerRunner::class, [
      'run' => function () use (&$run) {
        $run++;
        if ($run === 1) {
          sleep(2);
        } elseif ($run === 2) {
          throw new \Exception();
        }
      },
    ]);

    $daemon = new Daemon($this->cron_helper, $cron_worker_runner_mock, $this->createWorkersFactoryMock());
    $daemon_http_runner = $this->make(DaemonHttpRunner::class, [
      'pauseExecution' => null,
      'callSelf' => null,
    ]);
    $data = [
      'token' => 123,
    ];
    $now = time();
    $this->settings->set(CronHelper::DAEMON_SETTING, $data);
    $daemon_http_runner->__construct($daemon, $this->cron_helper, SettingsController::getInstance(), $this->di_container->get(WordPress::class));
    $daemon_http_runner->run($data);
    $updated_daemon = $this->settings->get(CronHelper::DAEMON_SETTING);
    expect($updated_daemon['run_started_at'])->greaterOrEquals($now);
    expect($updated_daemon['run_started_at'])->lessThan($now + 2);
    expect($updated_daemon['run_completed_at'])->greaterOrEquals($now + 2);
    expect($updated_daemon['run_completed_at'])->lessThan($now + 4);
  }

  function testItCanRun() {
    ignore_user_abort(0);
    expect(ignore_user_abort())->equals(0);
    $daemon_http_runner = $this->make(DaemonHttpRunner::class, [
      'pauseExecution' => null,
      // daemon should call itself
      'callSelf' => Expected::exactly(1),
      'terminateRequest' => null,
    ]);
    $data = [
      'token' => 123,
    ];
    $this->settings->set(CronHelper::DAEMON_SETTING, $data);

    $cron_worker_runner_mock = $this->make(CronWorkerRunner::class, [
      'run' => null,
    ]);
    $daemon = new Daemon($this->cron_helper, $cron_worker_runner_mock, $this->createWorkersFactoryMock());
    $daemon_http_runner->__construct($daemon, $this->cron_helper, SettingsController::getInstance(), $this->di_container->get(WordPress::class));
    $daemon_http_runner->run($data);
    expect(ignore_user_abort())->equals(1);
  }

  function testItRespondsToPingRequest() {
    $daemon = $this->make(DaemonHttpRunner::class, [
      'terminateRequest' => Expected::exactly(1, function($message) {
        expect($message)->equals('pong');
      }),
    ]);
    $daemon->ping();
  }

  function _after() {
    $this->di_container->get(SettingsRepository::class)->truncate();
  }

  private function createWorkersFactoryMock(array $workers = []) {
    $worker = $this->makeEmpty(SimpleWorker::class, [
      'process' => null,
    ]);
    return $this->make(WorkersFactory::class, $workers + [
      'createScheduleWorker' => $worker,
      'createQueueWorker' => $worker,
      'createStatsNotificationsWorker' => $worker,
      'createStatsNotificationsWorkerForAutomatedEmails' => $worker,
      'createSendingServiceKeyCheckWorker' => $worker,
      'createPremiumKeyCheckWorker' => $worker,
      'createBounceWorker' => $worker,
      'createMigrationWorker' => $worker,
      'createWooCommerceSyncWorker' => $worker,
      'createExportFilesCleanupWorker' => $worker,
      'createInactiveSubscribersWorker' => $worker,
      'createAuthorizedSendingEmailsCheckWorker' => $worker,
      'createWooCommercePastOrdersWorker' => $worker,
      'createBeamerkWorker' => $worker,
      'createUnsubscribeTokensWorker' => $worker,
      'createSubscriberLinkTokensWorker' => $worker,
    ]);
  }
}
