# Landing Bird Scheduler

Landing Bird Scheduler is a single calendar WordPress plugin for paid sessions. It requires WordPress 6.4+, PHP 7.4+, and WooCommerce with an active gateway. It creates one virtual WooCommerce order per booking and delegates payment, tax, currency, receipt, and refund execution to WooCommerce.

Activate the plugin, configure Settings > Scheduler, and place `[landing_bird_booking]` and `[landing_bird_my_sessions]` on pages. The dynamic blocks `landing-bird/scheduler` and `landing-bird/my-sessions` are also available. Users choose a slot before login, but must be logged in to create the 15-minute payment hold. Slots are 30-minute increments, durations are 30/60/90 minutes, and the default availability is Monday-Friday, 09:00-17:00 in `America/Mexico_City`.

The REST adapter exposes public `GET /wp-json/lb-scheduler/v1/slots?date=YYYY-MM-DD`, authenticated booking creation, session listing, cancellation, and one reschedule. The WooCommerce payment-complete hook confirms a valid hold; late or conflicting payment is marked `refund_pending` for review and never claims the slot. Admin bookings use capability and nonce protected extension hooks and the Bookings screen. Operational integrations can listen to `lb_scheduler_operational_notice`.

The plugin stores only booking contact, timing, status, mode text, WooCommerce linkage, and minimal notes. A daily job anonymizes plugin-owned personal fields after 12 months. No card data, video URL, calendar sync, multi-staff calendar, subscriptions, coupons, or direct payment-provider API is included. Future work may add richer admin editing, date override UI, and gateway-specific refund workflows.

GPL-2.0-or-later. See [LICENSE](LICENSE).
