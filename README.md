# XFunding Hub

B2B interbank liquidity marketplace. Product scope and implementation order are in [docs/README.md](docs/README.md).

## Current milestone

Bank onboarding, access control, funding requests, blind offer selection, demo fee verification/disclosure and settlement attestations are implemented:

- Invitation-only bank accounts and separate platform operations accounts.
- Login, logout, password reset, rate limits and session revocation.
- Bank verification, suspension, capability approval and profile review.
- Fixed bank roles, independently approved invitations/role changes and last-admin protection.
- Private PDF onboarding documents with authorized, audited downloads.
- Bank workspace and platform operations screens.
- Funding drafts, private financial PDFs, independent publication/cancellation approval and frozen published terms.
- Borrower request dashboard and verified lender treasury marketplace.
- Exact monetary amounts, UTC deadlines and scheduled request expiry.
- Encrypted lender instruction versions with independent approval.
- Lender offer drafts/approvals, withdrawal/replacement and validity expiry.
- Anonymous borrower comparison, independent selection and one reserved deal per request.
- Frozen demo fee quotes, authenticated payment notifications and atomic verification/unlock.
- Audited treasury disclosure of the selected lender only, fee exception review and refund attestations.
- Independently approved pre-payment instruction changes with encrypted history.
- Separate maker–checker settlement attestations from each bank, disputes and audited operations resolutions.
- Role-based in-app notifications and a paginated deal timeline.

The demo workflow is verified on PostgreSQL, including concurrent sessions and an isolated backup restore. Live preparation remains in [the delivery plan](docs/04-delivery-plan.md). Payments currently use a synthetic local/test adapter; no real money is collected and it is disabled in production. Settlement records are bank attestations, not independent verification of an interbank transfer. The previous sample loan endpoints are disabled; their old models/states are retained as unused sample code.

## Local setup

Use PHP 8.4+ and Composer with PDO PostgreSQL, PDO SQLite, XML/DOM, mbstring, fileinfo and cURL. The current Lerd runtime is PHP 8.4.25 and PostgreSQL is 16.14. Laravel 13 and package versions are pinned in composer.lock. SQLite remains available for fast isolated tests.

For a **fresh checkout** with Lerd:

~~~sh
lerd link
lerd composer install
cp .env.example .env
lerd env
lerd php artisan migrate --no-interaction
lerd php artisan db:seed --no-interaction
~~~

Open **http://xfunding-hub.test**. The tracked .php-version selects PHP 8.4 and .lerd.yaml declares PostgreSQL. Lerd supplies local connection values and creates the application/testing databases. On an existing installation, retain .env and APP_KEY, install dependencies and run migrations; generating a new key would make stored encrypted instructions unreadable.

These screens use Blade and static CSS, so a frontend build is not required. For a native PHP installation, configure your PostgreSQL connection and run the same Artisan migrations/seeder before starting a local server.

The existing local demo was copied from SQLite into xfunding_hub without resetting users, bank decisions or history. The original database/database.sqlite is retained unchanged as a local snapshot. Sessions, password-reset tokens and cache were excluded, so sign in again. xfunding_hub_testing is separate and contains disposable synthetic test data.

For another local SQLite-to-PostgreSQL demo copy, keep the application in maintenance mode, retain the existing key/private files, and migrate an **empty PostgreSQL target** first:

~~~sh
lerd php artisan marketplace:import-sqlite /absolute/path/to/source.sqlite --dry-run
lerd php artisan marketplace:import-sqlite /absolute/path/to/source.sqlite
~~~

The importer refuses production and non-empty targets, validates encrypted records and document checksums, copies in one transaction and resets PostgreSQL ID sequences. It never writes the SQLite source. This is a local demo migration tool, not a general production migration service.

The current queue and scheduler run as Lerd workers. Start them on a fresh installation with:

~~~sh
lerd queue:start
lerd schedule:start
~~~

Without Lerd, use separate terminals for php artisan queue:work --tries=3 and php artisan schedule:work. The scheduler runs funding:expire, offers:expire and deals:expire-fees every minute; guards enforce deadlines immediately. Use Laravel's normal scheduler cron for deployment.

The current environment uses Mailpit SMTP, configured by Lerd, with its inbox at http://localhost:8025. The example environment defaults to log mail until a transport is configured. Invitations expire after three days and can be accepted once; staff invitations and role changes require independent approval.

## Demo accounts

Run the demo seeder only in a local/test environment. It refuses production. All institutions and addresses below are synthetic. Every demo account uses **DemoBank123!**.

