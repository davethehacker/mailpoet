import React from 'react';
import ReactStringReplace from 'react-string-replace';
import MailPoet from 'mailpoet';
import Hooks from 'wp-js-hooks';
import { Grid } from 'common/grid';
import { StatsBadge, getBadgeType } from 'common/listings/newsletter_stats/stats';
import Tooltip from 'help-tooltip';

import { NewsletterType } from './newsletter_type';

type Props = {
  newsletter: NewsletterType;
  isWoocommerceActive: boolean;
}

const minNewslettersSent = 20;
const minNewslettersOpened = 5;

export const NewsletterGeneralStats = ({
  newsletter,
  isWoocommerceActive,
}: Props) => {
  const totalSent = newsletter.total_sent || 0;

  let percentageClicked = 0;
  let percentageOpened = 0;
  let percentageMachineOpened = 0;
  let percentageUnsubscribed = 0;
  if (totalSent > 0) {
    percentageClicked = (newsletter.statistics.clicked * 100) / totalSent;
    percentageOpened = (newsletter.statistics.opened * 100) / totalSent;
    percentageMachineOpened = (newsletter.statistics.machineOpened * 100) / totalSent;
    percentageUnsubscribed = (newsletter.statistics.unsubscribed * 100) / totalSent;
  }
  // format to 1 decimal place
  const percentageClickedDisplay = MailPoet.Num.toLocaleFixed(percentageClicked, 1);
  const percentageOpenedDisplay = MailPoet.Num.toLocaleFixed(percentageOpened, 1);
  const percentageMachineOpenedDisplay = MailPoet.Num.toLocaleFixed(percentageMachineOpened, 1);
  const percentageUnsubscribedDisplay = MailPoet.Num.toLocaleFixed(percentageUnsubscribed, 1);

  const displayBadges = ((totalSent >= minNewslettersSent)
    && (newsletter.statistics.opened >= minNewslettersOpened)
  );

  const opened = (
    <div className="mailpoet-statistics-value-small">
      <span className="mailpoet-statistics-value-number">
        {percentageOpenedDisplay}
        {'% '}
      </span>
      {MailPoet.I18n.t('percentageOpened')}
    </div>
  );

  const machineOpened = (
    <div className="mailpoet-statistics-value-small">
      <span className="mailpoet-statistics-value-number">
        {percentageMachineOpenedDisplay}
        {'% '}
      </span>
      {MailPoet.I18n.t('percentageMachineOpened')}
      <Tooltip
        tooltip={ReactStringReplace(
          MailPoet.I18n.t('percentageMachineOpenedTooltip'),
          /\[link](.*?)\[\/link]/,
          (match) => (
            <span style={{ pointerEvents: 'all' }}>
              <a
                href="https://kb.mailpoet.com/article/368-what-are-machine-opens"
                key="kb-link"
                target="_blank"
                data-beacon-article="6124b7fb21ef206e5592e188"
                rel="noopener noreferrer"
              >
                { match }
              </a>
            </span>
          )
        )}
      />
    </div>
  );

  const unsubscribed = (
    <div className="mailpoet-statistics-value-small">
      <span className="mailpoet-statistics-value-number">
        {percentageUnsubscribedDisplay}
        {'% '}
      </span>
      {MailPoet.I18n.t('percentageUnsubscribed')}
    </div>
  );

  const badgeTypeClicked = getBadgeType('clicked', percentageClicked);
  const clicked = (
    <>
      <div className="mailpoet-statistics-value">
        <span className={`mailpoet-statistics-value-number mailpoet-statistics-value-number-${badgeTypeClicked}`}>
          {percentageClickedDisplay}
          {'% '}
        </span>
        {MailPoet.I18n.t('percentageClicked')}
      </div>
      {displayBadges && (
        <StatsBadge
          isInverted={false}
          stat="clicked"
          rate={percentageClicked}
          tooltipId={`clicked-${newsletter.id || '0'}`}
          tooltipPlace="right"
        />
      )}
    </>
  );

  return (
    <div className="mailpoet-stats-general">
      <Grid.ThreeColumns className="mailpoet-stats-general-top-row">
        <div>
          <div className="mailpoet-statistics-value-small">
            {MailPoet.I18n.t('statsTotalSent')}
            {': '}
            <span className="mailpoet-statistics-value-number">
              {totalSent.toLocaleString()}
            </span>
          </div>
        </div>
        <div className="mailpoet-statistics-with-left-separator">
          {unsubscribed}
        </div>
        <div />
      </Grid.ThreeColumns>
      <Grid.ThreeColumns>
        <div>
          {clicked}
        </div>
        <div className="mailpoet-statistics-with-left-separator">
          {opened}
          {machineOpened}
        </div>
        {isWoocommerceActive && (
          <div className="mailpoet-statistics-with-left-separator">
            {Hooks.applyFilters('mailpoet_newsletters_revenues_stats', null, newsletter.statistics.revenue)}
          </div>
        )}
        {!isWoocommerceActive && (
          <div />
        )}
      </Grid.ThreeColumns>
      <p className="mailpoet-stats-general-read-more">
        <a
          href="https://kb.mailpoet.com/article/190-whats-a-good-email-open-rate"
          target="_blank"
          rel="noopener noreferrer"
          data-beacon-article="58f671152c7d3a057f8858e8"
        >
          {MailPoet.I18n.t('readMoreOnStats')}
        </a>
      </p>
    </div>
  );
};
