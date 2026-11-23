# Administrator CSV downloads

## IMPLEMENTED — P3-013 (2026-10-20)

The existing checkin, guest-list, checked-in, remaining-guest and invalid-scan CSV downloads are spreadsheet-facing reports. Their routes and access/filter rules are unchanged.

All checkin/report CSV output now uses one private writer. Formula-looking ASCII/fullwidth strings, formula prefixes after whitespace/control characters and leading C0 control bytes (0x00–0x1F) receive a TAB prefix in the exported quoted cell. This includes international +phone values. Ordinary Unicode/text, numeric counters, headers, column order, selection/filtering, filenames and MIME remain compatible. An explicit empty fputcsv escape preserves standard quote/comma/backslash/newline round-trip; stored guest/QR/manual-code/history values do not change. Typed XLSX export is unchanged.

## CURRENT — format and stored values

| Cell | Raw parsed CSV value |
|---|---|
| Ordinary guest name | Original text |
| Name beginning =, +, -, @ or fullwidth equivalent | TAB followed by original text |
| Formula prefix after whitespace/control characters | TAB followed by the complete original text |
| Value beginning with a C0 control byte (0x00–0x1F) | TAB followed by original bytes |
| International phone beginning + | TAB followed by original phone |
| Numeric counter | Original numeric text |
| Quotes, commas, backslashes or internal newlines | Original value within standard CSV quoting |

The prefix is added only during download. Repeated downloads are stable; no guest name/phone/manual code, QR token, counter, admission or scan history is rewritten. CSV report columns do not gain a manual-code column. Existing XLSX output continues to use explicit text cells for untrusted values.

The bounded text-prefix policy follows the Excel-oriented guidance in [OWASP CSV Injection](https://community.owasp.org/attacks/CSV_Injection). Standard CSV quoting keeps field separators and quotes inside the intended cell; quoting alone does not assign a spreadsheet text type.

## IMPLEMENTED — verification evidence

The pre-fix HTTP regression reproduced unguarded formula cells in all five CSV downloads (5 failures / 35 assertions). After repair, focused report/CSV/checkin/selected-event/XLSX checks passed **61 tests / 569 assertions**. The complete isolated backend suite passed **828 tests / 13,004 assertions** (121.874075s), with no errors/failures/skips. Added 33 HTTP cases covering five routes, 23 cell payloads, repeatability, whole-database row conservation and existing browser authorization.

Compared all 344 pre-existing baseline paths and checked new additions. Only the existing controller CSV call sites/new helper, one new test file and task docs changed. Removing the helper and reversing four call substitutions reconstructs the original controller exactly after line-ending normalization. New-test Pint and both PHP syntax checks passed; the inserted method matches its Pint-formatted fragment while unrelated pre-existing controller formatting was preserved. Runtime remains 97 routes, with exact documented method/path agreement; existing read-only CLI options/exits and 31-table inventory smoke passed. No configured database, service, .env/app key, build, dependency, tenant policy, UI or Android change occurred.

## REQUIRES VERIFICATION — client behavior

CSV has no native cell type. The TAB remains part of raw exported data, so programmatic consumers must account for this documented report format. Actual Excel/LibreOffice rendering and edit/save/reopen behavior were not verified; no universal spreadsheet/parser guarantee is claimed. This repair does not enable customer tenancy or close the real ownership/platform/restore/fencing gates. Phase 3 remains IN PROGRESS.

See [risk register](RISK_REGISTER.md), [test strategy](TEST_STRATEGY.md), [API compatibility](API_CONTRACT.md), [project status](PROJECT_STATUS.md), [task evidence](PROJECT_PLAN.md) and [source](../app/Http/Controllers/AdminController.php).
