# 04 — Delivery plan

**Language:** English · [Singlish (සිංහල)](04-delivery-plan.si.md)

Work through these phases in order. A following phase can be considered complete only after the previous phase's tasks and acceptance checks are finished. Phases 0–5 are currently implemented and Phase 6 demo verification is complete; live preparation remains. Payment verification is limited to a synthetic demo adapter and settlement evidence is limited to bank attestations.

## Phase 0 — New foundation

**Outcome:** An MVP skeleton that does not depend on the existing test application.

- [x] Review the [scope defaults and open decisions](01-product-scope.md) and record the decisions needed for onboarding.
- [x] Choose the supported stack/runtime. A simple Laravel monolith is the proposed default; preserving the existing sample version/schema/API contract is not a requirement.
- [x] When starting implementation, complete the repository `AGENTS.md` setup instructions as applicable. Decide whether to keep or remove the existing demo code and disable the public sample routes.
- [x] Set up dev/test databases, private file storage, a job runner and migrations.
- [x] Set up seed data with fake banks/users and a repeatable test setup.

**Done when:** A new developer can boot the app and run the tests. The marketplace flow does not depend on demo customer IDs/statuses.

## Phase 1 — Identity and access control

**Dependency:** Phase 0.

- [x] Login/logout, invitations, password reset, session revocation and login rate limits.
- [x] Bank, membership, capability and fixed roles; separate platform accounts.
- [x] Platform bank verification/suspension and a limited onboarding screen.
- [x] Bank-scoped policies, role administration and maker–checker approvals.
- [x] Private download authorization and access/approval audit events.

**Done when:** Bank A has no access to Bank B private data. Marketplace access for pending/suspended banks, self-approval, self-promotion and access from revoked sessions are rejected. Checks pass through both the UI and the API.

## Phase 2 — Borrower requests and financial documents

**Dependency:** Phase 1.

- [x] Bank profile, document upload and authorized download; file type/size limits.
- [x] FundingRequest draft, validation, approval, publish and cancellation.
- [x] Currency/rate/tenor conventions and exact decimal handling.
- [x] Freezing published terms/documents and the deadline expiry job.
- [x] Borrower request dashboard and published-request browse/detail screens for verified lenders.

**Done when:** A Maker prepares a request and a different Approver publishes it. A verified lender treasury user can see the borrower profile/documents; a public/unauthorized user cannot. A request past its deadline is not available in the lender marketplace/details/downloads. Enforcing the same cutoff for bid/select actions is part of Phase 3 acceptance.

**Validation:** 66 funding feature tests verify independent approvals, forged ownership/status, bank/role isolation, exact amounts, frozen snapshots/files, stale/replayed approvals, cancellation and immediate/idempotent expiry. A regression check confirms funding-detail queries do not grow as the document count increases. The demo seeder provides Draft, PendingApproval and Open requests with a synthetic PDF. Live database locking and Lerd route timings were pending until Phase 6.

Full suite: 157 tests / 460 assertions pass. Pint, compiled Blade syntax and Markdown link checks pass. In the browser, Maker create/edit/detail, Approver publication controls and Lender published-only marketplace/profile screens were checked. The local expiry scheduler was also run and command execution verified.

## Phase 3 — Blind offers and selection

**Dependency:** Phase 2.

- [x] Offer drafting, approval, withdrawal/replacement and expiry.
- [x] Maker–checker approval and version history for lender contact/settlement instructions.
- [x] Blocking own-request bidding and duplicate active offers.
- [x] Showing a lender only their own offers; a structured anonymous comparison for the borrower.
- [x] Borrower selection proposal + approval by a different Approver.
- [x] One deal per request through concurrent selection transactions and database constraints; selected terms snapshot and closure of losing offers.

**Done when:** Two lenders bid and a borrower compares/selects. Only one winner results. Pre-fee lender identity is not revealed through the current API/HTML responses and audit views. Expired/withdrawn offers cannot be selected. Anonymity checks are also needed for notifications/exports when they are added.

**Validation:** 99 offer/instruction/selection feature tests; full suite 256 tests / 857 assertions pass. Independent approvals, exact rates, anonymity, immutable encrypted instruction snapshots, withdrawal/replacement, eligibility/cutoff changes, stale sessions, approval replay and winner constraints were verified. Tests also verify that the comparison query count stays bounded as offers increase from 1 to 9, and that the demo seed is idempotent. Multi-session locking on the live database and Lerd timings must be verified in Phase 6.

