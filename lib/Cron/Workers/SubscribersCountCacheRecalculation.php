<?php declare(strict_types = 1);

namespace MailPoet\Cron\Workers;

use MailPoet\Cache\TransientCache;
use MailPoet\Entities\SegmentEntity;
use MailPoet\Models\ScheduledTask;
use MailPoet\Newsletter\Sending\ScheduledTasksRepository;
use MailPoet\Segments\SegmentsRepository;
use MailPoet\Subscribers\SubscribersCountsController;
use MailPoet\WP\Functions as WPFunctions;
use MailPoetVendor\Carbon\Carbon;

class SubscribersCountCacheRecalculation extends SimpleWorker {
  private const EXPIRATION_IN_MINUTES = 30;
  const TASK_TYPE = 'subscribers_count_cache_recalculation';
  const AUTOMATIC_SCHEDULING = false;
  const SUPPORT_MULTIPLE_INSTANCES = false;

  /** @var TransientCache */
  private $transientCache;

  /** @var SegmentsRepository */
  private $segmentsRepository;

  /** @var SubscribersCountsController */
  private $subscribersCountsController;

  /** @var ScheduledTasksRepository */
  private $scheduledTasksRepository;

  public function __construct(
    TransientCache $transientCache,
    SegmentsRepository $segmentsRepository,
    SubscribersCountsController $subscribersCountsController,
    ScheduledTasksRepository $scheduledTasksRepository,
    WPFunctions $wp
  ) {
    parent::__construct($wp);
    $this->transientCache = $transientCache;
    $this->segmentsRepository = $segmentsRepository;
    $this->subscribersCountsController = $subscribersCountsController;
    $this->scheduledTasksRepository = $scheduledTasksRepository;
  }

  public function processTaskStrategy(ScheduledTask $task, $timer) {
    $segments = $this->segmentsRepository->findAll();
    foreach ($segments as $segment) {
      $this->recalculateSegmentCache($timer, (int)$segment->getId(), $segment);
    }

    // update cache for subscribers without segment
    $this->recalculateSegmentCache($timer, 0);

    // remove redundancies from cache
    $this->subscribersCountsController->removeRedundancyFromStatisticsCache();

    return true;
  }

  private function recalculateSegmentCache($timer, int $segmentId, ?SegmentEntity $segment = null): void {
    $this->cronHelper->enforceExecutionLimit($timer);
    $now = Carbon::now();
    $item = $this->transientCache->getItem(TransientCache::SUBSCRIBERS_STATISTICS_COUNT_KEY, $segmentId);
    if ($item === null || !isset($item['created_at']) || $now->diffInMinutes($item['created_at']) > self::EXPIRATION_IN_MINUTES) {
      if ($segment) {
        $this->subscribersCountsController->recalculateSegmentStatisticsCache($segment);
        if ($segment->isStatic()) {
          $this->subscribersCountsController->recalculateSegmentGlobalStatusStatisticsCache($segment);
        }
      } else {
        $this->subscribersCountsController->recalculateSubscribersWithoutSegmentStatisticsCache();
      }
    }
  }

  public function getNextRunDate() {
    return Carbon::createFromTimestamp($this->wp->currentTime('timestamp'));
  }

  public function shouldBeScheduled(): bool {
    $scheduledOrRunningTask = $this->scheduledTasksRepository->findScheduledOrRunningTask(self::TASK_TYPE);
    if ($scheduledOrRunningTask) {
      return false;
    }
    $now = Carbon::now();
    $oldestCreatedAt = $this->transientCache->getOldestCreatedAt(TransientCache::SUBSCRIBERS_STATISTICS_COUNT_KEY);
    return $oldestCreatedAt === null || $now->diffInMinutes($oldestCreatedAt) > self::EXPIRATION_IN_MINUTES;
  }
}
