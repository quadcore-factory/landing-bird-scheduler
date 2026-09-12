# Landing Bird Scheduler 0.1 booking model

This plugin provides one public calendar for one business. A visitor can choose a date and slot before signing in, then the selected slot is preserved through the WordPress login redirect. A WordPress account is required before a 15-minute payment hold is created.

The default weekly window is Monday through Friday, 09:00–17:00 in `America/Mexico_City`. The business can change the IANA timezone, enabled days, daily window, optional break, date overrides, minimum notice, and maximum horizon from **Settings > Scheduler**. Starts use a 30-minute grid; the default maximum duration is 90 minutes. The initial price is one configurable price for the generic session.

WooCommerce owns checkout, payment status, customer receipts, and refund execution. The plugin creates a single order with a session fee. A payment that arrives after the hold expires, after an administrative cancellation, or after another booking occupies the time is marked `refund_pending`; it never claims the slot.

Clients can view their sessions in `[landing_bird_my_sessions]`, cancel with at least 24 hours' notice, reschedule one time to another available slot, or request a refund for business review. Paid cancellations and refund requests use `refund_requested`; the business completes the actual refund in WooCommerce and can record the resulting status in the Bookings screen.

Administrators can create a booking on behalf of a client, creating a WordPress account when necessary. External payment method and reference are retained for audit. Availability and conflicts are checked by default; an explicit override is recorded in the admin note. Plugin-owned contact and note fields are anonymized after 12 months.

The v0.1 scope excludes staff calendars, multiple services, calendar synchronization, customer email confirmation, card storage, direct payment-provider APIs, subscriptions, coupons, and external meeting URLs.
