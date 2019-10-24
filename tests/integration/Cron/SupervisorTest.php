<?php

namespace MailPoet\Test\Cron;

use MailPoet\Cron\CronHelper;
use MailPoet\Cron\Supervisor;
use MailPoet\DI\ContainerWrapper;
use MailPoet\Models\Setting;
use MailPoet\Settings\SettingsController;

class SupervisorTest extends \MailPoetTest {
  /** @var SettingsController */
  private $settings;

  function _before() {
    parent::_before();
    // cron trigger is by default set to 'WordPress'; when it runs and does not
    // detect any queues to process, it deletes the daemon setting, so Supervisor and
    // CronHelper's getDaemon() methods do not work. for that matter, we need to set
    // the trigger setting to anything but 'WordPress'.
    $this->settings = SettingsController::getInstance();
    $this->settings->set('cron_trigger', [
      'method' => 'none',
    ]);
    $this->cron_helper = ContainerWrapper::getInstance()->get(CronHelper::class);
    $this->supervisor = ContainerWrapper::getInstance()->get(Supervisor::class);
  }

  function testItCanBeInitialized() {
    if (getenv('WP_TEST_ENABLE_NETWORK_TESTS') !== 'true') $this->markTestSkipped();
    $this->supervisor->init();
    expect($this->supervisor->token)->notEmpty();
    expect($this->supervisor->daemon)->notEmpty();
  }

  function testItCreatesDaemonWhenOneDoesNotExist() {
    if (getenv('WP_TEST_ENABLE_NETWORK_TESTS') !== 'true') $this->markTestSkipped();
    expect($this->settings->get(CronHelper::DAEMON_SETTING))->null();
    $this->supervisor->init();
    expect($this->supervisor->getDaemon())->notEmpty();
  }

  function testItReturnsDaemonWhenOneExists() {
    if (getenv('WP_TEST_ENABLE_NETWORK_TESTS') !== 'true') $this->markTestSkipped();
    $this->supervisor->init();
    expect($this->supervisor->getDaemon())->equals($this->supervisor->daemon);
  }

  function testItDoesNothingWhenDaemonExecutionDurationIsBelowLimit() {
    if (getenv('WP_TEST_ENABLE_NETWORK_TESTS') !== 'true') $this->markTestSkipped();
    $this->supervisor->init();
    expect($this->supervisor->checkDaemon())
      ->equals($this->supervisor->daemon);
  }

  function testRestartsDaemonWhenExecutionDurationIsAboveLimit() {
    if (getenv('WP_TEST_ENABLE_NETWORK_TESTS') !== 'true') $this->markTestSkipped();
    $this->supervisor->init();
    $this->supervisor->daemon['updated_at'] = time() - $this->cron_helper->getDaemonExecutionTimeout();
    $daemon = $this->supervisor->checkDaemon();
    expect(is_int($daemon['updated_at']))->true();
    expect($daemon['updated_at'])->notEquals($this->supervisor->daemon['updated_at']);
    expect($daemon['status'])->equals(CronHelper::DAEMON_STATUS_ACTIVE);
  }

  function testRestartsDaemonWhenItIsInactive() {
    if (getenv('WP_TEST_ENABLE_NETWORK_TESTS') !== 'true') $this->markTestSkipped();
    $this->supervisor->init();
    $this->supervisor->daemon['updated_at'] = time();
    $this->supervisor->daemon['status'] = CronHelper::DAEMON_STATUS_INACTIVE;
    $daemon = $this->supervisor->checkDaemon();
    expect($daemon['status'])->equals(CronHelper::DAEMON_STATUS_ACTIVE);
  }

  function _after() {
    \ORM::raw_execute('TRUNCATE ' . Setting::$_table);
  }
}
