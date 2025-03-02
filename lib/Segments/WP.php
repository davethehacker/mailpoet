<?php

namespace MailPoet\Segments;

use MailPoet\DI\ContainerWrapper;
use MailPoet\Entities\SegmentEntity;
use MailPoet\Entities\SubscriberEntity;
use MailPoet\Models\ModelValidator;
use MailPoet\Models\Segment;
use MailPoet\Models\Subscriber;
use MailPoet\Models\SubscriberSegment;
use MailPoet\Newsletter\Scheduler\WelcomeScheduler;
use MailPoet\Settings\SettingsController;
use MailPoet\Subscribers\ConfirmationEmailMailer;
use MailPoet\Subscribers\Source;
use MailPoet\WooCommerce\Helper as WooCommerceHelper;
use MailPoet\WooCommerce\Subscription as WooCommerceSubscription;
use MailPoet\WP\Functions as WPFunctions;
use MailPoetVendor\Carbon\Carbon;
use MailPoetVendor\Idiorm\ORM;

class WP {

  /** @var WPFunctions */
  private $wp;

  /** @var WelcomeScheduler */
  private $welcomeScheduler;

  /** @var WooCommerceHelper */
  private $wooHelper;

  public function __construct(WPFunctions $wp, WelcomeScheduler $welcomeScheduler, WooCommerceHelper $wooHelper) {
    $this->wp = $wp;
    $this->welcomeScheduler = $welcomeScheduler;
    $this->wooHelper = $wooHelper;
  }

  public function synchronizeUser($wpUserId, $oldWpUserData = false) {
    $wpUser = \get_userdata($wpUserId);
    if ($wpUser === false) return;

    $subscriber = Subscriber::where('wp_user_id', $wpUser->ID)
      ->findOne();

    $currentFilter = $this->wp->currentFilter();
    // Delete
    if (in_array($currentFilter, ['delete_user', 'deleted_user', 'remove_user_from_blog'])) {
      return $this->deleteSubscriber($subscriber);
    }
    return $this->createOrUpdateSubscriber($currentFilter, $wpUser, $subscriber, $oldWpUserData);
  }

  private function deleteSubscriber($subscriber) {
    if ($subscriber !== false) {
      // unlink subscriber from wp user and delete
      $subscriber->set('wp_user_id', null);
      $subscriber->delete();
    }
  }