| Account | Email |
|---|---|
| Platform operations | operations@xfunding.example |
| Borrower bank admin | borrower.admin@xfunding.example |
| Borrower maker / approver / auditor | borrower.maker@xfunding.example / borrower.approver@xfunding.example / borrower.auditor@xfunding.example |
| Verified lender staff | lender-one.admin@xfunding.example / lender-one.maker@xfunding.example / lender-one.approver@xfunding.example / lender-one.auditor@xfunding.example |
| Second verified demo lender | lender-three.admin@xfunding.example / lender-three.maker@xfunding.example / lender-three.approver@xfunding.example / lender-three.auditor@xfunding.example |
| Pending lender staff | lender-two.admin@xfunding.example / lender-two.maker@xfunding.example / lender-two.approver@xfunding.example / lender-two.auditor@xfunding.example |

Start with operations to review banks, or a bank admin to review its profile and request staff invitations. An institution profile change returns it to pending verification and disables its approved capabilities.

For the funding workflow, sign in as `borrower.maker@xfunding.example` and open `/funding-requests`. The seed creates one draft, one publication awaiting approval and two published requests with synthetic PDFs. Use `borrower.approver@xfunding.example` to review the pending publication, and `lender-one.maker@xfunding.example` to browse `/marketplace` and its published borrower documents. Admin-only and auditor-only users cannot browse other institutions' requests.

On an existing installation, add only the funding examples with `php artisan db:seed --class=DemoFundingRequestSeeder`. This seeder skips existing examples and preserves bank profiles, verification and memberships. Running the full demo bank seeder again resets demo account/bank settings.

To add the blind-offer examples to an existing installation, run `php artisan db:seed --class=DemoMarketplaceSeeder`. It adds a separate synthetic verified lender (Meridian Bank), approved instructions and two offers for each open demo request. Existing bank decisions, accounts and offer history are preserved, including any suspension of Summit Bank. The comparison contains an unsecured 8.1250% offer and a 7.9000% offer requiring sovereign bonds.

Open a published request as the borrower maker and choose **Compare offers**. Propose one offer, then sign in as the borrower approver to approve selection. The result is a locked deal; lender names, contacts and settlement accounts stay hidden. **Offer comparison walkthrough** provides a separate request for trying selection while **Working capital liquidity** remains available for comparison. All demo accounts use the password above.

## Funding request rules

The local demo supports LKR, USD and GBP with two decimal places, tenor 1–3,650 days, ACT/360 or ACT/365F, and an annualized simple rate basis. These are working defaults in `config/marketplace.php`; launch conventions still need a business decision. Amounts use integer minor units, with decimal strings at the API boundary, from `0.01` to `999999999999.99`. Send `"amount": "250000000.00"`, rather than a JSON number.

The bidding deadline must be future UTC time on or before the settlement date. Upload at least one financial PDF, maximum 10 MB per file, before requesting publication. These request documents are separate from private onboarding evidence.

Only a verified borrower treasury maker can draft or change a request. A different approver, who has not created, edited or attached documents to that request, must approve publication. Editing terms or adding/removing files supersedes the pending approval. A bank identity change also requires a fresh proposal. Publication snapshots the borrower identity/background and freezes terms/documents; material changes require cancellation and a new request. Cancellation requires an independent approver and a reason.

Verified lender makers/approvers can read open, unexpired requests from eligible borrower banks. Platform operations has no treasury or funding-document access by default. Every download is authorized and audited, with private, non-cacheable responses.

## Blind offers and selection rules

