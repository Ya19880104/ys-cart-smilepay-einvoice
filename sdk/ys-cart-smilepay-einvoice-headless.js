export const YS_CART_SMILEPAY_INVOICE_PROVIDER_ID = 'smilepay';

export const YS_CART_SMILEPAY_INVOICE_CARRIERS = Object.freeze({
  member: 'member',
  mobile: 'mobile',
  cdc: 'cdc',
  donate: 'donate',
});

export function withSmilePayInvoice(checkoutPayload = {}, invoice = {}) {
  const payload = { ...checkoutPayload };
  payload.invoice = {
    provider: YS_CART_SMILEPAY_INVOICE_PROVIDER_ID,
    buyer_type: invoice.buyer_type || 'personal',
    carrier_type: invoice.carrier_type || 'member',
    ...invoice,
  };
  return payload;
}

export function withSmilePayCompanyInvoice(checkoutPayload = {}, company = {}) {
  return withSmilePayInvoice(checkoutPayload, {
    buyer_type: 'company',
    carrier_type: '',
    company_name: company.company_name || '',
    buyer_tax_id: company.buyer_tax_id || company.tax_id || '',
    buyer_email: company.buyer_email || company.email || '',
  });
}

