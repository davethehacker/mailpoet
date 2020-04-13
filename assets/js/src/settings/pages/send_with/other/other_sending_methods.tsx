import React from 'react';
import { useSetting } from 'settings/store/hooks';
import SendingMethod from './sending_method';
import SPF from './spf';
import TestSending from './test_sending';
import ActivateOrCancel from './activate_or_cancel';
import PHPMailFields from './php_mail_fields';
import SmtpFields from './smtp_fields';

export default function OtherSendingMethods() {
  const [method] = useSetting('mta', 'method');
  return (
    <div className="mailpoet-settings-grid">
      <SendingMethod />
      {method === 'PHPMail' && <PHPMailFields />}
      {method === 'SMTP' && <SmtpFields />}
      <SPF />
      <TestSending />
      <ActivateOrCancel />
    </div>
  );
}
