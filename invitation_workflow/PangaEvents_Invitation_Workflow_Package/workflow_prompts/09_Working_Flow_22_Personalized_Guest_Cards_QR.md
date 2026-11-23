# Working Flow 22 — Personalized Guest Cards & Existing QR Assignment

Implement personalized invitation generation for actual authorized guest records. For each guest, render the saved master invitation design with guest name, authoritative pass type and allowed entries, event details and that guest's EXISTING unique QR credential from the application's established QR/admission service.

Do not invent, derive or create a parallel QR credential. Do not encode raw IDs, phone numbers or guessable values. Do not change allowed entries, used entries, guest status or admission records during rendering/export. Repeated renders must not duplicate guests, rotate credentials or create admissions.

Ensure QR quiet zone, contrast, physical size and scan reliability. Keep QR placement editable only within safe constraints; never allow it to overlap text or decorative elements. Apply private storage and event authorization to every generated card. Test Single, Double, Special and Family according to actual stored allowances, cross-event denial, repeat export idempotency and scanning through the existing scanner verification path. Add visual captures. Stop.
