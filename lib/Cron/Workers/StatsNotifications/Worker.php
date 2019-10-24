<?php

namespace MailPoet\Cron\Workers\StatsNotifications;

use Carbon\Carbon;
use MailPoet\Config\Renderer;
use MailPoet\Cron\CronHelper;
use MailPoet\Entities\ScheduledTaskEntity;
use MailPoet\Entities\StatsNotificationEntity;
use MailPoet\Mailer\Mailer;
use MailPoet\Mailer\MetaInfo;
use MailPoet\Models\NewsletterLink;
use MailPoet\Models\ScheduledTask;
use MailPoet\Settings\SettingsController;
use MailPoet\Tasks\Sending;
use MailPoet\WP\Functions as WPFunctions;
use MailPoetVendor\Doctrine\ORM\EntityManager;

class Worker {

  const TASK_TYPE = 'stats_notification';
  const SETTINGS_KEY = 'stats_notifications';

  /** @var float */
  public $timer;

  /** @var Renderer */
  private $renderer;

  /** @var \MailPoet\Mailer\Mailer */
  private $mailer;

  /** @var SettingsController */
  private $settings;

  /** @var MetaInfo */
  private $mailerMetaInfo;

  /** @var StatsNotificationsRepository */
  private $repository;

  /** @var EntityManager */
  private $entity_manager;

  function __construct(
    Mailer $mailer,
    Renderer $renderer,
    SettingsController $settings,
    MetaInfo $mailerMetaInfo,
    StatsNotificationsRepository $repository,
    EntityManager $entity_manager,
    $timer = false
  ) {
    $this->timer = $timer ?: microtime(true);
    $this->renderer = $renderer;
    $this->mailer = $mailer;
    $this->settings = $settings;
    $this->mailerMetaInfo = $mailerMetaInfo;
    $this->repository = $repository;
    $this->entity_manager = $entity_manager;
  }

  /** @throws \Exception */
  function process() {
    $settings = $this->settings->get(self::SETTINGS_KEY);
    foreach (self::getDueTasks() as $stats_notification_entity) {
      try {
        $extra_params = [
          'meta' => $this->mailerMetaInfo->getStatsNotificationMetaInfo(),
        ];
        $this->mailer->send($this->constructNewsletter($stats_notification_entity), $settings['address'], $extra_params);
      } catch (\Exception $e) {
        if (WP_DEBUG) {
          throw $e;
        }
      } finally {
        $this->markTaskAsFinished($stats_notification_entity->getTask());
      }
      CronHelper::enforceExecutionLimit($this->timer);
    }
  }

  /**
   * @return StatsNotificationEntity[]
   */
  private function getDueTasks() {
    return $this->repository->findDueTasks(Sending::RESULT_BATCH_SIZE);
  }

  private function constructNewsletter(StatsNotificationEntity $stats_notification_entity) {
    $newsletter = $this->getNewsletter($stats_notification_entity);
    $link = NewsletterLink::findTopLinkForNewsletter($newsletter);
    $context = $this->prepareContext($newsletter, $link);
    $subject = $newsletter->queue['newsletter_rendered_subject'];
    return [
      'subject' => sprintf(_x('Stats for email %s', 'title of an automatic email containing statistics (newsletter open rate, click rate, etc)', 'mailpoet'), $subject),
      'body' => [
        'html' => $this->renderer->render('emails/statsNotification.html', $context),
        'text' => $this->renderer->render('emails/statsNotification.txt', $context),
      ],
    ];
  }

  private function getNewsletter(StatsNotificationEntity $stats_notification_entity) {
    $newsletter = $stats_notification_entity->getNewsletter();
    $newsletter = Newsletter::findOne($newsletter->getId());
    return $newsletter
      ->withSendingQueue()
      ->withTotalSent()
      ->withStatistics($this->woocommerce_helper);
  }

  /**
   * @param Newsletter $newsletter
   * @param \stdClass|NewsletterLink $link
   * @return array
   */
  private function prepareContext(Newsletter $newsletter, $link = null) {
    $clicked = ($newsletter->statistics['clicked'] * 100) / $newsletter->total_sent;
    $opened = ($newsletter->statistics['opened'] * 100) / $newsletter->total_sent;
    $unsubscribed = ($newsletter->statistics['unsubscribed'] * 100) / $newsletter->total_sent;
    $subject = $newsletter->queue['newsletter_rendered_subject'];
    $context = [
      'subject' => $subject,
      'preheader' => sprintf(_x(
        '%1$s%% opens, %2$s%% clicks, %3$s%% unsubscribes in a nutshell.', 'newsletter open rate, click rate and unsubscribe rate', 'mailpoet'),
        number_format($opened, 2),
        number_format($clicked, 2),
        number_format($unsubscribed, 2)
      ),
      'topLinkClicks' => 0,
      'linkSettings' => WPFunctions::get()->getSiteUrl(null, '/wp-admin/admin.php?page=mailpoet-settings#basics'),
      'linkStats' => WPFunctions::get()->getSiteUrl(null, '/wp-admin/admin.php?page=mailpoet-newsletters#/stats/' . $newsletter->id()),
      'clicked' => $clicked,
      'opened' => $opened,
    ];
    if ($link) {
      $context['topLinkClicks'] = (int)$link->clicksCount;
      $mappings = self::getShortcodeLinksMapping();
      $context['topLink'] = isset($mappings[$link->url]) ? $mappings[$link->url] : $link->url;
    }
    return $context;
  }

  private function markTaskAsFinished(ScheduledTaskEntity $task) {
    $task->setStatus(ScheduledTask::STATUS_COMPLETED);
    $task->setProcessedAt(new Carbon);
    $task->setScheduledAt(null);
    $this->entity_manager->persist($task);
    $this->entity_manager->flush();
  }

  public static function getShortcodeLinksMapping() {
    return [
      NewsletterLink::UNSUBSCRIBE_LINK_SHORT_CODE => __('Unsubscribe link', 'mailpoet'),
      '[link:subscription_manage_url]' => __('Manage subscription link', 'mailpoet'),
      '[link:newsletter_view_in_browser_url]' => __('View in browser link', 'mailpoet'),
    ];
  }

}
