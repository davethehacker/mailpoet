<?php

namespace MailPoet\Subscribers\ImportExport;

use DateTime;
use MailPoet\Entities\CustomFieldEntity;
use MailPoet\Entities\SegmentEntity;
use MailPoet\Entities\SubscriberCustomFieldEntity;
use MailPoet\Entities\SubscriberEntity;
use MailPoet\Entities\SubscriberSegmentEntity;
use MailPoet\Segments\DynamicSegments\FilterHandler;
use MailPoet\WP\Functions as WPFunctions;
use MailPoetVendor\Doctrine\DBAL\Connection;
use MailPoetVendor\Doctrine\DBAL\Driver\Statement;
use MailPoetVendor\Doctrine\DBAL\Query\QueryBuilder;
use MailPoetVendor\Doctrine\ORM\EntityManager;
use MailPoetVendor\Doctrine\ORM\Mapping\ClassMetadata;

class ImportExportRepository {
  private const IGNORED_COLUMNS_FOR_BULK_UPDATE = [
    SubscriberEntity::class => [
      'wp_user_id',
      'is_woocommerce_user',
      'email',
      'created_at',
      'last_subscribed_at',
    ],
    SubscriberCustomFieldEntity::class => [
      'created_at',
    ],
    SubscriberSegmentEntity::class => [
      'created_at',
    ],
  ];

  private const KEY_COLUMNS_FOR_BULK_UPDATE = [
    SubscriberEntity::class => [
      'email',
    ],
    SubscriberCustomFieldEntity::class => [
      'subscriber_id',
      'custom_field_id',
    ],
  ];

  /** @var EntityManager */
  protected $entityManager;

  /** @var FilterHandler */
  private $filterHandler;

  public function __construct(EntityManager $entityManager, FilterHandler $filterHandler) {
    $this->entityManager = $entityManager;
    $this->filterHandler = $filterHandler;
  }

  /**
   * @return ClassMetadata<object>
   */
  protected function getClassMetadata(string $className): ClassMetadata {
    return $this->entityManager->getClassMetadata($className);
  }

  protected function getTableName(string $className): string {
    return $this->getClassMetadata($className)->getTableName();
  }

  protected function getTableColumns(string $className): array {
    return $this->getClassMetadata($className)->getColumnNames();
  }

