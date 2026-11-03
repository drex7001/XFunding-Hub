# 02 — Access control

**Language:** English · [Singlish (සිංහල)](02-access-control.si.md)

## Core rule

On every action, check **authenticated user + active membership + bank verification/capability + permission + record relationship + workflow state**. Drop a part only for actions where that check does not apply; for example, a pending bank can complete its onboarding profile.

The default must be deny. Do not trust a bank ID from the request body; take it from the authenticated membership. Changing a URL/record ID must not give access to another bank's private data.

## Roles

| Role | Can do | Limit |
|---|---|---|
| Platform Admin | Bank verification/suspension, platform account administration, audited operational reviews | Cannot bid/select/settle on behalf of a bank; cannot bypass the fee gate |
| Bank Admin | Own bank's profile, invitations, membership and role administration | This role alone cannot give treasury approvals or unlock payment |
| Treasury Maker | Draft requests/offers according to the bank's capability; propose selection/confirmation | Cannot approve an action they prepared |
| Treasury Approver | Approve/reject treasury actions proposed by another user at the same bank; initiate fee payment | Cannot approve another bank's action or their own action |
| Bank Auditor | Read records and audit history the bank is permitted to see | No write access; not allowed to see settlement account/contact fields |

The Bank Admin role is not a treasury permission. Those permissions apply only if another treasury role is also granted. Even when a user holds several roles, the maker–checker check must be done by user ID.

A role grant that raises privilege to Approver or Bank Admin cannot be self-approved. Another authorized Bank Admin or a Platform Admin must approve and audit it. Set up the first Bank Admin account through platform onboarding.

In the first implementation, invitations and all role changes go through the same independent approval flow. Neither the user who proposed the request nor the user who benefits from the role change can approve it. When membership/roles change, old sessions must sign in again. The last active Bank Admin cannot be removed.

## Data visibility

| Information | Who can see it? |
|---|---|
| Bank onboarding and unpublished drafts | Authorized staff of that bank; limited platform staff needed for verification |
| Published request, borrower identity and attached financial documents | Relevant verified lender banks and the owning borrower bank |
| Lender offer terms | Owning lender bank staff; an anonymous projection for the request's borrower |
| Settlement instruction versions | Only Maker/Approver users of that verified bank, which needs the Borrower or Lender capability; not Admin-only/Auditor-only/platform users |
| Other lenders' offers | Not visible to lenders |
| Selected lender identity | Hidden from the borrower until the fee is verified; afterwards only for that deal's authorized treasury Maker/Approver users |
| Contact and settlement instructions | The lender's version and the borrower's receiving version: only for a verified fee and an unlocked deal, to treasury staff of the two banks involved |
| Non-selected lender identities | Never disclose to the borrower |

A Bank Admin cannot directly publish documents or unlock a lender identity. Exclude settlement/contact fields for Bank Auditor and Bank Admin-only users.

In the Phase 2 implementation, all four staff roles can read their own verified bank's funding records. Create/edit/upload/submit/cancellation proposal requires the Borrower capability + Maker role. Publication/cancellation review requires the Borrower capability + Approver role. Only Maker/Approver users with the verified Lender capability can browse published requests and financial documents from another bank; Admin-only and Auditor-only accounts have no marketplace access. Lender reads/downloads are blocked as soon as borrower eligibility is lost.

Platform staff have no default access to funding documents. Onboarding PDFs and request financial PDFs are kept under separate ownership/policies. A lender's document download is recorded in the lender's own audit history, so the owning borrower's history does not show that activity.

Do not let a platform-wide admin role always read sensitive documents/accounts. Show only the onboarding records relevant to verification. Authorize the privileged read needed for payment/dispute review separately, with a reason and an audit event; it must not unlock borrower access.

In the Phase 3 implementation, offer draft/edit/submit/withdraw requires the Lender Maker role, and independent offer review requires the Lender Approver role. Offer contributors cannot approve publication. Borrower staff have no access to lender internal offer routes; the comparison only receives a random request-local token, label, rate, structured conditions and validity.

A Borrower Maker proposes the selection and a different Borrower Approver approves/rejects it. Admin/Auditor users can read own-bank comparison/deal terms but cannot perform treasury actions. Selected lender treasury staff can keep reading the borrower's financial documents; losing lenders lose request/document access. No borrower role sees lender identity/account/contact fields in the pre-fee deal view. Even a privileged platform role cannot bypass the treasury/fee gate.

In the Phase 4 implementation, only an owning verified Borrower Approver can initiate payment. Amount, currency, reference and paid/unlocked status cannot be supplied by the client. The payment adapter rechecks the amount/currency/reference/deal and current eligibility in the authenticated provider notification before unlocking; a browser success or an operations action is not payment proof.

Even after unlock, ordinary deal API/HTML responses do not include lender identity/account/contact fields. A separate disclosure endpoint checks deal participation, Maker/Approver role, verification/capabilities of both banks, the approved instruction snapshot and a verified receipt for that specific deal. Every disclosure read is audited and returns a private no-store response. Admin-only, Auditor-only and platform users have no bypass; reads stop immediately after suspension/role revocation.

Platform Operations can read the payment exception list and, with a reason, audited receipt details. Recording a refund reference and review reason is not a transfer execution; that route cannot mark anything paid/unlocked. A Maker at either bank proposes a pre-payment change to its own instructions and a different own-bank Approver reviews it; the other bank only receives an identity-free notice.