  private function createOrUpdateSubscriber($currentFilter, $wpUser, $subscriber = false, $oldWpUserData = false) {
    // Add or update
    $wpSegment = Segment::getWPSegment();
    if (!$wpSegment) return;

    // find subscriber by email when is false
    if (!$subscriber) {
      $subscriber = Subscriber::where('email', $wpUser->user_email)->findOne(); // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps
    }
    // get first name & last name
    $firstName = $wpUser->first_name; // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps
    $lastName = $wpUser->last_name; // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps
    if (empty($wpUser->first_name) && empty($wpUser->last_name)) { // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps
      $firstName = $wpUser->display_name; // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps
    }
    $signupConfirmationEnabled = SettingsController::getInstance()->get('signup_confirmation.enabled');
    $status = $signupConfirmationEnabled ? Subscriber::STATUS_UNCONFIRMED : Subscriber::STATUS_SUBSCRIBED;
    // we want to mark a new subscriber as unsubscribe when the checkbox from registration is unchecked
    if (isset($_POST['mailpoet']['subscribe_on_register_active']) && (bool)$_POST['mailpoet']['subscribe_on_register_active'] === true) {
      $status = SubscriberEntity::STATUS_UNSUBSCRIBED;
    }

    // we want to mark a new subscriber as unsubscribed when the checkbox on Woo checkout is unchecked
    if (isset($_POST[WooCommerceSubscription::CHECKOUT_OPTIN_PRESENCE_CHECK_INPUT_NAME])
      && !isset($_POST[WooCommerceSubscription::CHECKOUT_OPTIN_INPUT_NAME])
    ) {
      $status = SubscriberEntity::STATUS_UNSUBSCRIBED;
    }

    // subscriber data
    $data = [
      'wp_user_id' => $wpUser->ID,
      'email' => $wpUser->user_email, // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps
      'first_name' => $firstName,
      'last_name' => $lastName,
      'status' => $status,
      'source' => Source::WORDPRESS_USER,
    ];

    if ($subscriber !== false) {
      $data['id'] = $subscriber->id();
      unset($data['status']); // don't override status for existing users
      unset($data['source']); // don't override status for existing users
    }

    $addingNewUserToDisabledWPSegment = $wpSegment->deletedAt !== null && $currentFilter === 'user_register';

    $otherActiveSegments = [];
    if ($subscriber) {
      $subscriber = $subscriber->withSegments();
      $otherActiveSegments = array_filter($subscriber->segments ?? [], function ($segment) {
        return $segment['type'] !== SegmentEntity::TYPE_WP_USERS && $segment['deleted_at'] === null;
      });
    }
    $isWooCustomer = $this->wooHelper->isWooCommerceActive() && in_array('customer', $wpUser->roles ?? [], true);
    // When WP Segment is disabled force trashed state and unconfirmed status for new WPUsers without active segment
    // or who are not WooCommerce customers at the same time since customers are added to the WooCommerce list
    if ($addingNewUserToDisabledWPSegment && !$otherActiveSegments && !$isWooCustomer) {
      $data['deleted_at'] = Carbon::createFromTimestamp($this->wp->currentTime('timestamp'));
      $data['status'] = SubscriberEntity::STATUS_UNCONFIRMED;
    }

    $subscriber = Subscriber::createOrUpdate($data);
    if ($subscriber->getErrors() === false && $subscriber->id > 0) {
      // add subscriber to the WP Users segment
      SubscriberSegment::subscribeToSegments(
        $subscriber,
        [$wpSegment->id]
      );

      $subscribeOnRegisterEnabled = SettingsController::getInstance()->get('subscribe.on_register.enabled');
      $sendConfirmationEmail =
        $signupConfirmationEnabled
        && $subscribeOnRegisterEnabled
        && $currentFilter !== 'profile_update'
        && !$addingNewUserToDisabledWPSegment;

      if ($sendConfirmationEmail && ($subscriber->status === Subscriber::STATUS_UNCONFIRMED)) {
        /** @var ConfirmationEmailMailer $confirmationEmailMailer */
        $confirmationEmailMailer = ContainerWrapper::getInstance()->get(ConfirmationEmailMailer::class);
        $confirmationEmailMailer->sendConfirmationEmailOnce($subscriber);
      }

      // welcome email
      $scheduleWelcomeNewsletter = false;
      if (in_array($currentFilter, ['profile_update', 'user_register'])) {
        $scheduleWelcomeNewsletter = true;
      }
      if ($scheduleWelcomeNewsletter === true) {
        $this->welcomeScheduler->scheduleWPUserWelcomeNotification(
          $subscriber->id,
          (array)$wpUser,
          (array)$oldWpUserData
        );
      }
    }
  }

  public function synchronizeUsers() {
    $updatedUsersEmails = $this->updateSubscribersEmails();
    $insertedUsersEmails = $this->insertSubscribers();
    $this->removeUpdatedSubscribersWithInvalidEmail(array_merge($updatedUsersEmails, $insertedUsersEmails));
    unset($updatedUsersEmails);
    unset($insertedUsersEmails);
    $this->updateFirstNames();
    $this->updateLastNames();
    $this->updateFirstNameIfMissing();
    $this->insertUsersToSegment();
    $this->removeOrphanedSubscribers();

    return true;
  }

  private function removeUpdatedSubscribersWithInvalidEmail($updatedEmails) {
    $validator = new ModelValidator();
    $invalidWpUserIds = array_map(function($item) {
      return $item['id'];
    },
    array_filter($updatedEmails, function($updatedEmail) use($validator) {
      return !$validator->validateEmail($updatedEmail['email']);
    }));
    if (!$invalidWpUserIds) {
      return;
    }
    ORM::for_table(Subscriber::$_table)->whereIn('wp_user_id', $invalidWpUserIds)->delete_many();
  }

