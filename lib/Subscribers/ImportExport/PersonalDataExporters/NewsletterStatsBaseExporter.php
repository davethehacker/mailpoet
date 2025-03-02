<?php

namespace MailPoet\Subscribers\ImportExport\PersonalDataExporters;

use MailPoet\DI\ContainerWrapper;
use MailPoet\Entities\SubscriberEntity;
use MailPoet\Subscribers\SubscribersRepository;

abstract class NewsletterStatsBaseExporter {

  const LIMIT = 100;

  protected $statsClassName;

  protected $subscriberRepository;

  public function __construct(SubscribersRepository $subscribersRepository) {
    $this->subscriberRepository = $subscribersRepository;
  }

  public function export($email, $page = 1): array {
    $data = [];
    $subscriber = $this->subscriberRepository->findOneBy(['email' => trim($email)]);

    if ($subscriber instanceof SubscriberEntity) {
      $data = $this->getSubscriberData($subscriber, $page);
    }

    return [
      'data' => $data,
      'done' => count($data) < self::LIMIT,
    ];
  }

  private function getSubscriberData(SubscriberEntity $subscriber, $page): array {
    $result = [];

    $statsClass = ContainerWrapper::getInstance()->get($this->statsClassName);
    $statistics = $statsClass->getAllForSubscriber($subscriber)
      ->setMaxResults(self::LIMIT)
      ->setFirstResult(self::LIMIT * ($page - 1))
      ->getQuery()
      ->getResult();

    foreach ($statistics as $row) {
      $result[] = $this->getEmailStats($row);
    }

    return $result;
  }

  protected abstract function getEmailStats(array $row);
}