At the Phase 3 handover, the deal was locked as AwaitingFee and the selected terms/instructions were snapshotted. Fee quote/deadline/verification were added in Phase 4 below. Repeat the notification/export anonymity checks when those surfaces are implemented.

In the browser, the lender offer dashboard/instruction version and the borrower comparison → Maker proposal → independent Approver approval → locked deal flow were verified. In the local walkthrough, one offer is Selected and the other is Unsuccessful; both offers on the Working capital request can still be compared. Compiled Blade syntax, Pint, Markdown links and whitespace checks pass.

## Phase 4 — Fee payment and controlled disclosure

**Dependency:** Phase 3; fee formula/currency/deadline/refund decisions recorded.

- [x] Confirm the demo fee formula/currency/deadline/tax/refund defaults with the user and record them in the scope document.
- [x] Fee calculation, frozen payment quote/policy and fee deadline; show the fee to the user up front.
- [x] An authenticated test adapter for the demo; explicit local/testing enable and production rejection.
- [x] Amount/currency/reference/deal validation, event/charge deduplication and an idempotent verification handler.
- [x] Atomic payment verification + unlock; disclose only the selected lender.
- [x] Failed/duplicate/late/unmatched payments, bank suspension and the RefundReview flow; reason-audited receipt reads and manual refund attestations.
- [x] An authorized, audited, no-store contact/settlement details view for treasury staff.
- [x] Independent approval, encrypted history and an identity-free notice for pre-payment instruction changes; blocking pending payments/refunds and post-unlock overwrites.
- [x] Fee deadline expiry command/scheduler, idempotent demo seeder and safe local setup/simulation commands.

**Done when:** A failed/fake or wrong-amount/currency payment does not unlock. A verified correct payment unlocks the deal exactly once. The other lenders stay hidden. A late payment does not revive a cancelled deal.

**Demo decisions:** 10 bps (0.1%) of principal, same as the loan currency, rounded up to the smallest unit, tax simulation 0, 24 hours from selection capped at offer expiry, manual review for refunds. The user approved these defaults for the demo. Real pricing/provider/refund policy are separate launch decisions.

**Validation:** 82 new fee/disclosure/refund/instruction-change tests; full suite **338 tests / 1,248 assertions pass**. They verify raw-body signature/timestamp, malformed/forged payloads, exact amounts, stale roles/sessions, bank suspension, expired reservations, copied payment proof, event conflicts, charge reuse, unmatched/extra receipts and atomic rollback. They also verify refund idempotency/reference uniqueness, independent instruction approvals and immutable fee/financial snapshots. The deal list query count stays bounded as deals increase from 2 to 8.

In the browser, the borrower Approver payment attempt → signed synthetic confirmation → Unlocked deal → selected Meridian Bank (Demo) settlement details flow was verified. The ordinary deal view stays identity-free. No real money collected. Actual payment provider/reconciliation, live database multi-session locking and Lerd route timings are pending in Phase 6; the native preview does not produce Lerd traffic samples.

