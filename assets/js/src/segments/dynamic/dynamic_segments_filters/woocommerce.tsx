import React, { useEffect } from 'react';
import MailPoet from 'mailpoet';
import { find } from 'lodash/fp';
import ReactSelect from 'common/form/react_select/react_select';
import Select from 'common/form/select/select';
import { useSelect, useDispatch } from '@wordpress/data';

import { Grid } from 'common/grid';
import Input from 'common/form/input/input';
import {
  SegmentTypes,
  SelectOption,
  WindowProductCategories,
  WindowProducts,
  WindowWooCommerceCountries,
  WooCommerceFormItem,
} from '../types';

enum WooCommerceActionTypes {
  NUMBER_OF_ORDERS = 'numberOfOrders',
  PURCHASED_CATEGORY = 'purchasedCategory',
  PURCHASED_PRODUCT = 'purchasedProduct',
  TOTAL_SPENT = 'totalSpent',
  CUSTOMER_IN_COUNTRY = 'customerInCountry',
}

export const WooCommerceOptions = [
  { value: WooCommerceActionTypes.CUSTOMER_IN_COUNTRY, label: MailPoet.I18n.t('wooCustomerInCountry'), group: SegmentTypes.WooCommerce },
  { value: WooCommerceActionTypes.NUMBER_OF_ORDERS, label: MailPoet.I18n.t('wooNumberOfOrders'), group: SegmentTypes.WooCommerce },
  { value: WooCommerceActionTypes.PURCHASED_CATEGORY, label: MailPoet.I18n.t('wooPurchasedCategory'), group: SegmentTypes.WooCommerce },
  { value: WooCommerceActionTypes.PURCHASED_PRODUCT, label: MailPoet.I18n.t('wooPurchasedProduct'), group: SegmentTypes.WooCommerce },
  { value: WooCommerceActionTypes.TOTAL_SPENT, label: MailPoet.I18n.t('wooTotalSpent'), group: SegmentTypes.WooCommerce },
];

export function validateWooCommerce(formItems: WooCommerceFormItem): boolean {
  if (!(
    Object
      .values(WooCommerceActionTypes)
      .some((v) => v === formItems.action))
  ) {
    return false;
  }
  if (formItems.action === WooCommerceActionTypes.PURCHASED_CATEGORY && !formItems.category_id) {
    return false;
  }
  if (formItems.action === WooCommerceActionTypes.PURCHASED_PRODUCT && !formItems.product_id) {
    return false;
  }
  if (formItems.action === WooCommerceActionTypes.CUSTOMER_IN_COUNTRY && !formItems.country_code) {
    return false;
  }
  const numberOfOrdersIsInvalid = !formItems.number_of_orders_count
    || !formItems.number_of_orders_days
    || !formItems.number_of_orders_type;
  if (formItems.action === WooCommerceActionTypes.NUMBER_OF_ORDERS && numberOfOrdersIsInvalid) {
    return false;
  }
  if (formItems.action === WooCommerceActionTypes.TOTAL_SPENT
    && (!formItems.total_spent_amount || !formItems.total_spent_days || !formItems.total_spent_type)
  ) {
    return false;
  }
  return true;
}

type Props = {
  filterIndex: number;
}