  private function updateSubscribersEmails() {
    global $wpdb;
    Subscriber::rawExecute('SELECT NOW();');
    $startTime = Subscriber::getLastStatement()->fetch(\PDO::FETCH_COLUMN);

    $subscribersTable = Subscriber::$_table;
    Subscriber::rawExecute(sprintf('
      UPDATE IGNORE %1$s
        INNER JOIN %2$s as wu ON %1$s.wp_user_id = wu.id
      SET %1$s.email = wu.user_email;
    ', $subscribersTable, $wpdb->users));

    return ORM::for_table(Subscriber::$_table)->raw_query(sprintf(
      'SELECT wp_user_id as id, email FROM %s
        WHERE updated_at >= \'%s\';
      ', $subscribersTable, $startTime))->findArray();
  }

  private function insertSubscribers() {
    global $wpdb;
    $wpSegment = Segment::getWPSegment();
    if (!$wpSegment) return;
    if ($wpSegment->deletedAt !== null) {
      $subscriberStatus = SubscriberEntity::STATUS_UNCONFIRMED;
      $deletedAt = 'CURRENT_TIMESTAMP()';
    } else {
      $signupConfirmationEnabled = SettingsController::getInstance()->get('signup_confirmation.enabled');
      $subscriberStatus = $signupConfirmationEnabled ? SubscriberEntity::STATUS_UNCONFIRMED : SubscriberEntity::STATUS_SUBSCRIBED;
      $deletedAt = 'null';
    }
    $subscribersTable = Subscriber::$_table;
    $inserterdUserIds = ORM::for_table($wpdb->users)->raw_query(sprintf(
      'SELECT %2$s.id, %2$s.user_email as email FROM %2$s
        LEFT JOIN %1$s AS mps ON mps.wp_user_id = %2$s.id
        WHERE mps.wp_user_id IS NULL AND %2$s.user_email != ""
      ', $subscribersTable, $wpdb->users))->findArray();

    Subscriber::rawExecute(sprintf(
      '
        INSERT IGNORE INTO %1$s(wp_user_id, email, status, created_at, `source`, deleted_at)
        SELECT wu.id, wu.user_email, "%4$s", CURRENT_TIMESTAMP(), "%3$s", %5$s FROM %2$s wu
          LEFT JOIN %1$s mps ON wu.id = mps.wp_user_id
          WHERE mps.wp_user_id IS NULL AND wu.user_email != ""
        ON DUPLICATE KEY UPDATE wp_user_id = wu.id
      ',
      $subscribersTable,
      $wpdb->users,
      Source::WORDPRESS_USER,
      $subscriberStatus,
      $deletedAt
    ));

    return $inserterdUserIds;
  }

  private function updateFirstNames() {
    global $wpdb;
    $subscribersTable = Subscriber::$_table;
    Subscriber::rawExecute(sprintf('
      UPDATE %1$s
        JOIN %2$s as wpum ON %1$s.wp_user_id = wpum.user_id AND wpum.meta_key = "first_name"
      SET %1$s.first_name = SUBSTRING(wpum.meta_value, 1, 255)
        WHERE %1$s.first_name = ""
        AND %1$s.wp_user_id IS NOT NULL
        AND wpum.meta_value IS NOT NULL
    ', $subscribersTable, $wpdb->usermeta));
  }

  private function updateLastNames() {
    global $wpdb;
    $subscribersTable = Subscriber::$_table;
    Subscriber::rawExecute(sprintf('
      UPDATE %1$s
        JOIN %2$s as wpum ON %1$s.wp_user_id = wpum.user_id AND wpum.meta_key = "last_name"
      SET %1$s.last_name = SUBSTRING(wpum.meta_value, 1, 255)
        WHERE %1$s.last_name = ""
        AND %1$s.wp_user_id IS NOT NULL
        AND wpum.meta_value IS NOT NULL
    ', $subscribersTable, $wpdb->usermeta));
  }

  private function updateFirstNameIfMissing() {
    global $wpdb;
    $subscribersTable = Subscriber::$_table;
    Subscriber::rawExecute(sprintf('
      UPDATE %1$s
        JOIN %2$s wu ON %1$s.wp_user_id = wu.id
      SET %1$s.first_name = wu.display_name
        WHERE %1$s.first_name = ""
        AND %1$s.wp_user_id IS NOT NULL
    ', $subscribersTable, $wpdb->users));
  }

  private function insertUsersToSegment() {
    $wpSegment = Segment::getWPSegment();
    $subscribersTable = Subscriber::$_table;
    $wpMailpoetSubscriberSegmentTable = SubscriberSegment::$_table;
    Subscriber::rawExecute(sprintf('
     INSERT IGNORE INTO %s(subscriber_id, segment_id, created_at)
      SELECT mps.id, "%s", CURRENT_TIMESTAMP() FROM %s mps
        WHERE mps.wp_user_id > 0
    ', $wpMailpoetSubscriberSegmentTable, $wpSegment->id, $subscribersTable));
  }

  private function removeOrphanedSubscribers() {
    // remove orphaned wp segment subscribers (not having a matching wp user id),
    // e.g. if wp users were deleted directly from the database
    global $wpdb;

    $wpSegment = Segment::getWPSegment();

    $wpSegment->subscribers()
      ->leftOuterJoin($wpdb->users, [MP_SUBSCRIBERS_TABLE . '.wp_user_id', '=', 'wu.id'], 'wu')
      ->whereRaw('(wu.id IS NULL OR ' . MP_SUBSCRIBERS_TABLE . '.email = "")')
      ->findResultSet()
      ->set('wp_user_id', null)
      ->delete();
  }
}
