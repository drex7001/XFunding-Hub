# 01 — Product scope

**Language:** English · [Singlish (සිංහල)](01-product-scope.si.md)

## Purpose

Connect a bank that needs wholesale liquidity with verified banks willing to lend. Lenders can see the borrower bank's identity and relevant financial documents. The borrower can see offer terms, but cannot see the lender's identity until the platform fee is verified.

The platform does not hold or transfer the loan principal. The two banks settle through their own official banking channels. The platform only collects its service fee; the MVP does not provide an escrow service.

## Parties

| Party | Responsibility |
|---|---|
| Borrower Bank | Submit a financial profile, publish requests, provide receiving instructions, select an offer, pay the fee, confirm settlement |
| Lender Bank | Assess the borrower, submit offers, provide settlement instructions, confirm settlement |
| Platform Operations | Verify banks, control platform access, handle fee exceptions and disputes |

Borrower/Lender are a bank's business capabilities. Bank Admin/Maker/Approver/Auditor are the roles of that bank's users. The same bank can have both capabilities.

## Included in the MVP

- Bank onboarding, platform verification and adding staff by invitation.
- Bank profiles, request-specific audit reports and private document access.
- Funding requests: amount, currency, tenor, settlement date and bid deadline.
- Offers from multiple lenders; anonymous comparison for the borrower.
- Maker–checker approvals and bank-scoped permissions.
- Selecting one offer, recording the platform fee and unlocking identity with a verified payment.
- Settlement confirmation from both parties, basic dispute handling and audit history.
- Borrower/lender views in the bank dashboard and a limited operations dashboard.

## Working defaults

These are proposed defaults to keep the implementation simple. If you change any of them, include the change in the relevant tasks before development starts.

| Item | MVP default |
|---|---|
| Offer coverage | One offer for the request's full amount, currency and tenor |
| Winner | One lender bank per request |
| Fee payer | Borrower Bank |
| Offer selection | The borrower compares terms and chooses; the lowest rate is not selected automatically |
| Application database | PostgreSQL; user decision 2026-10-16; local PostgreSQL 16.14. SQLite only for fast tests and the retained source snapshot |
| Staff membership | One bank per bank user account; separate accounts for platform staff |
| Roles | Fixed roles; no custom permission editor |
| Approvals | Another user's approval for publishing, bid submission, selection and settlement confirmation |
| Initial payment demo | A test payment adapter; do not use it as production payment verification |
| Approved demo fee | 10 bps (0.1%) of principal; same as the loan currency; round up to the smallest currency unit; simulated tax 0 |
| Approved demo payment window | 24 hours from selection; capped at the selected offer's expiry; quote/deadline/policy are frozen |
| Approved demo refund handling | Manual review and refund reference attestation; the app does not execute refund transfers |
| Request validation demo | LKR/USD/GBP; 2 decimal places; tenor 1–3,650 days; ACT/360 or ACT/365F; annualized simple rate basis |
| Financial documents | At least one PDF per request; maximum 10 MB per file; private storage separate from onboarding evidence |
| Deadlines | UTC; the future bid deadline cannot be after the settlement date |
| Offer validation demo | Annual simple percentage rate 0–100; 4 decimal places; full request amount/currency/tenor |
| Structured offer conditions | Unsecured or sovereign bonds; interest payment at maturity or monthly; no lender free text/attachments |
| Settlement instructions | Encrypted, independently approved immutable versions readable only by the bank's own treasury staff; each deal records the lender's version and the borrower's receiving version, and both are disclosed to the two banks' treasury staff only after the fee is verified |
| Minimum fee window | An offer cannot be selected with less than 60 minutes of validity left (`marketplace.minimum_fee_window_minutes`) |
| Platform accounts | Created by a platform admin or the `platform:create-admin` console command, with a recorded reason; approved by one person until live rules are decided |

## Later

Partial fills/syndicated loans, auctions with automatic matching, open chat, lender attachments before unlock, automated credit scoring, principal custody, direct RTGS/SWIFT integration, repayment servicing, SSO and multi-bank user accounts are outside the MVP.

## Business decisions to confirm

The Phase 0–5 implementation includes bank access, funding requests, blind offers/selection, demo fee verification/disclosure, settlement attestations/disputes, in-app notifications and a deal timeline. It uses a Laravel 13 / PHP 8.4+ monolith, a Blade UI, a local PostgreSQL application database, isolated PostgreSQL/SQLite test databases and a database queue. The local demo currently runs on Lerd PHP 8.4.25 / PostgreSQL 16.14; the earlier native PHP 8.5 preview has been stopped. Verification is a manual approval by an authorized platform reviewer with a reason; the real-bank due-diligence process has not been decided yet. The LK metadata and bank identities/documents in the demo seed are synthetic data only. Request conventions are implemented according to the working defaults above; this does not mean they have been confirmed as live business decisions.

Amounts are stored as integer minor units in the database and as decimal strings in the API. The demo range is `0.01` to `999999999999.99`; floating-point money is not used. Offer rates are stored as integer units and shown as decimal strings; an annualized simple percentage rate has 4 decimal places. Legal interest calculation/servicing is still outside the MVP.

**Demo decision — 2026-10-16:** The user confirmed using the 10 bps, same-currency, 24-hour, tax 0 and manual refund-review defaults above. The fee base is calculated with integer arithmetic as `ceil(principalMinor × 10 / 10000)`. The quote is frozen in the selection transaction and the approved policy version is snapshotted. Even when existing selected deals are quoted, the deadline is calculated from the original selection time. The test adapter only works when explicitly enabled in local/testing; it does not unlock in production. Real pricing, tax and refund obligations are not yet confirmed.

Settlement confirmations are independently approved attestations from the two banks; they are not platform transfer verification. References/notes are encrypted and available only to the bank's own treasury staff. In-app notifications are limited to generic status text; email notifications/exports are not included in this implementation. If an instruction is corrected during a dispute, the old evidence is preserved and confirmations are superseded; approval from both parties is required again.

| Decision | When it is needed |
|---|---|
| Launch market, eligible banks, currencies, verification requirements and tenor/rate conventions | Before using onboarding/request validation with real banks; demo defaults are listed above |
| Live fee formula/currency, taxes, payment window, refunds and failed-deal rules | Before enabling a real payment flow; only demo defaults are approved above |
| Payment provider or an independently verified bank-transfer process | Before production payment integration |
| Terms, anti-circumvention agreement, document retention and jurisdiction-specific regulatory review | Before live use with real banks/data |

Non-custodial is this product's boundary for handling money. These documents do not reach any conclusion about licensing or other regulatory obligations.

Because lenders can see the borrower's identity, identity hiding alone cannot stop lenders from contacting the borrower directly. Platform agreements and operational rules must also be part of the business design.

**Database decision — 2026-10-16:** The user chose PostgreSQL and deferred the payment verification method/provider decision. Existing user-edited bank profiles, memberships, documents, encrypted fee/deal/settlement evidence and notifications have been migrated to local PostgreSQL. The source SQLite file is kept unchanged. Selection/payment/settlement races in separate PostgreSQL sessions and an isolated SQL backup restore were verified. Production hosting, MFA, key/backup management and retention are still pending.

## Scenario that shows the MVP works

A verified borrower bank publishes a request. Two verified lender banks submit offers. The borrower compares terms without seeing lender identities. An approver selects one offer. Once the fee is verified, only that lender's details are unlocked. When both parties confirm settlement, the deal becomes settled.
