# Dependency remediation — P2-005

## IMPLEMENTED — verified 2026-10-16

Full isolated regression passed **356 tests / 3,117 assertions** (138.676902s). Focused real-mail rendering checks passed **3 tests / 68 assertions** (1.745481s). Composer locked audit reports zero advisories, abandoned packages or filtered findings; strict manifest validation and locked PHP platform checks pass. Package discovery and all 97 routes boot successfully. New-test Pint, PHP syntax and scoped whitespace checks pass.

The initial Composer audit contained 43 advisory records across 12 locked packages. The final locked audit has no advisories, abandoned packages or filtered findings. Record counts reflect Composer's feed, which can include overlapping reports for one upstream issue; they are not a claim of 43 distinct exploitable application paths.

| Package | Before | After |
|---|---|---|
| guzzlehttp/guzzle | 7.10.0 | 7.15.5 |
| guzzlehttp/promises | 2.3.0 | 2.5.3 |
| guzzlehttp/psr7 | 2.9.0 | 2.13.1 |
| laravel/framework | v12.58.0 | v12.70.0 |
| league/commonmark | 2.8.2 | 2.10.3 |
| league/flysystem | 3.33.0 | 3.36.0 |
| symfony/http-foundation | v7.4.8 | v7.4.21 |
| symfony/http-kernel | v7.4.11 | v7.4.21 |
| symfony/mailer | v7.4.8 | v7.4.19 |
| symfony/mime | v7.4.9 | v7.4.19 |
| symfony/polyfill-intl-idn | v1.37.0 | v1.43.0 |
| symfony/routing | v7.4.9 | v7.4.21 |
| symfony/yaml | v7.4.11 | v7.4.21 |

No packages were added or removed; Sanctum remains 4.3.3. Its root constraint changed to ^4.3.3 while the lock fixes the reviewed version. Guzzle requires the Promises update; the other 12 packages held the reported advisories. Laravel stays on major 12, Symfony on 7.4 and PHP on 8.2.12.

## CURRENT — reproducible checks

Resolution used a targeted Composer update of the 12 affected names with --with-all-dependencies --minimal-changes --no-install --no-scripts --no-plugins, after reviewing the equivalent dry run. Installation used the reviewed lock with --no-scripts --no-plugins. This avoided the repository's automatic asset publishing and setup/migration scripts. Package discovery ran explicitly with test DB/mail and temporary storage.

Checks: composer validate --strict --no-check-publish; composer audit --locked --format=json; composer check-platform-reqs --lock; installed-versus-lock version comparison; phpunit --do-not-cache-result --debug --log-junit; artisan route:list --json; new-test Pint and php -l; scoped git diff --check; 294-path preservation comparison. Tests use SQLite :memory:, array sessions/cache/mail and temporary storage. Existing public/build was preserved.

The real array-mail test runs the public recovery action, broker, notification, Markdown renderer and MIME serialization. It verifies email/phone lookup, registered recipient, trusted reset origin, valid broker token, HTML/plain content and rejection of CRLF in a legacy email without sensitive logging. Initial exception-class mismatch was a fixture assumption; Laravel now rejects line breaks before Symfony's MIME layer.

## CURRENT — before-audit identifiers

These captured Composer identifiers are cleared from the final audit:

- guzzlehttp/guzzle: PKSA-gcrk-3vtt-1r14, PKSA-cnw1-2ytm-cgr8, PKSA-fy2t-3c5f-827y, PKSA-qxvb-2bpp-dnk6, PKSA-bbs6-q5q9-f3t4, PKSA-bcdd-5xc7-gwfb, PKSA-pwsk-hy21-4gby, PKSA-93qv-9n9h-6k6p, PKSA-k22t-f949-t9g6.
- guzzlehttp/psr7: PKSA-vznr-tgp9-fd7d, PKSA-7qs6-zvnz-h66r, PKSA-gm5x-j3mz-71n9, PKSA-jj5t-2zs1-dcfm.
- laravel/framework: PKSA-d5tc-s1qs-h781, PKSA-m5cs-t1y6-qpcs, PKSA-3r5d-mb8f-1qw9, PKSA-mdq4-51ck-6kdq.
- league/commonmark: PKSA-m2dq-1fhr-29b1, PKSA-m4t9-vsgq-8khn, PKSA-zyf5-hrxv-hrd7, PKSA-nv44-1b4d-6gjg, PKSA-kr3s-894t-g5w2, PKSA-9q1p-3s19-bp1q, PKSA-5mzr-szzf-z6cn, PKSA-cqd6-fg4n-nxpf, PKSA-1q6p-sqkj-8mmj, PKSA-mc58-w91n-f5gv, PKSA-t21r-vtr5-3mdz, PKSA-scnn-p8mm-jbft.
- league/flysystem: PKSA-w9tt-7782-78jx.
- symfony/http-foundation: PKSA-xmwy-rmx5-m7h4, PKSA-y6py-qpv1-h52p.
- symfony/http-kernel: PKSA-dw7n-x7f5-zf63.
- symfony/mailer: PKSA-28rh-rzzn-djk4.
- symfony/mime: PKSA-wtxr-p26d-nn42, PKSA-2n2k-66v2-bwg3.
- symfony/polyfill-intl-idn: PKSA-dwsq-ppd2-mb1x.
- symfony/routing: PKSA-bf7t-jnpz-492k, PKSA-yc7t-91v9-99xs.
- symfony/yaml: PKSA-xz3r-9gp4-4m1z, PKSA-v5yj-8nmz-sk2q, PKSA-ft77-7h5f-p3r6, PKSA-b14r-zh1d-vdrc.

Maintainer references: [Laravel email validation](https://github.com/laravel/framework/security/advisories/GHSA-5vg9-5847-vvmq), [Symfony HeaderUtils fix and 7.4.21 follow-up](https://symfony.com/cve-2026-108082), [Guzzle HTTPS proxy handling](https://github.com/guzzle/guzzle/security/advisories/GHSA-wpwq-4j6v-78m3).

## REQUIRES VERIFICATION — deployment limits

A clean dated dependency audit does not certify the whole application or future advisory state. Repeat audit and platform checks for deployment; verify production SMTP/proxy behavior and MySQL/hosting prerequisites. No real DB migration, external message, frontend change or deployment occurred. See [phase report](PHASE_02_REPORT.md), [risks](RISK_REGISTER.md) and [deployment](DEPLOYMENT_CPANEL.md).
