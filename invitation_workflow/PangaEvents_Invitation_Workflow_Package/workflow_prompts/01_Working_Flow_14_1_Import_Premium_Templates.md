# Working Flow 14.1 — Import & Catalogue Existing Premium Templates

Inspect the existing project and locate every premium invitation template asset previously supplied or implemented. Do not create substitute mediocre designs and do not flatten editable artwork.

Build the real template catalogue using existing assets. Preserve stable template IDs and existing saved selections. Add categories, search/filter, responsive gallery, high-resolution preview, loading/empty/error states and pagination or lazy loading suitable for 100+ templates.

Each template record must expose its stable ID, title, category, thumbnail, preview/master asset references, supported editable layers, supported color variants, photo slots and availability. Use database-backed catalogue only if the current architecture requires admin-managed templates; otherwise reuse the existing source of truth and explain the decision.

Add authorization so customers see only published templates. Do not modify guest records or generate QR credentials in this flow. Add focused tests for catalogue, authorization, pagination, missing assets and preservation of existing template IDs. Visually compare against supplied references. Stop after this flow.
