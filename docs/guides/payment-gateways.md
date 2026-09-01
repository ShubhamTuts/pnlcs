# Payment Gateways

A **gateway** is how you get paid. You need at least one enabled before real
customers can order. Configure them under **Configuration → Gateways**.

## Stripe

The most fully tested gateway.

1. Create a [Stripe](https://stripe.com) account.
2. In the Stripe dashboard, copy your **Publishable key** and **Secret key**
   (use **test mode** keys first).
3. In PNLCS, open **Configuration → Gateways → Stripe**, paste both keys, and enable it.
4. **Webhook (recommended):** in Stripe, add a webhook endpoint pointing to
   `https://your-domain/gateway/stripe/webhook` so payments confirm reliably.

Test with card `4242 4242 4242 4242`, any future expiry, any CVC.

## PayPal

1. Create a PayPal app at the [PayPal Developer](https://developer.paypal.com) portal.
2. Copy the **Client ID** and **Secret** (sandbox first).
3. In **Configuration → Gateways → PayPal**, paste them, choose sandbox or live, enable.
4. **Webhook:** point a PayPal webhook at
   `https://your-domain/gateway/paypal/webhook`.

!!! note "Payments are verified"
    PNLCS re-checks every PayPal capture directly with PayPal before marking an
    invoice paid, so a forged callback can't create a free order.

## Authorize.Net

Enter your **API Login ID** and **Transaction Key** under
**Configuration → Gateways → Authorize.Net**.

## Bank Transfer

For manual/offline payment:

1. Enable **Bank Transfer** and enter the account details/instructions shown to
   customers at checkout.
2. When a customer pays, they can submit a **payment notification** (with an
   optional receipt) from their invoice.
3. You review it under **Billing → Payment Notifications** and approve it in one
   click — which marks the invoice paid and triggers provisioning.

## Testing before you go live

Always place a **test order** and pay it in the gateway's test/sandbox mode
before accepting real money. Confirm the invoice becomes *Paid* and (if you
sell hosting) the service is provisioned. Then switch to live keys.

## Razorpay

UPI, cards, netbanking and wallets (India, and Razorpay's other markets).
Same coverage as Stripe and PayPal: Checkout, signed webhooks, refunds, and
shop currency. Plus **native Subscriptions** for recurring invoices and
optional AI credit packs.

1. Create a [Razorpay](https://razorpay.com) account and generate **Key ID**
   and **Key Secret** (start with `rzp_test_` keys).
2. **Configuration → Gateways → Razorpay**: paste the keys, set **Test Mode**,
   enable **Razorpay Subscriptions**, save, enable the gateway.
3. **Webhook:** `https://your-domain/gateway/razorpay/webhook`
   Header: `X-Razorpay-Signature`. Paste the **Webhook Secret** into PNLCS.
   Subscribe at least to:
   - `payment.captured`
   - `subscription.charged`
   - `subscription.cancelled`
   - `subscription.paused`
   - `subscription.resumed`
   - `subscription.halted`

Recurring hosting paid with Razorpay creates a Plan (`POST /v1/plans`) and a
Subscription (`POST /v1/subscriptions`). Checkout uses `subscription_id`.
Later `subscription.charged` events pay or raise the next PNLCS invoice.
The nightly invoice cron **skips** services that already have a live
Razorpay subscription, so the customer is not billed twice.

AI credit packs: on **AI Credits**, choose Razorpay and tick **Subscribe
monthly**. Each cycle tops up the same pack (still via an invoice, so the
wallet credit path stays the same).

Enable **Subscriptions** on the Razorpay dashboard (test and live). If the
Plans API is not enabled, PNLCS falls back to a one-time Order.

## PayPal subscriptions

PayPal Checkout here is **one invoice at a time**, like Stripe PaymentIntents.
PNLCS still raises renewal invoices on the billing cycle (`auto_renew` on the
service). The customer pays each invoice with PayPal Buttons. Native PayPal
Billing Plans are not used; Razorpay is the gateway that stores a mandate
and charges it.

!!! note "Payments are verified"
    One-time Razorpay checkouts verify `order_id|payment_id`. Subscription
    checkouts verify `payment_id|subscription_id`. Webhooks are HMAC-signed
    and the payment is re-fetched from Razorpay before the invoice is marked
    paid.

## Refunds

Any paid invoice can be refunded — fully or partially — from the admin invoice
page. Gateway refunds go back through the original provider; bank-transfer
payments are refunded offline. See
[The Billing Lifecycle](../concepts/billing-lifecycle.md#refunds).
