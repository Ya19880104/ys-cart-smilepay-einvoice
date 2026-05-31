# YS CART SmilePay E-Invoice Headless Skill

Use this when integrating the SmilePay e-invoice provider in a YS CART storefront or checkout agent.

## Provider Contract

- Provider id: `smilepay`.
- The plugin is invoice-only. It must not register payment gateways or shipping methods.
- Runtime invoice registration is gated by YS CART provider lifecycle state. If the SmilePay provider or invoice method is disabled, do not show SmilePay-specific invoice options.
- Print/PDF URLs should be requested through YS CART invoice file proxy endpoints, not by exposing SmilePay credentials in the browser.

## Checkout Payload

Use the core YS CART checkout payload and add an `invoice` object:

```js
{
  invoice: {
    provider: "smilepay",
    buyer_type: "personal",
    carrier_type: "member"
  }
}
```

For company invoices, send:

```js
{
  invoice: {
    provider: "smilepay",
    buyer_type: "company",
    company_name: "Example Co.",
    buyer_tax_id: "12345678",
    buyer_email: "buyer@example.com"
  }
}
```

## Safety Rules

- Do not send `verify_key`, `grvc`, or raw SmilePay API credentials from frontend code.
- Do not call SmilePay API endpoints directly from the browser.
- If the order invoice section exposes company/person toggle UI, changing the toggle must reveal the relevant tax-id/company fields before checkout submission.
- Treat invoice print links as customer file URLs returned by YS CART, and open them with `rel="noopener noreferrer"`.

