<?php
namespace MailPoet\Config;
use \MailPoet\Models\Segment;
use \MailPoet\Models\Setting;

if(!defined('ABSPATH')) exit;

class Menu {
  function __construct($renderer, $assets_url) {
    $this->renderer = $renderer;
    $this->assets_url = $assets_url;
  }

  function init() {
    add_action(
      'admin_menu',
      array($this, 'setup')
    );
  }

  function setup() {
    add_menu_page(
      'MailPoet',
      'MailPoet',
      'manage_options',
      'mailpoet',
      array($this, 'home'),
      $this->assets_url . '/img/menu_icon.png',
      30
    );
    add_submenu_page(
      'mailpoet',
      __('Newsletters'),
      __('Newsletters'),
      'manage_options',
      'mailpoet-newsletters',
      array($this, 'newsletters')
    );
    add_submenu_page(
      'mailpoet',
      __('Subscribers'),
      __('Subscribers'),
      'manage_options',
      'mailpoet-subscribers',
      array($this, 'subscribers')
    );
    add_submenu_page(
      'mailpoet',
      __('Segments'),
      __('Segments'),
      'manage_options',
      'mailpoet-segments',
      array($this, 'segments')
    );
    add_submenu_page(
      'mailpoet',
      __('Settings'),
      __('Settings'),
      'manage_options',
      'mailpoet-settings',
      array($this, 'settings')
    );
    // add_submenu_page(
    //   'mailpoet',
    //   __('Newsletter editor'),
    //   __('Newsletter editor'),
    //   'manage_options',
    //   'mailpoet-newsletter-editor',
    //   array($this, 'newletterEditor')
    // );
    $this->registered_pages();
  }

  function registered_pages() {
    global $_registered_pages;
    $pages = array(
      //'mailpoet-form-editor' => 'formEditor',
      'mailpoet-newsletter-editor' => array($this, 'newletterForm')
    );
    foreach($pages as $menu_slug => $callback) {
      $hookname = get_plugin_page_hookname($menu_slug, null);
      if(!empty($hookname)) {
        add_action($hookname, $callback);
      }
      $_registered_pages[$hookname] = true;
    }
  }

  function home() {
    $data = array();
    echo $this->renderer->render('index.html', $data);
  }

  function settings() {
    $data = array();
    $flags = array();

    // check if registration is enabled
    if(is_multisite()) {
        // get multisite registration option
        $registration = apply_filters(
          'wpmu_registration_enabled',
          get_site_option('registration', 'all')
        );

        // check if users can register
        $flags['registration_enabled'] = !(in_array($registration, array('none', 'blog')));
    } else {
        // check if users can register
        $flags['registration_enabled'] = (bool)get_option('users_can_register', false);
    }

    $data['flags'] = $flags;

    $data['segments'] = Segment::findArray();

    $settings = Setting::findMany();
    $data['settings'] = array();
    foreach($settings as $setting) {
      $data['settings'][$setting->name] = $setting->value;
    }

    echo $this->renderer->render('settings.html', $data);

  }

  function subscribers() {
    $data = array();

    $data['segments'] = Segment::findArray();

    echo $this->renderer->render('subscribers.html', $data);
  }

  function segments() {
    $data = array();
    echo $this->renderer->render('segments.html', $data);
  }

  function newsletters() {
    $data = array();

    $data['segments'] = Segment::findArray();
    $settings = Setting::findArray();
    $data['settings'] = array();
    foreach($settings as $setting) {
      $data['settings'][$setting['name']] = $setting['value'];
    }
    echo $this->renderer->render('newsletters.html', $data);
  }

  function newletterForm() {
    $data = array();
    wp_enqueue_media();
    wp_enqueue_script('tinymce-wplink', includes_url('js/tinymce/plugins/wplink/plugin.js'));
    wp_enqueue_style('editor', includes_url('css/editor.css'));
    echo $this->renderer->render('newsletter/form.html', $data);
  }
}
