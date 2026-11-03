# 03 — Marketplace workflow

**Language:** English · [Singlish (සිංහල)](03-marketplace-workflow.si.md)

This flow must operate under the [access-control rules](02-access-control.md). The loan principal is exchanged outside the platform.

## 1. Bank onboarding

Platform Operations verifies the bank profile and grants Borrower/Lender capabilities. The Bank Admin invites staff and assigns roles. A pending bank does not get marketplace access.

A Lender Maker prepares contact and settlement instructions and a different Lender Approver approves them. An approved instruction version must exist before an active offer is submitted; a Bank Admin-only user cannot give this approval. A Borrower Maker and Approver prepare the borrower's receiving instructions the same way before proposing a selection. An Approver can retire an outdated version once no active offer, pending selection or open deal uses it.

## 2. Preparing and publishing a request

The Borrower Maker enters the amount, currency, tenor, expected settlement date, bid deadline and financial documents. When the request is published, the borrower's identity is clearly visible to lenders.

Publish the request only after a different Borrower Approver approves it. Freeze the published financial terms and document set. If a material change is needed, cancel the request and publish a new one; do not silently change the terms of a request that has received bids.

**Currently implemented flow:** The Maker saves a draft → uploads at least one private financial PDF → submits a publication proposal → an Approver who did not contribute to the request approves/rejects it. If terms or files change, the pending proposal is superseded, returned to Draft and must be submitted again. A change in the proposal's bank identity also requires fresh approval. At publication, the borrower identity/background is snapshotted and terms/documents are frozen. If rejected, it returns to Draft with the reason.

Cancelling a Draft/Open request is also a Maker proposal + a different Approver review. While a cancellation review is pending, draft edits are blocked; an open request stays open until approval. If approved, it becomes Cancelled; if rejected, the previous state remains. An expired request cannot be published/revived.

Do not limit tenor to months only. Keep amounts/rates as exact decimals. Show the currency, annual rate basis and day-count convention clearly; legal loan interest calculation/servicing is outside the MVP.

Local request defaults: LKR/USD/GBP, amount with 2 decimal places, tenor 1–3,650 days, ACT/360 or ACT/365F, annualized simple rate basis. Send the amount as a decimal string in the API. The bidding deadline must be a future time in UTC and on or before the settlement date. Maximum 10 MB per financial PDF.

Verified lender Maker/Approver users see only open, unexpired requests from eligible borrower banks via `/marketplace`. Drafts, approval history and onboarding evidence are not shown. Once the deadline passes, guards stop access immediately; the `funding:expire` command, scheduled every minute, closes the persisted status and pending approvals and writes a system audit event. How to run the local scheduler is in the [project README](../README.md).

## 3. Borrower review and offers

A verified Lender Maker can view the published borrower profile and relevant documents. The Maker prepares an offer matching the request amount/currency/tenor and provides the rate, permitted structured conditions and validity deadline.

The offer becomes Active after a different Lender Approver approves it. One lender bank can have only one active offer per request. An offer can be withdrawn before it is selected. To change terms, withdraw the old offer and get a replacement approved again; keep the old version in the audit history.

Do not give the pre-unlock borrower view arbitrary lender text, attachments, signatures or contact fields. Limit the MVP comparison to structured fields; do not show lender internal notes to the borrower.

In the Phase 3 implementation, settlement instructions are proposed/reviewed as separate immutable versions. Account/contact details are encrypted in the database. If the bank profile changes, a new offer cannot be activated/selected using the old version; a fresh approved version is required. An existing selected instruction snapshot is not overwritten by a new version.

Offer defaults: rate as a decimal string, 0–100 percent, 4 decimal places; collateral unsecured/sovereign bonds; interest payment at maturity/monthly. Offer validity must cover the bid deadline and must not end after the settlement date. Draft/PendingApproval edits supersede the old approval. An active offer is immutable; a replacement requires withdrawal + fresh approval. The `offers:expire` command closes unselected validity-expired offers every minute.

## 4. Blind comparison and selection

The borrower sees the active offers on their request as `Offer 1`, `Offer 2` with their terms. The label must be limited to within the request. Do not use the lender bank ID or a stable cross-request identifier for it.

The Borrower Maker proposes the selection and names the bank's approved receiving version. When a different Borrower Approver approves it, recheck within a transaction that the request is still Open, the offer is Active/valid, the receiving version is still current and both banks are eligible, then lock the winning offer. An offer with less than 60 minutes of validity left cannot be proposed or approved, so the borrower has time to pay the fee.

Only one deal may be created here. Snapshot the selected terms, the lender's approved settlement-instruction version and the borrower's approved receiving version. If the demo payment adapter is configured, the fee quote/deadline/policy are frozen in the same transaction. If it is not configured, payment and disclosure are blocked; even if quoted later, the window is calculated from the original selection time. The lender cannot withdraw/edit the selected offer on their own. Close the other offers as Unsuccessful.

