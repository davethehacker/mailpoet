<?php
namespace MailPoet\Form\Block;

class Checkbox extends Base {

  static function render($block) {
    $html = '';

    $field_name = static::getFieldName($block);
    $field_validation = static::getInputValidation($block);

    $html .= '<p class="mailpoet_paragraph">';

    $html .= static::renderLabel($block);

    foreach($block['params']['values'] as $option) {
      $html .= '<label class="mailpoet_checkbox_label">';

      $html .= '<input type="checkbox" class="mailpoet_checkbox" ';

      $html .= 'name="'.$field_name.'" ';

      $html .= 'value="1" ';

      $html .= (isset($option['is_checked']) && $option['is_checked'])
                ? 'checked="checked"' : '';
      $html .= $field_validation;

      $html .= ' />'.$option['value'];

      $html .= '</label>';
    }

    $html .= '<span class="mailpoet_error_'.$block['id'].'"></span>';

    $html .= '</p>';

    return $html;
  }
}