Lender makers propose settlement instructions and a different lender approver approves each immutable version. Account/contact details and selected instruction snapshots are encrypted in the database. Only own-bank maker/approver users can read these versions. BIC syntax accepts 8 or 11 characters with an alphabetic country element, following the [Swift BIC policy](https://www2.swift.com/knowledgecentre/rest/v1/publications/bic_policy/_latest/bic_policy.pdf?logDownload=true). Account/routing verification remains part of the institution's approval process.

Offers cover the full published amount/currency/tenor. The demo quotes annual simple percentage rates as decimal strings, 0–100 with up to four decimal places; rates use integer units internally. Structured conditions are `unsecured` / `sovereign_bonds` and interest payment `at_maturity` / `monthly`. Offer validity must cover the bidding deadline and end on or before settlement day (UTC). Arbitrary lender text, contact fields and attachments are excluded from the borrower comparison.

Draft edits supersede pending offer approvals, and publication contributors cannot approve their own work. One lender bank can have one active offer per request. Active terms are immutable: withdraw and obtain approval of a replacement. Labels and random comparison tokens belong to one request; borrower payloads contain no internal offer IDs, lender bank IDs, instruction IDs or actors. Lenders can read only their own offers. Own-bank admin/auditor users can read offer terms, with banking instruction fields excluded.

Borrower maker selection and independent borrower approval are separate actions. Approval locks the request, rechecks both banks, offer version/validity and approved instruction identity, snapshots selected terms/instructions and closes losing offers. Unique database constraints enforce one pending selection and one deal per request. Pending cancellation and selection proposals cannot coexist. The selected lender retains access to borrower financial evidence; other lenders lose that access once the request closes.

## Demo fee payment and disclosure

The approved demo defaults charge the borrower **10 bps (0.1%) of principal**, in the loan currency, with zero simulated tax. Fees round up to the smallest currency unit. The payment window is 24 hours from selection, capped at the selected offer's expiry. Amount, currency, deadline and policy are frozen on the deal; later configuration changes do not reprice it. These are demo rules, not live commercial pricing.

Enable synthetic payments locally before selecting an offer:

~~~sh
php artisan payments:setup-demo
php artisan db:seed --class=DemoPaymentSeeder
~~~

Setup creates a private signing key in `.env` without printing it or changing unrelated settings. The payment seeder only quotes existing selected demo deals; it preserves bank decisions and does not mark payments successful. `php artisan fees:quote-pending` quotes other existing reservations, with the deadline anchored to their original selection time.

Sign in as the borrower approver, open **Selected deals**, and choose **Request demo fee payment**. Copy the displayed `XFP-…` payment reference, then run:

~~~sh
php artisan payments:simulate 'XFP-REPLACE-WITH-THE-DISPLAYED-REFERENCE'
~~~

The command also accepts the fee-payment ID. It sends a synthetic signed notification through the same authentication, validation and verification handler as `POST /api/payments/demo/notifications`. It does not contact a payment provider or move money. After verification, refresh the deal and open **View settlement details** as a participating bank's authorized treasury maker/approver. Ordinary deal responses continue to omit lender identities and banking details; every disclosure is separately authorized, audited and non-cacheable. Admin-only, auditor-only and platform accounts cannot use this view.

Only an owning borrower approver can initiate a payment. The server determines the amount and reference; neither bank users nor operations can mark a fee paid or bypass verification. The handler authenticates the timestamp and raw payload signature, validates exact amount/currency/deal/reference and current bank/instruction eligibility, and atomically verifies the receipt and unlocks the deal. Cancelled reservations cannot be revived by late payment.

To exercise failure cases, the simulator supports `--outcome=failed`, `--amount=`, `--currency=`, `--deal=`, `--event=` and `--charge=`. Each invocation creates a new event and charge unless supplied. To replay one delivery, repeat the same `--event` and `--charge` UUIDs with the same payload. A new charge after successful payment is an additional receipt requiring refund review; it is not a duplicate delivery.

Operations can review payment exceptions at `/operations/payments`; detailed receipt access requires a recorded reason. Wrong, late, unmatched or additional receipts enter refund review. Recording a refund reference is a manual attestation only and never executes a refund transfer. Refunding an additional receipt does not remove the original verified payment. Actual provider verification, reconciliation and refund execution remain launch work.

Before payment, a lender maker can propose a different approved instruction version for independent lender approval. Pending payment attempts or refund reviews block changes, and pending changes block payment. Approved changes preserve the old encrypted snapshot and fee/financial terms, with an identity-free borrower notice. After unlock, changes require an open dispute and independent lender review; approving a correction supersedes previous confirmations and requires both institutions to attest again.

## Settlement and disputes

After fee verification, each participating bank's maker can propose a settlement attestation with the UTC transfer time, optional reference and institution note. A different approver from that bank must review it. References, notes and review reasons are encrypted and visible only to that bank's treasury maker/approver users. Other participants and admin/auditor users see confirmation status. The proposing maker must still be eligible at approval time.

One approved institution keeps the deal `Unlocked`. Two independently approved confirmations against the current instruction version atomically mark both the deal and funding request `Settled`. Payment proof, bank eligibility and approved instructions are rechecked under the existing bank/request/deal locks. No browser or operations action can substitute for either institution's approval.

An owning bank approver can open an issue while the deal is unlocked. The deal becomes `Disputed`. Institutions may continue collecting attestations, but both confirmations do not automatically finalize a disputed deal. Operations reviews it at `/operations/disputes`; reading evidence requires a reason and an audit record. A `Settled` resolution requires both approved confirmations and current eligibility/instructions. Otherwise operations can record a reasoned `ClosedUnsettled` outcome, which cancels the request and supersedes pending attestations. This does not execute or reverse any transfer or refund a platform fee.

To prepare a synthetic pending borrower attestation on an existing paid demo deal:

~~~sh
php artisan db:seed --class=DemoSettlementSeeder
~~~

This optional local/test seeder preserves bank profiles and existing confirmations. It does not pay a fee, confirm both banks or reset a completed deal. Approve as `borrower.approver@xfunding.example`, then propose as `lender-three.maker@xfunding.example` and approve as `lender-three.approver@xfunding.example`. Use the actual selected lender's account if another offer was chosen. All references are synthetic; no principal transfer occurs in this demonstration.

The current **Offer comparison walkthrough** example has completed both synthetic attestations and an audited dispute resolution. **Working capital liquidity** remains available to compare offers. Start a new request to repeat the entire workflow; completed histories are preserved.

**Notifications** are in-app database notifications. Pending confirmations notify the same bank's other approvers; rejections notify its makers; outcomes notify eligible staff at both banks. Reads and acknowledgements recheck the current recipient, bank, role and deal relationship. Messages and timelines contain fixed status text, with no lender names, account/contact fields, actors, references or private narratives. Settlement emails and exports are not implemented. The deal timeline shows selection/fee milestones and paginated settlement events.

## Tests

~~~sh
lerd php vendor/bin/pest --compact
lerd php vendor/bin/pest --configuration=phpunit.pgsql.xml --compact
lerd php vendor/bin/pint --dirty --format agent
~~~

Tests use isolated PostgreSQL and in-memory SQLite databases with fake mail/storage. They cover bank isolation, maker–checker approvals, private downloads, exact amounts, immutable snapshots, anonymous comparison, winner constraints, authenticated payment notifications, atomic unlock/settlement, restricted disclosure, refund review, corrective instruction changes, disputes, role-based notifications and bounded query counts. The latest PostgreSQL run passed **423 tests / 1,847 assertions**. The SQLite run passed 411 tests / 1,783 assertions, with 12 PostgreSQL-only tests skipped.

phpunit.xml forces an in-memory SQLite database. phpunit.pgsql.xml forces the local Lerd database xfunding_hub_testing; never point it at retained data. Concurrency tests deliberately rebuild that test database and must run serially, without --parallel. They synchronize separate PostgreSQL backend sessions for competing selections, duplicate/extra payment receipts and simultaneous settlement approvals. The complete HTTP journey exercises both possible lender winners, refund review, disclosure and settlement without a real payment provider.

Session-authenticated offer/instruction endpoints are `/api/v1/offers` and `/api/v1/settlement-instructions`; selected deals use `/api/v1/deals/{deal}`. Borrower comparison is `GET /api/v1/funding-requests/{request}/offers`; selection proposals use `POST /api/v1/funding-requests/{request}/selection` with `offer_token`. Use `php artisan route:list` for explicit review/withdrawal routes.

Fee initiation uses `POST /api/v1/deals/{deal}/fee-payments`; disclosure uses `GET /api/v1/deals/{deal}/settlement-instructions`. These routes use session authentication and CSRF for mutations. The separate demo notification endpoint uses a timestamped HMAC signature instead of a browser session and refuses production even if demo configuration is enabled.

Settlement proposals use `POST /api/v1/deals/{deal}/confirmations`; independent reviews use `POST /api/v1/deals/{deal}/confirmations/{confirmation}/review`. Disputes use `POST /api/v1/deals/{deal}/disputes`. The timeline and in-app feed use `GET /api/v1/deals/{deal}/timeline` and `GET /api/v1/notifications`. Operations dispute review/resolution endpoints are session-authenticated, CSRF-protected POST actions under `/operations/disputes`.

Session-authenticated endpoints include `GET /api/v1/me`, `GET /api/v1/banks/{bank}`, `/api/v1/funding-requests` and `GET /api/v1/marketplace`. Funding actions use explicit submit/cancellation/approval routes; browser/session mutations require CSRF. No public registration or arbitrary status update endpoint is provided.

## Environment status and remaining launch work

The app now runs through Lerd at **http://xfunding-hub.test** with PostgreSQL 16.14. The earlier native preview on port 8017 has been stopped. Lerd also supplied the PostgreSQL, Redis and Mailpit connection values; the existing application key and bank data were retained.

Browser checks confirmed the migrated settled deal, in-app feed and selected lender's encrypted instructions. Lerd captured 11 requests with an 80 ms median and no slow routes. Its generic query fingerprints grouped some fixed queries across different tables; raw SQL review and the PostgreSQL query-count regressions found no record-count-dependent N+1 queries. These are local development samples, not a load test.

A PostgreSQL SQL export was restored into a separate temporary database. Counts and canonical row-content hashes matched across 29 persistent tables. The recovery databases were removed afterwards. A private temporary export remains under /tmp/xfunding-runtime/recovery-check/; it is a rehearsal artifact, not a durable backup. A production recovery plan must include the private documents, original application key, off-host encrypted backups and a tested recovery target.

Payment-provider selection remains deferred. Real payment verification/reconciliation/refunds, staff MFA, production secret and backup management, monitoring, retention rules, bank due diligence, platform terms and regulatory review remain launch work. Demo-ready does not mean live-ready.