Currently the comparison payload contains only a random token, request-local label, rate, collateral, interest-payment convention and validity. Selection proposals/approvals and cancellation proposals are serialized under the request lock. A withdrawal or request expiry supersedes a pending selection. Once a selection is approved, the other offers are closed, the request becomes Selected and the deal becomes AwaitingFee. The borrower still does not see lender identity/instructions.

## 5. Platform fee and identity unlock

The deal is created as `AwaitingFee`. The Borrower Approver can initiate fee payment but cannot mark the payment successful themselves. Show the fee amount, currency and deadline before payment is initiated.

The fee deadline cannot exceed the selected offer's validity. Make it clear to lenders at submission time that the selected terms are reserved until that deadline.

The payment handler uses a trusted provider confirmation or an independently verified payment process to check the amount, currency, payment reference and deal association. A browser success page, an uploaded receipt or a `paid=true` request is not payment proof.

Do verification and unlock atomically. In that transaction, recheck that the deal is still AwaitingFee, the deadline has not expired and both banks are eligible. Duplicate/replayed notifications must not cause a second unlock, fee allocation or notifications. A fee record cannot be used for another deal.

After the fee is verified, show the selected lender's identity, approved contact details and settlement instructions, together with the borrower's receiving instructions, only to the relevant bank users. Keep the identities of non-selected lenders hidden.

**Currently implemented demo flow:** 10 bps (0.1%) of principal is charged in the loan currency; it is rounded up to the smallest currency unit and simulated tax is kept at 0. The fee deadline is whichever comes first: 24 hours from selection or the offer expiry. The user approved these defaults for the demo; the real provider/pricing is still pending. The adapter only works when explicitly enabled in local/testing.

When the Borrower Approver initiates a payment attempt, the server sets the amount/currency from the frozen quote and generates a random payment reference. Requesting the same pending attempt again returns the same record. A failed attempt can be retried within the deadline, but a new payment cannot be initiated while a refund review is outstanding.

The demo callback checks the timestamp and the HMAC signature of the raw JSON body. Delivery replays are detected by event ID and payload digest; there is only one canonical receipt per provider charge reference. A charge cannot be allocated to another payment/deal. Verifying the correct receipt and unlocking the deal happen in the same transaction. There is no endpoint that accepts fake client fields or ordinary status updates.

