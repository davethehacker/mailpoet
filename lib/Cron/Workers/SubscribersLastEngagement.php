<?php declare(strict_types=1);

namespace MailPoet\Cron\Workers;

use MailPoet\Entities\StatisticsClickEntity;
use MailPoet\Entities\StatisticsOpenEntity;
use MailPoet\Entities\SubscriberEntity;
use MailPoet\Models\ScheduledTask;
use MailPoet\Util\DBCollationChecker;
use MailPoet\WooCommerce\Helper as WooCommerceHelper;
use MailPoetVendor\Doctrine\ORM\EntityManager;

class SubscribersLastEngagement extends SimpleWorker {
  const AUTOMATIC_SCHEDULING = false;
  const SUPPORT_MULTIPLE_INSTANCES = false;
  const BATCH_SIZE = 2000;
  const TASK_TYPE = 'subscribers_last_engagement';

  /** @var EntityManager */
  private $entityManager;

  /** @var DBCollationChecker */
  private $dbCollationChecker;

  /** @var WooCommerceHelper */
  private $wooCommereHelper;

  /** @var null|string */
  private $emailCollationCorrection;

  public function __construct(
    EntityManager $entityManager,
    DBCollationChecker $dbCollationChecker,
    WooCommerceHelper $wooCommereHelper
  ) {
    parent::__construct();
    $this->entityManager = $entityManager;
    $this->dbCollationChecker = $dbCollationChecker;
    $this->wooCommereHelper = $wooCommereHelper;
  }

  public function processTaskStrategy(ScheduledTask $task, $timer): bool {
    $meta = $task->getMeta();
    $minId = $meta['nextId'] ?? 1;
    $highestId = $this->getHighestSubscriberId();
    while ($minId <= $highestId) {
      $maxId = $minId + self::BATCH_SIZE;
      $this->processBatch($minId, $maxId);
      $task->meta = ['nextId' => $maxId];
      $task->save();
      $this->cronHelper->enforceExecutionLimit($timer); // Throws exception and interrupts process if over execution limit
      $minId = $maxId;
    }
    return true;
  }

  private function processBatch(int $minSubscriberId, int $maxSubscriberId): void {
    global $wpdb;
    $statisticsClicksTable = $this->entityManager->getClassMetadata(StatisticsClickEntity::class)->getTableName();
    $statisticsOpensTable = $this->entityManager->getClassMetadata(StatisticsOpenEntity::class)->getTableName();
    $subscribersTable = $this->entityManager->getClassMetadata(SubscriberEntity::class)->getTableName();
    $postsTable = $wpdb->posts;
    $postsmetaTable = $wpdb->postmeta;

    if (is_null($this->emailCollationCorrection)) {
      $this->emailCollationCorrection = $this->dbCollationChecker->getCollateIfNeeded(
        $subscribersTable,
        'email',
        $postsmetaTable,
        'meta_value'
      );
    }
    $emailCollate = $this->emailCollationCorrection;

    $query = "
      UPDATE $subscribersTable as mps
        LEFT JOIN (SELECT max(created_at) as created_at, subscriber_id FROM $statisticsOpensTable as mpsoinner GROUP BY mpsoinner.subscriber_id) as mpso ON mpso.subscriber_id = mps.id
        LEFT JOIN (SELECT max(created_at) as created_at, subscriber_id FROM $statisticsClicksTable as mpscinner GROUP BY mpscinner.subscriber_id) as mpsc ON mpsc.subscriber_id = mps.id
      SET mps.last_engagement_at = NULLIF(GREATEST(COALESCE(mpso.created_at, 0), COALESCE(mpsc.created_at,0)), 0)
      WHERE mps.last_engagement_at IS NULL AND mps.id >= $minSubscriberId AND  mps.id < $maxSubscriberId;
    ";

    // Use more complex query that takes into the account also subscriber's latest WooCommerce order
    if ($this->wooCommereHelper->isWooCommerceActive()) {
      $query = "
        UPDATE $subscribersTable as mps
          LEFT JOIN (SELECT max(created_at) as created_at, subscriber_id FROM $statisticsOpensTable as mpsoinner GROUP BY mpsoinner.subscriber_id) as mpso ON mpso.subscriber_id = mps.id
          LEFT JOIN (SELECT max(created_at) as created_at, subscriber_id FROM $statisticsClicksTable as mpscinner GROUP BY mpscinner.subscriber_id) as mpsc ON mpsc.subscriber_id = mps.id
          LEFT JOIN (SELECT MAX(post_id) AS post_id, meta_value as email FROM $postsmetaTable WHERE meta_key = '_billing_email' GROUP BY email) AS newestOrderIds ON newestOrderIds.email $emailCollate = mps.email
          LEFT JOIN (SELECT ID, post_date FROM $postsTable WHERE post_type = 'shop_order') AS shopOrders ON newestOrderIds.post_id = shopOrders.ID
        SET mps.last_engagement_at = NULLIF(GREATEST(COALESCE(mpso.created_at, 0), COALESCE(mpsc.created_at,0), COALESCE(shopOrders.post_date, 0)), 0)
        WHERE mps.last_engagement_at IS NULL AND mps.id >= $minSubscriberId AND  mps.id < $maxSubscriberId;
      ";
    }
    $this->entityManager->getConnection()->executeStatement($query);
  }

  private function getHighestSubscriberId(): int {
    $subscribersTable = $this->entityManager->getClassMetadata(SubscriberEntity::class)->getTableName();
    $result = $this->entityManager->getConnection()->executeQuery("SELECT MAX(id) FROM $subscribersTable LIMIT 1;")->fetchNumeric();
    return is_array($result) && isset($result[0]) ? (int)$result[0] : 0;
  }
}