  public function insertMultiple(
    string $className,
    array $columns,
    array $data
  ): int {
    $tableName = $this->getTableName($className);

    if (!$columns || !$data) {
      return 0;
    }

    $rows = [];
    $parameters = [];
    foreach ($data as $key => $item) {
      $paramNames = array_map(function (string $parameter) use ($key): string {
        return ":{$parameter}_{$key}";
      }, $columns);

      foreach ($item as $columnKey => $column) {
        $parameters[$paramNames[$columnKey]] = $column;
      }
      $rows[] = "(" . implode(', ', $paramNames) . ")";
    }

    return $this->entityManager->getConnection()->executeUpdate("
      INSERT IGNORE INTO {$tableName} (`" . implode("`, `", $columns) . "`) VALUES
      " . implode(", \n", $rows) . "
    ", $parameters);
  }

  public function updateMultiple(
    string $className,
    array $columns,
    array $data,
    ?DateTime $updatedAt = null
  ): int {
    $tableName = $this->getTableName($className);
    $entityColumns = $this->getTableColumns($className);

    if (!$columns || !$data) {
      return 0;
    }

    $parameters = [];
    $parameterTypes = [];
    $keyColumns = self::KEY_COLUMNS_FOR_BULK_UPDATE[$className] ?? [];
    if (!$keyColumns) {
      return 0;
    }

    $keyColumnsConditions = [];
    foreach ($keyColumns as $keyColumn) {
      $columnIndex = array_search($keyColumn, $columns);
      $parameters[$keyColumn] = array_map(function(array $row) use ($columnIndex) {
        return $row[$columnIndex];
      }, $data);
      $parameterTypes[$keyColumn] = Connection::PARAM_STR_ARRAY;
      $keyColumnsConditions[] = "{$keyColumn} IN (:{$keyColumn})";
    }

    $ignoredColumns = self::IGNORED_COLUMNS_FOR_BULK_UPDATE[$className] ?? ['created_at'];
    $updateColumns = array_map(function($columnName) use ($keyColumns, $columns, $data, &$parameters): string {
      $values = [];
      foreach ($data as $index => $row) {
        $keyCondition = array_map(function($keyColumn) use ($index, $row, $columns, &$parameters): string {
          $parameters["{$keyColumn}_{$index}"] = $row[array_search($keyColumn, $columns)];
          return "{$keyColumn} = :{$keyColumn}_{$index}";
        }, $keyColumns);
        $values[] = "WHEN " . implode(' AND ', $keyCondition) . " THEN :{$columnName}_{$index}";
        $parameters["{$columnName}_{$index}"] = $row[array_search($columnName, $columns)];
      }
      return "{$columnName} = (CASE " . implode("\n", $values) . " END)";
    }, array_diff($columns, $ignoredColumns));

    if ($updatedAt && in_array('updated_at', $entityColumns, true)) {
      $parameters['updated_at'] = $updatedAt;
      $updateColumns[] = "updated_at = :updated_at";
    }

    // we want to reset deleted_at for updated rows
    if (in_array('deleted_at', $entityColumns, true)) {
      $updateColumns[] = 'deleted_at = NULL';
    }

    return $this->entityManager->getConnection()->executeUpdate("
      UPDATE {$tableName} SET
      " . implode(", \n", $updateColumns) . "
      WHERE
      " . implode(' AND ', $keyColumnsConditions) . "
    ", $parameters, $parameterTypes);
  }

  public function getSubscribersBatchBySegment(?SegmentEntity $segment, int $limit, int $offset = 0): array {
    $subscriberSegmentTable = $this->getTableName(SubscriberSegmentEntity::class);
    $subscriberTable = $this->getTableName(SubscriberEntity::class);
    $segmentTable = $this->getTableName(SegmentEntity::class);

    $qb = $this->createSubscribersQueryBuilder($limit, $offset);
    $qb = $this->addSubscriberCustomFieldsToQueryBuilder($qb);

    if (!$segment) {
      // if there are subscribers who do not belong to any segment, use
      // a CASE function to group them under "Not In Segment"
      $qb->addSelect("'" . WPFunctions::get()->__('Not In Segment', 'mailpoet') . "' AS segment_name")
        ->leftJoin($subscriberTable, $subscriberTable, 's2', "{$subscriberTable}.id = s2.id")
        ->leftJoin('s2', $subscriberSegmentTable, 'ssg2', "s2.id = ssg2.subscriber_id AND ssg2.status = :statusSubscribed AND {$segmentTable}.id <> ssg2.segment_id")
        ->leftJoin('ssg2', $segmentTable, 'sg2', 'ssg2.segment_id = sg2.id AND sg2.deleted_at IS NULL')
        ->andWhere("({$subscriberSegmentTable}.status != :statusSubscribed OR {$subscriberSegmentTable}.id IS NULL OR {$segmentTable}.deleted_at IS NOT NULL)")
        ->andWhere('sg2.id IS NULL')
        ->setParameter('statusSubscribed', SubscriberEntity::STATUS_SUBSCRIBED);
    } elseif ($segment->isStatic()) {
      $qb->addSelect("{$segmentTable}.name AS segment_name")
        ->andWhere("{$subscriberSegmentTable}.segment_id = :segmentId")
        ->setParameter('segmentId', $segment->getId());
    } else {
      // Dynamic segments don't have a relation to the segment table,
      // So we need to use a placeholder
      $qb->addSelect(":segmentName AS segment_name")
        ->setParameter('segmentName', $segment->getName());
      $qb = $this->filterHandler->apply($qb, $segment);
    }

    $statement = $qb->execute();
    return $statement instanceof Statement ? $statement->fetchAll() : [];
  }

  private function createSubscribersQueryBuilder(int $limit, int $offset): QueryBuilder {
    $subscriberSegmentTable = $this->getTableName(SubscriberSegmentEntity::class);
    $subscriberTable = $this->getTableName(SubscriberEntity::class);
    $segmentTable = $this->getTableName(SegmentEntity::class);

    return $this->entityManager->getConnection()->createQueryBuilder()
      ->select("
        {$subscriberTable}.first_name,
        {$subscriberTable}.last_name,
        {$subscriberTable}.email,
        {$subscriberTable}.subscribed_ip,
        {$subscriberTable}.confirmed_at,
        {$subscriberTable}.confirmed_ip,
        {$subscriberTable}.created_at,
        {$subscriberTable}.status AS global_status,
        {$subscriberSegmentTable}.status AS list_status
      ")
      ->from($subscriberTable)
      ->leftJoin($subscriberTable, $subscriberSegmentTable, $subscriberSegmentTable, "{$subscriberTable}.id = {$subscriberSegmentTable}.subscriber_id")
      ->leftJoin($subscriberSegmentTable, $segmentTable, $segmentTable, "{$segmentTable}.id = {$subscriberSegmentTable}.segment_id")
      ->andWhere("{$subscriberTable}.deleted_at IS NULL")
      ->groupBy("{$subscriberTable}.id, {$segmentTable}.id")
      ->orderBy("{$subscriberTable}.id")
      ->setFirstResult($offset)
      ->setMaxResults($limit);
  }

  private function addSubscriberCustomFieldsToQueryBuilder(QueryBuilder $qb): QueryBuilder {
    $segmentsTable = $this->getTableName(SubscriberEntity::class);
    $customFieldsTable = $this->getTableName(CustomFieldEntity::class);
    $subscriberCustomFieldTable = $this->getTableName(SubscriberCustomFieldEntity::class);

    $customFields = $this->entityManager->getConnection()->createQueryBuilder()
      ->select("{$customFieldsTable}.*")
      ->from($customFieldsTable)
      ->execute();

    $customFields = $customFields->fetchAll();

    foreach ($customFields as $customField) {
      $customFieldId = "customFieldId{$customField['id']}";
      $qb->addSelect("MAX(CASE WHEN {$customFieldsTable}.id = :{$customFieldId} THEN {$subscriberCustomFieldTable}.value END) AS :{$customFieldId}")
        ->setParameter($customFieldId, $customField['id']);
    }

    $qb->leftJoin($segmentsTable, $subscriberCustomFieldTable, $subscriberCustomFieldTable, "{$segmentsTable}.id = {$subscriberCustomFieldTable}.subscriber_id")
      ->leftJoin($subscriberCustomFieldTable, $customFieldsTable, $customFieldsTable, "{$customFieldsTable}.id = {$subscriberCustomFieldTable}.custom_field_id");

    return $qb;
  }
}