export const WooCommerceFields: React.FunctionComponent<Props> = ({ filterIndex }) => {
  const segment: WooCommerceFormItem = useSelect(
    (select) => select('mailpoet-dynamic-segments-form').getSegmentFilter(filterIndex),
    [filterIndex]
  );

  const { updateSegmentFilter, updateSegmentFilterFromEvent } = useDispatch('mailpoet-dynamic-segments-form');

  const productCategories: WindowProductCategories = useSelect(
    (select) => select('mailpoet-dynamic-segments-form').getProductCategories(),
    []
  );
  const woocommerceCountries: WindowWooCommerceCountries = useSelect(
    (select) => select('mailpoet-dynamic-segments-form').getWooCommerceCountries(),
    []
  );
  const products: WindowProducts = useSelect(
    (select) => select('mailpoet-dynamic-segments-form').getProducts(),
    []
  );
  const wooCurrencySymbol: string = useSelect(
    (select) => select('mailpoet-dynamic-segments-form').getWooCommerceCurrencySymbol(),
    []
  );
  const productOptions = products.map((product) => ({
    value: product.id,
    label: product.name,
  }));

  const categoryOptions = productCategories.map((product) => ({
    value: product.id,
    label: product.name,
  }));

  const countryOptions = woocommerceCountries.map((country) => ({
    value: country.code,
    label: country.name,
  }));

  let optionFields;

  useEffect(() => {
    if (
      segment.number_of_orders_type === undefined
      && segment.action === WooCommerceActionTypes.NUMBER_OF_ORDERS
    ) {
      updateSegmentFilter({ number_of_orders_type: '=' }, filterIndex);
    }
    if (
      segment.total_spent_type === undefined
      && segment.action === WooCommerceActionTypes.TOTAL_SPENT
    ) {
      updateSegmentFilter({ total_spent_type: '>' }, filterIndex);
    }
  }, [updateSegmentFilter, segment, filterIndex]);

  if (segment.action === WooCommerceActionTypes.PURCHASED_PRODUCT) {
    optionFields = (
      <div>
        <ReactSelect
          dimension="small"
          key="select-segment-product"
          isFullWidth
          placeholder={MailPoet.I18n.t('selectWooPurchasedProduct')}
          options={productOptions}
          value={find(['value', segment.product_id], productOptions)}
          onChange={(option: SelectOption): void => updateSegmentFilter(
            { product_id: option.value },
            filterIndex
          )}
          automationId="select-segment-product"
        />
      </div>
    );
  } else if (segment.action === WooCommerceActionTypes.PURCHASED_CATEGORY) {
    optionFields = (
      <div>
        <ReactSelect
          dimension="small"
          key="select-segment-category"
          isFullWidth
          placeholder={MailPoet.I18n.t('selectWooPurchasedCategory')}
          options={categoryOptions}
          value={find(['value', segment.category_id], categoryOptions)}
          onChange={(option: SelectOption): void => updateSegmentFilter(
            { category_id: option.value },
            filterIndex
          )}
          automationId="select-segment-category"
        />
      </div>
    );
  } else if (segment.action === WooCommerceActionTypes.NUMBER_OF_ORDERS) {
    optionFields = (
      <>
        <Grid.CenteredRow>
          <Select
            key="select"
            value={segment.number_of_orders_type}
            onChange={(e): void => {
              updateSegmentFilterFromEvent('number_of_orders_type', filterIndex, e);
            }}
            automationId="select-number-of-orders-type"
          >
            <option value="=">{MailPoet.I18n.t('equal')}</option>
            <option value=">">{MailPoet.I18n.t('moreThan')}</option>
            <option value="<">{MailPoet.I18n.t('lessThan')}</option>
          </Select>
          <Input
            data-automation-id="input-number-of-orders-count"
            type="number"
            min={0}
            value={segment.number_of_orders_count || ''}
            placeholder={MailPoet.I18n.t('wooNumberOfOrdersCount')}
            onChange={(e): void => {
              updateSegmentFilterFromEvent('number_of_orders_count', filterIndex, e);
            }}
          />
          <div>{MailPoet.I18n.t('wooNumberOfOrdersOrders')}</div>
        </Grid.CenteredRow>
        <Grid.CenteredRow>
          <div>{MailPoet.I18n.t('inTheLast')}</div>
          <Input
            data-automation-id="input-number-of-orders-days"
            type="number"
            min={1}
            value={segment.number_of_orders_days || ''}
            placeholder={MailPoet.I18n.t('daysPlaceholder')}
            onChange={(e): void => {
              updateSegmentFilterFromEvent('number_of_orders_days', filterIndex, e);
            }}
          />
          <div>{MailPoet.I18n.t('days')}</div>
        </Grid.CenteredRow>
      </>
    );
  } else if (segment.action === WooCommerceActionTypes.TOTAL_SPENT) {
    optionFields = (
      <>
        <Grid.CenteredRow>
          <Select
            key="select"
            value={segment.total_spent_type}
            onChange={(e): void => {
              updateSegmentFilterFromEvent('total_spent_type', filterIndex, e);
            }}
            automationId="select-total-spent-type"
          >
            <option value=">">{MailPoet.I18n.t('moreThan')}</option>
            <option value="<">{MailPoet.I18n.t('lessThan')}</option>
          </Select>
          <Input
            data-automation-id="input-total-spent-amount"
            type="number"
            min={0}
            step={0.01}
            value={segment.total_spent_amount || ''}
            placeholder={MailPoet.I18n.t('wooTotalSpentAmount')}
            onChange={(e): void => {
              updateSegmentFilterFromEvent('total_spent_amount', filterIndex, e);
            }}
          />
          <div>{wooCurrencySymbol}</div>
        </Grid.CenteredRow>
        <Grid.CenteredRow>
          <div>{MailPoet.I18n.t('inTheLast')}</div>
          <Input
            data-automation-id="input-total-spent-days"
            type="number"
            min={1}
            value={segment.total_spent_days || ''}
            placeholder={MailPoet.I18n.t('daysPlaceholder')}
            onChange={(e): void => {
              updateSegmentFilterFromEvent('total_spent_days', filterIndex, e);
            }}
          />
          <div>{MailPoet.I18n.t('days')}</div>
        </Grid.CenteredRow>
      </>
    );
  } else if (segment.action === WooCommerceActionTypes.CUSTOMER_IN_COUNTRY) {
    optionFields = (
      <div>
        <ReactSelect
          dimension="small"
          key="select-segment-country"
          isFullWidth
          placeholder={MailPoet.I18n.t('selectWooCountry')}
          options={countryOptions}
          value={find(['value', segment.country_code], countryOptions)}
          onChange={(option: SelectOption): void => updateSegmentFilter(
            { country_code: option.value },
            filterIndex
          )}
          automationId="select-segment-country"
        />
      </div>
    );
  }

  return optionFields;
};
