<?php

namespace MailPoet\Test\API\JSON\v1;

use Codeception\Stub\Expected;
use Codeception\Util\Fixtures;
use Codeception\Util\Stub;
use Helper\WordPressHooks as WPHooksHelper;
use MailPoet\API\JSON\Response as APIResponse;
use MailPoet\API\JSON\ResponseBuilders\NewslettersResponseBuilder;
use MailPoet\API\JSON\v1\Newsletters;
use MailPoet\Cron\CronHelper;
use MailPoet\DI\ContainerWrapper;
use MailPoet\Entities\NewsletterEntity;
use MailPoet\Entities\NewsletterOptionEntity;
use MailPoet\Entities\NewsletterOptionFieldEntity;
use MailPoet\Entities\NewsletterSegmentEntity;
use MailPoet\Entities\ScheduledTaskEntity;
use MailPoet\Entities\SegmentEntity;
use MailPoet\Entities\SendingQueueEntity;
use MailPoet\Entities\SubscriberEntity;
use MailPoet\Entities\SubscriberSegmentEntity;
use MailPoet\Listing\Handler;
use MailPoet\Models\ScheduledTask;
use MailPoet\Models\SendingQueue;
use MailPoet\Newsletter\Listing\NewsletterListingRepository;
use MailPoet\Newsletter\NewsletterSaveController;
use MailPoet\Newsletter\NewslettersRepository;
use MailPoet\Newsletter\Options\NewsletterOptionFieldsRepository;
use MailPoet\Newsletter\Options\NewsletterOptionsRepository;
use MailPoet\Newsletter\Preview\SendPreviewController;
use MailPoet\Newsletter\Preview\SendPreviewException;
use MailPoet\Newsletter\Scheduler\PostNotificationScheduler;
use MailPoet\Newsletter\Scheduler\Scheduler;
use MailPoet\Newsletter\Segment\NewsletterSegmentRepository;
use MailPoet\Newsletter\Statistics\NewsletterStatisticsRepository;
use MailPoet\Newsletter\Url;
use MailPoet\Router\Router;
use MailPoet\Segments\SegmentsRepository;
use MailPoet\Settings\SettingsController;
use MailPoet\Tasks\Sending as SendingTask;
use MailPoet\Util\License\Features\Subscribers as SubscribersFeature;
use MailPoet\Util\Security;
use MailPoet\WooCommerce\Helper as WCHelper;
use MailPoet\WP\Emoji;
use MailPoet\WP\Functions as WPFunctions;
use MailPoetVendor\Carbon\Carbon;
use MailPoetVendor\Doctrine\ORM\EntityManager;

class NewslettersTest extends \MailPoetTest {

  /** @var NewsletterEntity */
  public $postNotification;

  /** @var NewsletterEntity */
  public $newsletter;

  /** @var Newsletters */
  private $endpoint;

  /** @var CronHelper */
  private $cronHelper;

  /** @var NewslettersRepository */
  private $newsletterRepository;

  /** @var NewsletterOptionFieldsRepository */
  private $newsletterOptionFieldsRepository;

  /** @var NewsletterOptionsRepository */
  private $newsletterOptionsRepository;

  /** @var SegmentsRepository */
  private $segmentRepository;

  /** @var NewsletterSegmentRepository */
  private $newsletterSegmentRepository;

  /** @var NewslettersResponseBuilder */
  private $newslettersResponseBuilder;

  /** @var Url */
  private $newsletterUrl;

