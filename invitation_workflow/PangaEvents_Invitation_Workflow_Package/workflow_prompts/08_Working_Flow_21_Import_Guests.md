# Working Flow 21 — Import Guests

Implement secure guest import using a downloadable template and CSV/XLSX formats supported by the project. Preview rows, validate names/phones/pass types, show row-level errors, detect duplicates and require owner confirmation before committing. Use transactions and idempotency to prevent partial duplicate imports. Enforce event authorization. Reuse existing guest creation and admission services. Add focused tests and stop.