Pint, compiled Blade syntax (86 files), Markdown file links and whitespace checks pass. Tests also verify that the local demo payment setup does not output the key and preserves existing environment settings. Run instructions are in the [project README](../README.md#demo-fee-payment-and-disclosure).

## Phase 5 — Settlement and operations

**Dependency:** Phase 4.

- [x] Maker–checker settlement attestation per bank, encrypted reference/note and current instruction-version checks.
- [x] Clearly showing in the UI when one and when two confirmations have been received.
- [x] Both confirmed → atomic deal/request Settled; dispute/ClosedUnsettled resolution and audit evidence.
- [x] Reason-audited operations evidence view for settlement disputes; no unilateral successful-settlement override.
- [x] Independently approved instruction correction during a dispute, encrypted history, superseding old confirmations and requiring fresh attestations.
- [x] Role-based in-app notifications and a paginated deal timeline; emails/exports are separate future surfaces.
- [x] An idempotent optional pending-attestation seeder for an existing paid demo deal; preserving user profiles/history.

**Done when:** One bank confirming does not mark it Settled. It becomes Settled only when approved confirmations from both parties exist. Operations cannot mark a successful transfer on its own.

**Validation:** **68** new settlement/dispute/notification tests; full suite **406 tests / 1,525 assertions pass**. They verify own-bank maker/checker roles, self/cross-bank approval, forged fields, timestamps, stale membership/instructions, pending/approved uniqueness, approval replay and atomic rollback. One-bank pending state, normal second approval, disputed dual-confirmation resolution, ClosedUnsettled closure, encrypted private evidence and two fresh approvals for corrected instructions were also verified. Notification recipient/current-role checks, generic timeline privacy/pagination and bounded query counts pass.

In the browser, the borrower approval → dispute → lender Maker proposal → independent lender approval → reason-audited operations review/resolution → Settled flow was verified. The role-scoped notification feed and paginated timeline screens were also checked. Pint, compiled Blade syntax (89 files), Markdown links and whitespace checks pass.

Live database multi-session locking, actual payment/refund integration and Lerd traffic timings still remain for Phase 6. The native preview has 0 Lerd `optimize_route` samples; passing automated query regressions is not treated as live runtime profiling.

## Phase 6 — Demo verification and live preparation

**Dependency:** Phases 1–5; the relevant business decisions for live-only tasks.

- [x] Record the choice of PostgreSQL; the user deferred the payment provider decision.
- [x] Run the full request → fee → settlement HTTP scenario with one borrower bank, two lender banks and separate Maker/Approver users; verify both cases where either lender can be the winner.
- [x] Pass access-control acceptance checks, expiry, duplicate payment and actual separate-session PostgreSQL selection/payment/settlement race checks.
- [x] Re-review lists, private downloads, disclosure, in-app notifications and timeline by bank role.
- [x] Fix the PostgreSQL notification JSON filtering bug with a data-preserving migration and verify existing payloads/IDs are preserved.
- [x] Copy the existing local SQLite history to PostgreSQL in one transaction and verify encrypted proof, user-edited profiles, private-file checksums and ID sequences.
- [x] Hit affected routes on the Lerd PHP 8.4 / PostgreSQL runtime and review N+1/slow-route regressions with query capture/optimize_route.
- [x] Restore the local PostgreSQL SQL export into a separate recovery database, compare counts/content hashes for 29 tables, and remove the temporary recovery databases.
- [x] Run the queue and expiry scheduler as Lerd workers and set up the local Mailpit SMTP connection.
- [ ] If email notifications/exports are added later, repeat the anonymity/access review for those surfaces; they do not exist yet.
- [ ] Connect a real payment provider/verified transfer process and complete sandbox reconciliation and actual refund checks. The user deferred this decision; the demo adapter is disabled in production.
- [ ] Implement/verify real-bank staff MFA.
- [ ] Set up production off-host encrypted backups, separate APP_KEY recovery, monitoring/alerting and a document retention policy.
- [ ] Complete the bank due-diligence process, platform terms, live fee/refund policy and jurisdiction-specific regulatory review.

**Validation — 2026-10-16:** PostgreSQL 16.14 suite **423 tests / 1,847 assertions pass**. SQLite suite **411 tests / 1,783 assertions pass**, with 12 PostgreSQL-only tests intentionally skipped. Two new integrated journey tests verify, through API actions, draft/PDF/publication, independently approved instructions/offers from both lenders, blind comparison, selection, wrong-amount refund review, exact signed payment/replay, restricted disclosure and both-bank settlement.

Six PostgreSQL concurrency tests start two actual backend sessions simultaneously behind a barrier and verify competing offer selections, approval replay, same-event/same-charge/additional-charge notifications and simultaneous bank approvals. There is only one pending choice/winner, one canonical allocation and one settlement event. The test configuration is restricted to the fixed xfunding_hub_testing database; do not run the suite in parallel.

The local app at http://xfunding-hub.test has been migrated to PostgreSQL. 5 banks, 17 users, 39 notifications and all deal/fee/settlement/audit history were copied; the source SQLite checksum is unchanged. Sessions/reset tokens/cache were not migrated, so users must sign in again. APP_KEY and private PDFs were preserved; tests verify that the importer rolls back on missing/changed files, a wrong key and non-empty targets.

In the browser, the migrated Settled deal, role-scoped notifications and the selected Meridian Bank encrypted account/contact details were verified. Lerd captured 11 requests with an 80 ms median; no slow routes. Findings where generic fingerprints grouped fixed queries on different tables together were reviewed with raw SQL; according to the existing PostgreSQL query-count regression checks, there is no N+1 where the query count grows with the record count. These local samples are not a load test.

In the backup rehearsal, counts and canonical row-content hashes for 29 persistent tables matched. The recovery databases were removed; the private temporary export is in /tmp/xfunding-runtime/recovery-check/. This is not a durable production backup or an off-host recovery drill. For real recovery, the database, private documents and original key must be secured separately and verified on a restore target.

**Demo-ready:** The full fake-data/test-payment flow and rejection scenarios have been verified.

Pint, compiled Blade syntax (95 files), Markdown links and whitespace checks pass. Lerd queue/scheduler workers are healthy; query capture has been disabled again. The verification browser session was ended; you can sign in again at the new local URL.

**Live-ready:** Not yet. Payment integration, MFA and the production/business checks above remain. Phase 6 is not marked complete.

## Post-review corrections — 2026-11-03

An independent review of Phases 0–6 found the issues below. All of them are corrected.

- [x] Borrower receiving instructions: maker–checker versions chosen with the selection, encrypted on the deal and disclosed to the selected lender's treasury after fee verification.
- [x] Dispute closure: `ClosedUnsettled` is available whenever `Settled` is not. It supersedes pending attestations and instruction reviews and records `cancelled_at`.
- [x] Invitation tokens are encrypted in queued jobs.
- [x] Saving an unchanged bank profile keeps its verification.
- [x] Recorded payment proof no longer depends on the demo flag. Demo proof is still never honoured outside local/testing.
- [x] Confirmation and dispute creation responses return their stored status.
- [x] A bank user's action is recorded in that user's institution history, and bank admins/auditors have a paginated audit view.
- [x] Platform account administration through the operations screen and `platform:create-admin`.
- [x] A 60-minute minimum fee window at selection.
- [x] Malformed route identifiers return 404 on PostgreSQL, and an array login email returns a validation error.
- [x] The selected lender no longer sees the comparison label. Selection, fee verification and reservation closure notify both banks. The funding-request link is hidden when the request cannot be read.
- [x] Revoked staff can be reinstated with independent approval, and unused instruction versions can be retired.
- [x] Foreign-key lookup indexes, browser hardening headers and the `symfony/mime` security update.
- [x] The retired loan sample is removed: its code, states, tests, Postman collection, Spatie dependency and empty database table.

**Validation — 2026-11-03:** PostgreSQL suite **492 tests / 2,188 assertions pass**. SQLite suite **480 tests / 2,123 assertions pass**, with 12 PostgreSQL-only tests skipped. A temporary sweep called every parameterised route as an unrelated bank user holding all four roles; all 82 requests were denied. A second sweep found no lender identity in ordinary borrower views before or after the fee. These changes were exercised through HTTP feature tests, not a browser walkthrough.

**Still open:** long-line and status-string cleanup.

## Interface and flow — 2026-11-03

- [x] Role-aware Home page with a work queue, and its count in the navigation on every page.
- [x] Progress tracker and one next step with its action on every request, offer and deal.
- [x] Conflicts on form submissions return to the page with the reason; friendly error pages replace raw ones.
- [x] Single-table offer comparison with one receiving-account choice, and one-step offer submission.
- [x] Grouped money, readable dates and short references; a rewritten design system with mobile navigation and no new dependency.

**Validation — 2026-11-03:** After the loan sample removal, the PostgreSQL suite **517 tests / 2,845 assertions pass**. SQLite suite **504 tests / 2,777 assertions pass**, with 13 PostgreSQL-only tests skipped. `RenderedFormsJourneyTest` submits only what the rendered forms contain and follows Home from draft to settled; deliberately broken fields and links made it fail. The authorization sweep (82 of 82 requests denied) and the lender-identity sweep were repeated with Home included. Screens were checked in headless-Chrome screenshots of rendered pages at desktop and mobile widths. A live click-through in a running browser has not been done.

## Handover for each phase

Tick the completed tasks and briefly record the relevant tests/checks and the decisions still remaining. If a requirement changes, update the scope/access/workflow document and then change the checklist. No extra ceremony or separate status documents are needed.