  public function _before() {
    parent::_before();
    $this->cronHelper = ContainerWrapper::getInstance()->get(CronHelper::class);
    $this->newsletterRepository = ContainerWrapper::getInstance()->get(NewslettersRepository::class);
    $this->newsletterOptionFieldsRepository = ContainerWrapper::getInstance()->get(NewsletterOptionFieldsRepository::class);
    $this->newsletterOptionsRepository = ContainerWrapper::getInstance()->get(NewsletterOptionsRepository::class);
    $this->segmentRepository = ContainerWrapper::getInstance()->get(SegmentsRepository::class);
    $this->newsletterSegmentRepository = ContainerWrapper::getInstance()->get(NewsletterSegmentRepository::class);
    $this->newslettersResponseBuilder = ContainerWrapper::getInstance()->get(NewslettersResponseBuilder::class);
    $this->newsletterUrl = ContainerWrapper::getInstance()->get(Url::class);
    $this->endpoint = Stub::copy(
      ContainerWrapper::getInstance()->get(Newsletters::class),
      [
        'newslettersResponseBuilder' => new NewslettersResponseBuilder(
          $this->diContainer->get(EntityManager::class),
          new NewslettersRepository($this->diContainer->get(EntityManager::class)),
          new NewsletterStatisticsRepository(
            $this->diContainer->get(EntityManager::class),
            $this->makeEmpty(WCHelper::class)
          ),
          $this->diContainer->get(Url::class)
        ),
      ]
    );
    $this->newsletter = $this->createNewsletter('My Standard Newsletter', NewsletterEntity::TYPE_STANDARD);
    $this->postNotification = $this->createNewsletter('My Post Notification', NewsletterEntity::TYPE_NOTIFICATION);

    $this->createNewsletterOptionField(NewsletterOptionFieldEntity::NAME_IS_SCHEDULED, NewsletterEntity::TYPE_STANDARD);
    $this->createNewsletterOptionField(NewsletterOptionFieldEntity::NAME_SCHEDULED_AT, NewsletterEntity::TYPE_STANDARD);
    $this->createNewsletterOptionField(NewsletterOptionFieldEntity::NAME_SCHEDULE, NewsletterEntity::TYPE_NOTIFICATION);
  }

  public function testItKeepsUnsentNewslettersAtTheTopWhenSortingBySentAtDate() {
    /** @var NewsletterEntity[] */
    $sentNewsletters = [];
    for ($i = 1; $i <= 3; $i++) {
      $sentAt = Carbon::createFromFormat('Y-m-d H:i:s', "2017-01-0{$i} 01:01:01");
      if (!$sentAt) {
        continue;
      }
      $sentNewsletters[$i] = $this->createNewsletter("Sent newsletter {$i}", NewsletterEntity::TYPE_STANDARD);
      $sentNewsletters[$i]->setSentAt($sentAt);
    }
    $this->newsletterRepository->flush();

    // sorting by ASC order retains unsent newsletters at the top
    $response = $this->endpoint->listing(
      [
        'params' => [
          'type' => 'standard',
        ],
        'sort_by' => 'sent_at',
        'sort_order' => 'asc',
      ]
    );
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->data[0]['id'])->equals($this->newsletter->getId());
    expect($response->data[1]['id'])->equals($sentNewsletters[1]->getId());
    expect($response->data[2]['id'])->equals($sentNewsletters[2]->getId());
    expect($response->data[3]['id'])->equals($sentNewsletters[3]->getId());

