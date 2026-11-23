# WhatsApp Notification Hooks

The pledge, payment, meeting, and digital card modules are not present yet. When they are added, dispatch these events after the related business action succeeds, ideally after the database transaction commits.

- `PledgeCreated`: dispatch after a pledge is created.
- `PledgeReminderDue`: dispatch from the reminder scheduler or job when a pledge reminder is due.
- `PledgeCompleted`: dispatch after a pledge is marked completed.
- `PaymentCompleted`: dispatch after payment is confirmed. Include a card object or array when a digital card link is available.
- `NextMeetingScheduled`: dispatch after a meeting is scheduled or updated.
- `DigitalCardReady`: dispatch when the digital card link is ready.
- `ThankYouMessageRequested`: dispatch when an admin or workflow requests a thank-you message.
- `CongratulationsMessageRequested`: dispatch when an admin or workflow requests a congratulations message.

Listeners call `NotificationService`, which normalizes phone numbers and writes `notification_logs`. Listener failures are caught and reported so WhatsApp problems do not break pledge, payment, admission, or check-in flows.

Do not pass QR tokens in event context. The guest `manual_code` may be sent through WhatsApp.