The deal terms view stays identity-free even after unlock. **View settlement details** is a separate authorized treasury view that provides only the selected lender snapshot and the borrower's receiving snapshot; every read is audited and served as no-store. Both banks' eligibility, current membership/role and verified payment proof are checked on every read. See the [project README](../README.md#demo-fee-payment-and-disclosure) for setup/simulation commands.

A failed notification does not unlock. A paid receipt with a wrong amount/currency/deal, unmatched reference, late receipt, suspended bank or stale instructions goes to RefundReview. Receiving the same delivery again does not cause a duplicate unlock; a new charge creates a separate refund-review receipt. Operations can view the receipt with a reason and record an externally handled refund reference; the app does not make the refund transfer. Refunding an additional receipt does not change the original verified proof.

## 6. Off-platform settlement

This part was implemented in Phase 5. Confirmations are institution attestations only; there is no actual transfer execution/independent verification.

The two banks settle through their own official banking process using the instructions received from the platform: the lender sends principal to the borrower's receiving account. The MVP does not provide RTGS/SWIFT integration or transfer execution.

Each bank's Maker proposes the settlement confirmation and a different Approver at the same bank approves it. Record the bank, user, time and optional settlement reference. These are bank attestations, not independent verification of the transfer by the platform.

When only one bank has confirmed, the deal stays Unlocked and shows that the other confirmation is pending. Mark it `Settled` only when both banks have confirmed.

The Maker provides the UTC settlement time, an optional reference and a note. The time must be between fee verification and the current time. A different same-bank Approver approves/rejects the immutable attestation; a rejection reason is required. References/notes/review reasons are encrypted and visible only to the bank's own treasury staff. At approval time, it is rechecked that the proposing Maker is still active/eligible, both banks' capabilities and approved instructions are current, and the payment proof is valid.

In the normal flow, the second bank's approval marks both the deal and the request Settled in one transaction. Attestations can be collected for a disputed deal, but automatic settlement is blocked. Operations reviews the evidence with a reason and records a Settled resolution if both approved confirmations and current eligibility exist. Otherwise it can mark the deal ClosedUnsettled with a reason; the request becomes Cancelled and pending attestations and instruction reviews are superseded. A pending instruction review blocks a Settled resolution but not this closure, so an ineligible bank cannot hold a dispute open. There are no resolution replays or arbitrary status overrides.

## Simple state rules

Keep Request, Offer, Deal and Payment statuses separate. Do not give the client an arbitrary `PATCH status` endpoint; provide authorized actions such as publish/select/confirm.

| Record | Normal flow | Other outcomes |
|---|---|---|
| Request | Draft → PendingApproval → Open → Selected → Settled | Approval reject → Draft; deadline → Expired; allowed cancellation → Cancelled |
| Offer | Draft → PendingApproval → Active → Selected | Approval reject → Draft; Withdrawn, Expired or Unsuccessful |
| Deal | AwaitingFee → Unlocked → Settled | Fee deadline → Cancelled; settlement issue → Disputed → Settled or ClosedUnsettled |
| FeePayment | Pending → Verified | Failed, RefundReview, Refunded |

The Request/Offer/Deal/FeePayment states above are currently implemented. SettlementConfirmation goes Pending → Approved or Rejected; it can become Superseded after an instruction correction/terminal closure. DealDispute goes Open → Settled or ClosedUnsettled. `deals:expire-fees` closes expired reservations every minute; payment guards enforce the deadline immediately.

A request can be selected from `Open` only before the bidding deadline. If there is no winner by the deadline, mark the request Expired and close the active offers. Once a selection is made, stop new bids.

## Failure and dispute rules

- **Fee not paid:** At the deadline, mark the deal/request Cancelled; do not reveal identities. There is no auto-reopen in the MVP; the borrower prepares a new request.
- **Late payment / suspended bank:** Do not unlock; send the payment to RefundReview/operations review. Do not revive a cancelled deal through a retry.
- **Cancellation before selection:** Cancel the request through an approved borrower action and close the offers.
- **Settlement fails after unlock:** The relevant bank's Approver can open a dispute. Operations records a resolution with evidence. The platform cannot reverse the transfer.
- **Dispute resolution:** If both parties' approved confirmations exist and both banks and their instructions are still current, mark it Settled; otherwise operations records a ClosedUnsettled outcome with a reason. The request is then closed as Cancelled. An admin cannot fabricate a successful settlement alone.
- **Refund:** Manual refund-reference attestation for exception receipts is implemented in the demo. The live commercial policy and actual refund execution are still pending. A refund cannot erase or re-hide an identity that has already been revealed.
- **Changing the settlement account:** While the fee is pending, a Maker at either bank proposes a separate approved version of its own instructions and a different Approver at that bank approves the deal change. A deal selected before borrower instructions were required can add the missing borrower version while unlocked; it cannot be paid or settled until it does. The change is blocked if there is a pending payment or refund review; while a change is pending, payment/settlement confirmation and settlement-details disclosure are blocked. An approved change keeps the old encrypted snapshot for history and gives the other bank an identity-free notice; the fee quote/deadline and selected financial terms do not change. After unlock, changes require an open dispute and independent approval by the same bank. Once the corrected version is approved, previous confirmations are superseded; two fresh attestations must be obtained and an operations resolution made. Settled/ClosedUnsettled records cannot be overwritten.

## Notifications and timeline

A pending attestation goes as an in-app notification to the bank's other Approvers, a rejection to the bank's own Makers, and outcomes to eligible staff at both banks. Selection, fee verification and reservation closure also notify eligible staff at both banks. Stored payloads and reads are limited by current bank/roles/deal relationship. Fixed messages contain no lender names, contacts, accounts, actors, references or narratives. Email/exports do not exist yet.

The Home page is the starting point for every role. Its work queue lists what needs the signed-in person and what is waiting on someone else, so an approver finds pending approvals without being told about them. Each request, offer and deal shows its progress and one next step for the current person.

The deal timeline provides selection/fee milestones and paginated settlement events. Events are append-only; privileged dispute evidence and private institution references are in separate authorized views. The timeline cannot bypass the payment/disclosure gate.

## Minimum data records

| Record | Purpose |
|---|---|
| Bank, User, BankMembership | Verified organization, capability and staff permissions |
| Document | Private onboarding/request files, ownership and version |
| FundingRequest | Borrower, financial terms, documents and deadline |
| Offer | Lender, structured terms, approval and validity |
| Deal | Selected offer snapshot, fee deadline, disclosure and settlement state |
| FeePayment | Frozen expected amount/currency, server reference and payment-attempt outcome |
| PaymentReceipt, PaymentNotification | Canonical charge, exact received amount/currency, event digest, deduplication and refund-review evidence |
| DealInstructionChange | Independent pre-payment/disputed version approval for either bank and encrypted previous snapshot |
| DealDispute | Encrypted issue/resolution narratives, originating institution and operations outcome |
| DealEvent, database notifications | Immutable generic workflow history and role-scoped in-app alerts |
| Approval, SettlementConfirmation | Maker/checker evidence and each bank's confirmation |
| AuditEvent | Actor, bank, action, record, timestamp and necessary limited change metadata |

Do not copy raw financial documents, full settlement account details or credentials into audit logs. The borrower-facing audit projection must also follow the anonymity rules.

The implementation database is PostgreSQL (local 16.14). SQLite is used only for fast tests; the earlier local source snapshot is kept unchanged. Selection/payment/settlement races have been verified using separate PostgreSQL sessions. Run/recovery evidence and live-only pending tasks are in the [delivery plan](04-delivery-plan.md).