In the Phase 5 implementation, a verified participating bank's Maker proposes a settlement attestation and a different same-bank Approver reviews it. Another confirmation cannot be proposed for a pending/approved record. At approval time, the payment proof, both banks, the approved instruction version and the proposing maker's active membership/role are rechecked. The reference, note and review reason are stored encrypted and provided only to the owning bank's treasury users; the other party and Admin/Auditor-only users see status only.

An owning bank Approver can mark an unlocked deal as Disputed; they can report an issue if their own bank is verified, even if the other bank is suspended. Platform Operations can only do reason-audited dispute evidence review and resolution. It cannot mark a deal Settled without two approved confirmations, or create a bank confirmation. If an independently approved instruction correction is made during a dispute, earlier confirmations are superseded and fresh approvals are required from both parties.

In-app notifications are filtered by recipient + current bank + current role + deal participation. A read acknowledgement cannot be made using another user's notification ID. Notifications and the paginated deal timeline only provide fixed status text; they do not include accounts, contacts, actor identities, references or private narratives.

## Bank and workflow limits

- An unverified or suspended bank cannot browse the marketplace, publish, bid, select, pay or confirm. An unverified bank can only use its own onboarding process.
- Recheck both parties' eligibility at selection and unlock time as well. If the selected bank is suspended, review the payment/unlock exception.
- Even a bank with both Borrower/Lender capabilities cannot bid on its own request.
- If terms sent for approval change, invalidate the earlier approval and approve again.
- Publication approval cannot be given by the proposer or by any other contributor who created/edited the request or uploaded/removed documents. This limit is enforced by user ID, even if the user has several roles.
- If a pending publication's terms/files change, supersede the approval and return it to Draft. Also recheck the proposal's borrower identity snapshot at approval time.
- Freeze the published request's borrower profile, financial terms and documents. Cancellation requires a different approver and a reason.
- After membership is revoked, access must be rechecked for sessions, downloads and all other reads/writes.

## Enforcement

- If using Laravel, use Policies + bank-scoped queries + explicit response resources. Do not use frontend visibility as authorization.
- Keep financial documents in private storage. Serve them through a download route that checks authorization; do not give out public permanent URLs.
- Do not include lender IDs, bank names, emails, actor names or internal relationships in borrower-facing payloads. Limit the anonymous label to within the request.
- Apply the same access rules to notifications, exports, search, cached responses and background jobs. Identity must not leak through audit history either.
- Fee verification is an action of the verified payment handler. Nothing can be marked `paid` or `unlocked` through an ordinary user/admin status update.
- Include login rate limits and session revocation in the foundation. Set up staff MFA for a real-bank launch.

## Acceptance checks

- [x] An anonymous user has no access to the marketplace/private documents.
- [x] A Bank A user cannot find a Bank B funding draft by ID.
- [x] A verified lender treasury user sees only the open request's borrower profile/documents; private approvals/storage paths are not visible.
- [x] Publication self-approval, stale/replayed approvals and changes to published terms/documents are rejected.
- [x] Requests past their deadline are removed from lender lists/details/downloads immediately; rerunning the expiry job does not create duplicate events.
- [x] A Bank A user cannot find a Bank B private deal by ID.
- [x] A lender cannot see competitor bids.
- [x] Borrower comparison/deal API, HTML and audit views do not reveal pre-fee lender identity/instructions.
- [x] Cross-request offer tokens, self-selection approval, stale/replayed decisions and withdrawn/expired/ineligible offer selection are rejected.
- [x] Database constraints and the request lock enforce uniqueness of the active lender offer, pending selection and winning deal.
- [x] Competing selection proposals/approvals, duplicate/extra payments and simultaneous settlement approvals in separate PostgreSQL backend sessions are limited to one winner/allocation/event.
- [x] In-app notifications and the deal timeline have no identity/reference/narrative leakage; current role and recipient isolation are enforced.
- [ ] Re-verify the anonymity rules when email notifications and exports are implemented.
- [x] After fee verification, only the selected lender is visible to the relevant treasury users through a separate audited disclosure view.
- [x] Unsigned/fake, wrong amount/currency/deal, replay-conflicting and late payments cannot unlock; there is no ordinary user/admin paid override.
- [x] Admin/Auditor/platform accounts, copied payment proof and revoked/suspended membership cannot bypass disclosure.
- [x] Fee exception details require a platform reason/audit; refund attestations and instruction changes enforce bank/role/state limits.
- [x] Confirmation self-approval, cross-bank review, duplicate/replayed approvals, stale instructions/members and forged fields are rejected.
- [x] One-bank approval does not mark Settled; operations cannot fabricate a successful settlement without evidence from both parties.
- [x] Disputed instruction corrections preserve old evidence and supersede previous confirmations; two fresh approvals are required.
- [x] Self-approval, self-promotion, arbitrary status updates and revoked membership access are rejected.
- [x] Suspension or a role change cannot be bypassed through a cached response/download.
- [x] The borrower's receiving instructions are hidden from the lender until the fee is verified, and from Admin-only, Auditor-only, unrelated and platform accounts afterwards.
- [x] Staff names in the audit history appear only within their own institution; private reasons are not shown.
- [x] An unrelated bank user holding all four roles is denied on every parameterised route.
- [x] The Home work queue uses the same policies as the actions, offers no self-approval and never names a lender to a borrower.