    // sorting by DESC order retains unsent newsletters at the top
    $response = $this->endpoint->listing(
      [
        'params' => [
          'type' => 'standard',
        ],
        'sort_by' => 'sent_at',
        'sort_order' => 'desc',
      ]
    );
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->data[0]['id'])->equals($this->newsletter->getId());
    expect($response->data[1]['id'])->equals($sentNewsletters[3]->getId());
    expect($response->data[2]['id'])->equals($sentNewsletters[2]->getId());
    expect($response->data[3]['id'])->equals($sentNewsletters[1]->getId());
  }

  public function testItCanGetANewsletter() {
    $response = $this->endpoint->get(); // missing id
    expect($response->status)->equals(APIResponse::STATUS_NOT_FOUND);
    expect($response->errors[0]['message'])
      ->equals('This email does not exist.');

    $response = $this->endpoint->get(['id' => 'not_an_id']);
    expect($response->status)->equals(APIResponse::STATUS_NOT_FOUND);
    expect($response->errors[0]['message'])
      ->equals('This email does not exist.');

    $wp = Stub::make(new WPFunctions, [
      'applyFilters' => asCallable([WPHooksHelper::class, 'applyFilters']),
    ]);
    $this->endpoint = $this->createNewslettersEndpointWithMocks([
      'wp' => $wp,
      'cronHelper' => $this->cronHelper,
      'subscribersFeature' => Stub::make(SubscribersFeature::class),
    ]);
    $response = $this->endpoint->get(['id' => $this->newsletter->getId()]);

    expect($response->status)->equals(APIResponse::STATUS_OK);
    $newsletter = $this->newsletterRepository->findOneById($this->newsletter->getId());
    assert($newsletter instanceof NewsletterEntity);
    expect($response->data)->equals($this->newslettersResponseBuilder->build($newsletter, [
      NewslettersResponseBuilder::RELATION_SEGMENTS,
      NewslettersResponseBuilder::RELATION_OPTIONS,
      NewslettersResponseBuilder::RELATION_QUEUE,
    ]));
    $hookName = 'mailpoet_api_newsletters_get_after';
    expect(WPHooksHelper::isFilterApplied($hookName))->true();
    expect(WPHooksHelper::getFilterApplied($hookName)[0])->array();
  }

  public function testItCanSaveANewsletter() {
    $newsletterData = [
      'id' => $this->newsletter->getId(),
      'type' => 'Updated type',
      'subject' => 'Updated subject',
      'preheader' => 'Updated preheader',
      'body' => '{"value": "Updated body"}',
      'sender_name' => 'Updated sender name',
      'sender_address' => 'Updated sender address',
      'reply_to_name' => 'Updated reply-to name',
      'reply_to_address' => 'Updated reply-to address',
      'ga_campaign' => 'Updated GA campaign',
    ];

    $response = $this->endpoint->save($newsletterData);
    expect($response->status)->equals(APIResponse::STATUS_OK);
    $updatedNewsletter = $this->newsletterRepository->findOneById($this->newsletter->getId());
    assert($updatedNewsletter instanceof NewsletterEntity); // PHPStan
    expect($response->data)->equals($this->newslettersResponseBuilder->build($updatedNewsletter));
    expect($updatedNewsletter->getType())->equals('Updated type');
    expect($updatedNewsletter->getSubject())->equals('Updated subject');
    expect($updatedNewsletter->getPreheader())->equals('Updated preheader');
    expect($updatedNewsletter->getBody())->equals(['value' => 'Updated body']);
    expect($updatedNewsletter->getSenderName())->equals('Updated sender name');
    expect($updatedNewsletter->getSenderAddress())->equals('Updated sender address');
    expect($updatedNewsletter->getReplyToName())->equals('Updated reply-to name');
    expect($updatedNewsletter->getReplyToAddress())->equals('Updated reply-to address');
    expect($updatedNewsletter->getGaCampaign())->equals('Updated GA campaign');
  }

  public function testItReturnsErrorIfSubscribersLimitReached() {
    $endpoint = $this->createNewslettersEndpointWithMocks([
      'cronHelper' => $this->cronHelper,
      'subscribersFeature' => Stub::make(SubscribersFeature::class, ['check' => true]),
    ]);
    $res = $endpoint->setStatus([
      'id' => $this->newsletter->getId(),
      'status' => NewsletterEntity::STATUS_ACTIVE,
    ]);
    expect($res->status)->equals(APIResponse::STATUS_FORBIDDEN);
  }

  public function testItCanSetANewsletterStatus() {
    // set status to sending
    $response = $this->endpoint->setStatus
    ([
       'id' => $this->newsletter->getId(),
       'status' => NewsletterEntity::STATUS_SENDING,
     ]
    );
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->data['status'])->equals(NewsletterEntity::STATUS_SENDING);

    // set status to draft
    $response = $this->endpoint->setStatus(
      [
        'id' => $this->newsletter->getId(),
        'status' => NewsletterEntity::STATUS_DRAFT,
      ]
    );
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->data['status'])->equals(NewsletterEntity::STATUS_DRAFT);

    // no status specified throws an error
    $response = $this->endpoint->setStatus(
      [
        'id' => $this->newsletter->getId(),
      ]
    );
    expect($response->status)->equals(APIResponse::STATUS_BAD_REQUEST);
    expect($response->errors[0]['message'])
      ->equals('You need to specify a status.');

    // invalid newsletter id throws an error
    $response = $this->endpoint->setStatus(
      [
        'status' => NewsletterEntity::STATUS_DRAFT,
      ]
    );
    expect($response->status)->equals(APIResponse::STATUS_NOT_FOUND);
    expect($response->errors[0]['message'])
      ->equals('This email does not exist.');
  }

  public function testItReschedulesPastDuePostNotificationsWhenStatusIsSetBackToActive() {
    $schedule = sprintf('0 %d * * *', Carbon::createFromTimestamp(WPFunctions::get()->currentTime('timestamp'))->hour); // every day at current hour
    $randomFutureDate = Carbon::createFromTimestamp(WPFunctions::get()->currentTime('timestamp'))->addDays(10)->format('Y-m-d H:i:s'); // 10 days from now
    $this->createOrUpdateNewsletterOption($this->postNotification, NewsletterOptionFieldEntity::NAME_SCHEDULE, $schedule);

    $sendingQueue1 = SendingTask::create();
    $sendingQueue1->newsletterId = $this->postNotification->getId();
    $sendingQueue1->scheduledAt = Scheduler::getPreviousRunDate($schedule);
    $sendingQueue1->status = SendingQueue::STATUS_SCHEDULED;
    $sendingQueue1->save();
    $sendingQueue2 = SendingTask::create();
    $sendingQueue2->newsletterId = $this->postNotification->getId();
    $sendingQueue2->scheduledAt = $randomFutureDate;
    $sendingQueue2->status = SendingQueue::STATUS_SCHEDULED;
    $sendingQueue2->save();
    $sendingQueue3 = SendingTask::create();
    $sendingQueue3->newsletterId = $this->postNotification->getId();
    $sendingQueue3->scheduledAt = Scheduler::getPreviousRunDate($schedule);
    $sendingQueue3->save();

    $this->entityManager->clear();
    $this->endpoint->setStatus(
      [
        'id' => $this->postNotification->getId(),
        'status' => NewsletterEntity::STATUS_ACTIVE,
      ]
    );
    $tasks = ScheduledTask::findMany();
    // previously scheduled notification is rescheduled for future date
    expect($tasks[0]->scheduled_at)->equals(Scheduler::getNextRunDate($schedule));
    // future scheduled notifications are left intact
    expect($tasks[1]->scheduled_at)->equals($randomFutureDate);
    // previously unscheduled (e.g., sent/sending) notifications are left intact
    expect($tasks[2]->scheduled_at)->equals(Scheduler::getPreviousRunDate($schedule));
  }

  public function testItSchedulesPostNotificationsWhenStatusIsSetBackToActive() {
    $schedule = '* * * * *';
    $this->createOrUpdateNewsletterOption($this->postNotification, NewsletterOptionFieldEntity::NAME_SCHEDULE, $schedule);

    $this->endpoint->setStatus(
      [
        'id' => $this->postNotification->getId(),
        'status' => NewsletterEntity::STATUS_ACTIVE,
      ]
    );
    $tasks = ScheduledTask::findMany();
    expect($tasks)->notEmpty();
  }

  public function testItCanRestoreANewsletter() {
    $this->newsletterRepository->bulkTrash([$this->newsletter->getId()]);
    $this->entityManager->clear();

    $trashedNewsletter = $this->newsletterRepository->findOneById($this->newsletter->getId());
    assert($trashedNewsletter instanceof NewsletterEntity);
    expect($trashedNewsletter->getDeletedAt())->notNull();

    $response = $this->endpoint->restore(['id' => $this->newsletter->getId()]);
    expect($response->status)->equals(APIResponse::STATUS_OK);
    $newsletter = $this->newsletterRepository->findOneById($this->newsletter->getId());
    assert($newsletter instanceof NewsletterEntity);
    expect($response->data)->equals($this->newslettersResponseBuilder->build($newsletter));
    expect($response->data['deleted_at'])->null();
    expect($response->meta['count'])->equals(1);
  }

  public function testItCanTrashANewsletter() {
    $response = $this->endpoint->trash(['id' => $this->newsletter->getId()]);
    expect($response->status)->equals(APIResponse::STATUS_OK);
    $newsletter = $this->newsletterRepository->findOneById($this->newsletter->getId());
    assert($newsletter instanceof NewsletterEntity);
    expect($response->data)->equals($this->newslettersResponseBuilder->build($newsletter));
    expect($response->data['deleted_at'])->notNull();
    expect($response->meta['count'])->equals(1);
  }

  public function testItCanDeleteANewsletter() {
    $response = $this->endpoint->delete(['id' => $this->newsletter->getId()]);
    expect($response->data)->isEmpty();
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->meta['count'])->equals(1);
  }

  public function testItCanDuplicateANewsletter() {
    $wp = Stub::make(new WPFunctions, [
      'doAction' => asCallable([WPHooksHelper::class, 'doAction']),
    ]);
    $this->endpoint = $this->createNewslettersEndpointWithMocks([
      'wp' => $wp,
      'cronHelper' => $this->cronHelper,
      'subscribersFeature' => Stub::make(SubscribersFeature::class),
    ]);

    $response = $this->endpoint->duplicate(['id' => $this->newsletter->getId()]);
    expect($response->status)->equals(APIResponse::STATUS_OK);
    $newsletterCopy = $this->newsletterRepository->findOneBy(['subject' => 'Copy of My Standard Newsletter']);
    assert($newsletterCopy instanceof NewsletterEntity);
    expect($response->data)->equals($this->newslettersResponseBuilder->build($newsletterCopy));
    expect($response->meta['count'])->equals(1);

    $hookName = 'mailpoet_api_newsletters_duplicate_after';
    expect(WPHooksHelper::isActionDone($hookName))->true();
    expect(WPHooksHelper::getActionDone($hookName)[0] instanceof NewsletterEntity)->true();

    $response = $this->endpoint->duplicate(['id' => $this->postNotification->getId()]);
    expect($response->status)->equals(APIResponse::STATUS_OK);
    $newsletterCopy = $this->newsletterRepository->findOneBy(['subject' => 'Copy of My Post Notification']);
    assert($newsletterCopy instanceof NewsletterEntity);
    expect($response->data)->equals($this->newslettersResponseBuilder->build($newsletterCopy));
    expect($response->meta['count'])->equals(1);
  }

  public function testItCanCreateANewsletter() {
    $data = [
      'subject' => 'My New Newsletter',
      'type' => NewsletterEntity::TYPE_STANDARD,
    ];
    $response = $this->endpoint->create($data);
    expect($response->status)->equals(APIResponse::STATUS_OK);
    $newsletter = $this->newsletterRepository->findOneBy(['subject' => 'My New Newsletter']);
    assert($newsletter instanceof NewsletterEntity);
    expect($response->data)->equals($this->newslettersResponseBuilder->build($newsletter));

    $response = $this->endpoint->create();
    expect($response->status)->equals(APIResponse::STATUS_BAD_REQUEST);
    expect($response->errors[0]['message'])->equals('Please specify a type.');
  }

  public function testItHasDefaultSenderAfterCreate() {
    $data = [
      'subject' => 'My First Newsletter',
      'type' => NewsletterEntity::TYPE_STANDARD,
    ];

    $settingsController = $this->diContainer->get(SettingsController::class);
    $settingsController->set('sender', ['name' => 'Sender', 'address' => 'sender@test.com']);
    $settingsController->set('reply_to', ['name' => 'Reply', 'address' => 'reply@test.com']);

    $response = $this->endpoint->create($data);
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->data['subject'])->equals('My First Newsletter');
    expect($response->data['type'])->equals(NewsletterEntity::TYPE_STANDARD);
    expect($response->data['sender_address'])->equals('sender@test.com');
    expect($response->data['sender_name'])->equals('Sender');
    expect($response->data['reply_to_address'])->equals('reply@test.com');
    expect($response->data['reply_to_name'])->equals('Reply');
  }

  public function testItCanGetListingData() {
    $segment1 = $this->segmentRepository->createOrUpdate('Segment 1');
    $segment2 = $this->segmentRepository->createOrUpdate('Segment 2');

    $this->createNewsletterSegment($this->newsletter, $segment1);
    $this->createNewsletterSegment($this->newsletter, $segment2);
    $this->createNewsletterSegment($this->postNotification, $segment2);
    $this->entityManager->clear();

    $response = $this->endpoint->listing();

    expect($response->status)->equals(APIResponse::STATUS_OK);

    expect($response->meta)->hasKey('filters');
    expect($response->meta)->hasKey('groups');
    expect($response->meta['count'])->equals(2);

    expect($response->data)->count(2);
    expect($response->data[0]['subject'])->equals('My Standard Newsletter');
    expect($response->data[1]['subject'])->equals('My Post Notification');

    // 1st subscriber has 2 segments
    expect($response->data[0]['segments'])->count(2);
    expect($response->data[0]['segments'][0]['id'])
      ->equals($segment1->getId());
    expect($response->data[0]['segments'][1]['id'])
      ->equals($segment2->getId());

    // 2nd subscriber has 1 segment
    expect($response->data[1]['segments'])->count(1);
    expect($response->data[1]['segments'][0]['id'])
      ->equals($segment2->getId());
  }

  public function testItCanFilterListing() {
    // create 2 segments
    $segment1 = $this->segmentRepository->createOrUpdate('Segment 1');
    $segment2 = $this->segmentRepository->createOrUpdate('Segment 2');

    // link standard newsletter to the 2 segments
    $this->createNewsletterSegment($this->newsletter, $segment1);
    $this->createNewsletterSegment($this->newsletter, $segment2);

    // link post notification to the 2nd segment
    $this->createNewsletterSegment($this->postNotification, $segment2);

    // filter by 1st segment
    $response = $this->endpoint->listing(
      [
        'filter' => [
          'segment' => $segment1->getId(),
        ],
      ]
    );

    expect($response->status)->equals(APIResponse::STATUS_OK);

    // we should only get the standard newsletter
    expect($response->meta['count'])->equals(1);
    expect($response->data[0]['subject'])->equals($this->newsletter->getSubject());

    // filter by 2nd segment
    $response = $this->endpoint->listing(
      [
        'filter' => [
          'segment' => $segment2->getId(),
        ],
      ]
    );

    expect($response->status)->equals(APIResponse::STATUS_OK);

    // we should have the 2 newsletters
    expect($response->meta['count'])->equals(2);
  }

  public function testItCanLimitListing() {
    // get 1st page (limit items per page to 1)
    $response = $this->endpoint->listing(
      [
        'limit' => 1,
        'sort_by' => 'subject',
        'sort_order' => 'asc',
      ]
    );

    expect($response->status)->equals(APIResponse::STATUS_OK);

    expect($response->meta['count'])->equals(2);
    expect($response->data)->count(1);
    expect($response->data[0]['subject'])->equals(
      $this->postNotification->getSubject()
    );

    // get 1st page (limit items per page to 1)
    $response = $this->endpoint->listing(
      [
        'limit' => 1,
        'offset' => 1,
        'sort_by' => 'subject',
        'sort_order' => 'asc',
      ]
    );

    expect($response->meta['count'])->equals(2);
    expect($response->data)->count(1);
    expect($response->data[0]['subject'])->equals(
      $this->newsletter->getSubject()
    );
  }

  public function testItCanBulkDeleteSelectionOfNewsletters() {
    $selectionIds = [
      $this->newsletter->getId(),
      $this->postNotification->getId(),
    ];

    $response = $this->endpoint->bulkAction(
      [
        'listing' => [
          'selection' => $selectionIds,
        ],
        'action' => 'delete',
      ]
    );

    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->meta['count'])->equals(count($selectionIds));
  }

  public function testItCanBulkDeleteNewsletters() {
    $response = $this->endpoint->bulkAction(
      [
        'action' => 'trash',
        'listing' => ['group' => 'all'],
      ]
    );
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->meta['count'])->equals(2);

    $response = $this->endpoint->bulkAction(
      [
        'action' => 'delete',
        'listing' => ['group' => 'trash'],
      ]
    );
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->meta['count'])->equals(2);

    $response = $this->endpoint->bulkAction(
      [
        'action' => 'delete',
        'listing' => ['group' => 'trash'],
      ]
    );
    expect($response->status)->equals(APIResponse::STATUS_OK);
    expect($response->meta['count'])->equals(0);
  }

  public function testItCanSendAPreview() {
    $subscriber = 'test@subscriber.com';
    $endpoint = $this->createNewslettersEndpointWithMocks([
      'sendPreviewController' => $this->make(SendPreviewController::class, [
        'sendPreview' => null,
      ]),
    ]);

    $data = [
      'subscriber' => $subscriber,
      'id' => $this->newsletter->getId(),
    ];
    $response = $endpoint->sendPreview($data);
    expect($response->status)->equals(APIResponse::STATUS_OK);
  }

  public function testItReturnsMailerErrorWhenSendingFailed() {
    $subscriber = 'test@subscriber.com';
    $endpoint = $this->createNewslettersEndpointWithMocks([
      'sendPreviewController' => $this->make(SendPreviewController::class, [
        'sendPreview' => Expected::once(function () {
          throw new SendPreviewException('The email could not be sent: failed');
        }),
      ]),
    ]);

    $data = [
      'subscriber' => $subscriber,
      'id' => $this->newsletter->getId(),
    ];
    $response = $endpoint->sendPreview($data);
    expect($response->errors[0]['message'])->equals('The email could not be sent: failed');
  }

  public function testItReturnsBrowserPreviewUrlWithoutProtocol() {
    $data = [
      'id' => $this->newsletter->getId(),
      'body' => 'fake body',
    ];

    $emoji = $this->make(
      Emoji::class,
      ['encodeForUTF8Column' => Expected::once(function ($params) {
        return $params;
      })]
    );

    $wp = Stub::make(new WPFunctions, [
      'applyFilters' => asCallable([WPHooksHelper::class, 'applyFilters']),
      'doAction' => asCallable([WPHooksHelper::class, 'doAction']),
    ]);
    $this->endpoint = $this->createNewslettersEndpointWithMocks([
      'wp' => $wp,
      'cronHelper' => $this->cronHelper,
      'emoji' => $emoji,
      'subscribersFeature' => Stub::make(SubscribersFeature::class),
    ]);

    $response = $this->endpoint->showPreview($data);
    expect($response->meta['preview_url'])->stringNotContainsString('http');
    expect($response->meta['preview_url'])->regExp('!^\/\/!');
  }

  public function testItGeneratesPreviewLinksWithNewsletterHashAndNoSubscriberData() {
    $response = $this->endpoint->listing();
    $previewLink = $response->data[0]['preview_url'];
    parse_str((string)parse_url($previewLink, PHP_URL_QUERY), $previewLinkData);
    $previewLinkData = $this->newsletterUrl->transformUrlDataObject(Router::decodeRequestData($previewLinkData['data']));
    expect($previewLinkData['newsletter_hash'])->notEmpty();
    expect($previewLinkData['subscriber_id'])->false();
    expect($previewLinkData['subscriber_token'])->false();
    expect((boolean)$previewLinkData['preview'])->true();
  }

  public function _after() {
    $this->truncateEntity(NewsletterEntity::class);
    $this->truncateEntity(NewsletterOptionEntity::class);
    $this->truncateEntity(NewsletterOptionFieldEntity::class);
    $this->truncateEntity(NewsletterSegmentEntity::class);
    $this->truncateEntity(SegmentEntity::class);
    $this->truncateEntity(ScheduledTaskEntity::class);
    $this->truncateEntity(SendingQueueEntity::class);
    $this->truncateEntity(SubscriberEntity::class);
    $this->truncateEntity(SubscriberSegmentEntity::class);
  }

  private function createNewslettersEndpointWithMocks(array $mocks): Newsletters {
    return new Newsletters(
      $this->diContainer->get(Handler::class),
      $mocks['wp'] ?? $this->diContainer->get(WPFunctions::class),
      $this->diContainer->get(SettingsController::class),
      $mocks['cronHelper'] ?? $this->diContainer->get(CronHelper::class),
      $this->diContainer->get(NewslettersRepository::class),
      $this->diContainer->get(NewsletterListingRepository::class),
      $this->diContainer->get(NewslettersResponseBuilder::class),
      $this->diContainer->get(PostNotificationScheduler::class),
      $mocks['emoji'] ?? $this->diContainer->get(Emoji::class),
      $mocks['subscribersFeature'] ?? $this->diContainer->get(SubscribersFeature::class),
      $mocks['sendPreviewController'] ?? $this->diContainer->get(SendPreviewController::class),
      $this->diContainer->get(NewsletterSaveController::class),
      $this->diContainer->get(Url::class)
    );
  }

  private function createNewsletter(string $subject, string $type): NewsletterEntity {
    $newsletter = new NewsletterEntity();
    $newsletter->setSubject($subject);
    $newsletter->setBody(Fixtures::get('newsletter_body_template'));
    $newsletter->setType($type);
    $newsletter->setHash(Security::generateHash());
    $this->newsletterRepository->persist($newsletter);
    $this->newsletterRepository->flush();
    return $newsletter;
  }

  private function createNewsletterOptionField(string $name, string $type): NewsletterOptionFieldEntity {
    $optionField = new NewsletterOptionFieldEntity();
    $optionField->setName($name);
    $optionField->setNewsletterType($type);
    $this->newsletterOptionFieldsRepository->persist($optionField);
    $this->newsletterOptionFieldsRepository->flush();
    return $optionField;
  }

  private function createOrUpdateNewsletterOption(NewsletterEntity $newsletter, string $name, string $value): NewsletterOptionEntity {
    $option = $newsletter->getOption($name);
    if (!$option) {
      $optionField = $this->newsletterOptionFieldsRepository->findOneBy(['name' => $name]);
      assert($optionField instanceof NewsletterOptionFieldEntity);
      $option = new NewsletterOptionEntity($newsletter, $optionField);
      $newsletter->getOptions()->add($option);
    }
    $option->setValue($value);
    $this->newsletterOptionsRepository->persist($option);
    $this->newsletterOptionsRepository->flush();
    return $option;
  }

  private function createNewsletterSegment(
    NewsletterEntity $newsletter,
    SegmentEntity $segment
  ): NewsletterSegmentEntity {
    $newsletterSegment = new NewsletterSegmentEntity($newsletter, $segment);
    $this->newsletterSegmentRepository->persist($newsletterSegment);
    $this->newsletterSegmentRepository->flush();
    return $newsletterSegment;
  }
}
