<?php
namespace MailPoet\Test\Config;

use Helper\WordPress;
use MailPoet\Config\Shortcodes;
use MailPoet\Models\Newsletter;
use MailPoet\Models\SendingQueue;
use MailPoet\Newsletter\Url;
use MailPoet\Router\Router;

class ShortcodesTest extends \MailPoetTest {
  function _before() {
    $newsletter = Newsletter::create();
    $newsletter->type = Newsletter::TYPE_STANDARD;
    $newsletter->status = Newsletter::STATUS_SENT;
    $this->newsletter = $newsletter->save();
    $queue = SendingQueue::create();
    $queue->newsletter_id = $newsletter->id;
    $queue->status = SendingQueue::STATUS_COMPLETED;
    $this->queue = $queue->save();
  }

  function testItGetsArchives() {
    $shortcodes = new Shortcodes();
    WordPress::interceptFunction('apply_filters', function() use($shortcodes) {
      $args = func_get_args();
      $filter_name = array_shift($args);
      switch ($filter_name) {
        case 'mailpoet_archive_date':
          return $shortcodes->renderArchiveDate($args[0]);
        case 'mailpoet_archive_subject':
          return $shortcodes->renderArchiveSubject($args[0], $args[1], $args[2]);
      }
      return '';
    });
    // result contains a link pointing to the "view in browser" router endpoint
    $result = $shortcodes->getArchive($params = false);
    WordPress::releaseFunction('apply_filters');
    $dom = \pQuery::parseStr($result);
    $link = $dom->query('a');
    $link = $link->attr('href');
    expect($link)->contains('endpoint=view_in_browser');
    // request data object contains newsletter hash but not newsletter id
    $parsed_link = parse_url($link);
    parse_str(html_entity_decode($parsed_link['query']), $data);
    $request_data = Url::transformUrlDataObject(
      Router::decodeRequestData($data['data'])
    );
    expect($request_data['newsletter_id'])->isEmpty();
    expect($request_data['newsletter_hash'])->equals($this->newsletter->hash);
  }
}
