# 03 — Marketplace workflow

මේ flow එක [access-control rules](02-access-control.md) යටතේ ක්‍රියාත්මක විය යුතුයි. Loan principal හුවමාරු වන්නේ platform එකෙන් පිටතයි.

## 1. Bank onboarding

Platform Operations විසින් bank profile එක verify කර Borrower/Lender capabilities ලබාදෙනවා. Bank Admin staff invite කර roles සකස් කරනවා. Pending bank එකකට marketplace access ලැබෙන්නේ නැහැ.

Lender Maker විසින් contact සහ settlement instructions සකස් කර වෙනත් Lender Approver කෙනෙක් approve කරන්න. Active offer එකක් submit කිරීමට පෙර approved instruction version එකක් තිබිය යුතුයි; Bank Admin-only user කෙනෙකුට මේ approval එක කළ නොහැක.

## 2. Request සකස් කර publish කිරීම

Borrower Maker amount, currency, tenor, expected settlement date, bid deadline සහ financial documents ඇතුළත් කරනවා. Request එක published වන විට borrower identity lenders ට පැහැදිලිව පෙනෙනවා.

වෙනත් Borrower Approver කෙනෙක් request එක approve කළ පසු පමණක් publish කරන්න. Published financial terms සහ document set එක freeze කරන්න. Material වෙනසක් අවශ්‍ය නම් request එක cancel කර අලුත් request එකක් publish කරන්න; bids ලැබුණු request එකේ terms නිහඬව වෙනස් නොකරන්න.

**දැනට implement කර ඇති flow එක:** Maker draft save කරනවා → අවම වශයෙන් එක් private financial PDF එකක් upload කරනවා → publication proposal submit කරනවා → request එකට contribute නොකළ Approver කෙනෙක් approve/reject කරනවා. Terms හෝ files වෙනස් කළොත් pending proposal එක supersede කර Draft කර නැවත submit කළ යුතුයි. Proposal එකේ bank identity වෙනස් වුණත් fresh approval අවශ්‍යයි. Publication වෙලාවේ borrower identity/background snapshot කර terms/documents freeze කරනවා. Reject කළොත් reason එක සමඟ Draft වෙත යනවා.

Draft/Open request cancellation එකත් Maker proposal + වෙනත් Approver review එකක්. Cancellation review pending අතර draft edit අවහිර වෙනවා; open request එක approve වන තුරු open ලෙසම තිබෙනවා. Approve කළොත් Cancelled; reject කළොත් කලින් state එකම පවතිනවා. Expired request එකක් publish/revive කරන්න බැහැ.

Tenor එක මාසවලට පමණක් සීමා නොකරන්න. Amount/rates exact decimals ලෙස තබන්න. Currency, annual rate basis සහ day-count convention පැහැදිලිව පෙන්වන්න; legal loan interest calculation/servicing MVP එකෙන් පිටතයි.

Local request defaults: LKR/USD/GBP, amount decimal places 2, tenor දින 1–3,650, ACT/360 හෝ ACT/365F, annualized simple rate basis. Amount API එකේ decimal string එකක් ලෙස යවන්න. Bidding deadline UTC අනුව අනාගත වෙලාවක් සහ settlement date එකට පෙර හෝ එදින විය යුතුයි. Financial PDF එකකට උපරිම 10 MB.

Verified lender Maker/Approver users ට `/marketplace` හරහා eligible borrower banks වල open, unexpired requests පමණක් පෙනෙනවා. Drafts, approval history සහ onboarding evidence පෙන්වන්නේ නැහැ. Deadline එක පසු වූ විට guards වහාම access නවත්වනවා; සෑම විනාඩියකම scheduled `funding:expire` command එක persisted status සහ pending approvals close කර system audit event එකක් ලියනවා. Local scheduler run කරන හැටි [project README](../README.md) එකේ තිබෙනවා.

## 3. Borrower review සහ offers

Verified Lender Maker ට published borrower profile සහ අදාළ documents බලන්න පුළුවන්. Maker විසින් request amount/currency/tenor එකට ගැළපෙන offer එකක් සකස් කර rate, permitted structured conditions සහ validity deadline ලබාදෙනවා.

වෙනත් Lender Approver කෙනෙක් approve කළ පසු offer එක Active වෙනවා. එක් lender bank එකකට එක් request එකේ එක් active offer එකක් පමණක් තිබිය හැකියි. Selected වීමට පෙර offer එක withdraw කළ හැකියි. Terms වෙනස් කිරීමට old offer එක withdraw කර replacement එක නැවත approve කරන්න; old version එක audit history එකේ තබන්න.

Pre-unlock borrower view එකට arbitrary lender text, attachments, signatures හෝ contact fields නොදෙන්න. MVP comparison එක structured fields වලට සීමා කරන්න; lender internal notes borrower ට පෙන්වන්න එපා.

අදියර 3 implementation එකේ settlement instructions වෙනම immutable versions ලෙස propose/review කරනවා. Account/contact details database එකේ encrypt කර තිබෙනවා. Bank profile වෙනස් වුණොත් පැරණි version එක භාවිතා කර අලුත් offer activate/select කරන්න බැහැ; fresh approved version එකක් අවශ්‍යයි. Existing selected instruction snapshot එක නව version එකකින් overwrite වෙන්නේ නැහැ.

Offer defaults: rate decimal string එකක් ලෙස 0–100 percent, decimal places 4; collateral unsecured/sovereign bonds; interest payment at maturity/monthly. Offer validity එක bid deadline එක cover කර settlement date එකෙන් පසුව නොවිය යුතුයි. Draft/PendingApproval edits පැරණි approval එක supersede කරනවා. Active offer එක immutable; replacement එකකට withdraw + fresh approval අවශ්‍යයි. `offers:expire` command එක සෑම විනාඩියකම unselected validity-expired offers close කරනවා.

## 4. Blind comparison සහ selection

Borrower ට තම request එකේ active offers `Offer 1`, `Offer 2` ලෙස terms සමඟ පෙනෙනවා. Label එක request එක තුළට පමණක් සීමා විය යුතුයි. Lender bank ID හෝ stable cross-request identifier එකක් එයට භාවිතා නොකරන්න.

Borrower Maker selection එක යෝජනා කරනවා. වෙනත් Borrower Approver කෙනෙක් approve කරන අවස්ථාවේ transaction එකක් තුළ request එක තවම Open ද, offer එක Active/valid ද, banks දෙකම eligible ද යන්න නැවත පරීක්ෂා කර winning offer එක lock කරන්න.

මෙහිදී එක deal එකක් පමණක් හැදිය යුතුයි. Selected terms සහ අනුමත settlement-instruction version එක snapshot කරන්න. Demo payment adapter එක configure කර ඇත්නම් ඒ transaction එකේම fee quote/deadline/policy freeze වෙනවා. Configure කර නැත්නම් payment සහ disclosure අවහිර වේ; පසුව quote කළත් window එක original selection time එකෙන් ගණනය කරනවා. තෝරාගත් offer එක lender ට තනිව withdraw/edit කළ නොහැක. අනෙක් offers Unsuccessful ලෙස close කරන්න.

දැනට comparison payload එකේ random token, request-local label, rate, collateral, interest-payment convention සහ validity පමණක් තිබෙනවා. Selection proposal/approval සහ cancellation proposals request lock එක යටතේ serialize කරනවා. Withdrawal හෝ request expiry එකකින් pending selection supersede වෙනවා. Selection approve වුණාම වෙනත් offers close කර request එක Selected සහ deal එක AwaitingFee කරනවා. Borrowerට lender identity/instructions තවම පෙන්වන්නේ නැහැ.

## 5. Platform fee සහ identity unlock

Deal එක `AwaitingFee` ලෙස හැදෙනවා. Borrower Approver ට fee payment ආරම්භ කළ හැකි නමුත් payment සාර්ථක බව ඔහුටම mark කළ නොහැක. Fee amount, currency සහ deadline එක payment ආරම්භ කිරීමට පෙර පෙන්වන්න.

Fee deadline එක selected offer validity එක ඉක්මවිය නොහැක. ඒ deadline දක්වා selected terms reserve වන බව lenders ට submission වෙලාවේ පැහැදිලි කරන්න.

Payment handler එක trusted provider confirmation එක හෝ independently verified payment process එක භාවිතා කර amount, currency, payment reference සහ deal association පරීක්ෂා කරනවා. Browser success page, uploaded receipt හෝ `paid=true` request එකක් payment proof එකක් නොවේ.

Verification සහ unlock එක atomic ලෙස කරන්න. එම transaction එකේ deal එක තවම AwaitingFee ද, deadline ඉකුත් වී නැද්ද සහ banks දෙකම eligible ද යන්න නැවත පරීක්ෂා කරන්න. Duplicate/replayed notifications වලින් දෙවරක් unlock, fee allocation හෝ notifications නොවිය යුතුයි. Fee record එක වෙනත් deal එකකට භාවිතා කළ නොහැක.

Fee verify වූ පසු selected lender identity, approved contact details සහ settlement instructions අදාළ bank users ට පමණක් පෙන්වන්න. Non-selected lenders ගේ identities hidden ලෙසම තබන්න.

**දැනට implement කර ඇති demo flow එක:** Principal එකෙන් 10 bps (0.1%) loan currency එකෙන් අය කරනවා; smallest currency unit දක්වා round up කර simulated tax 0 ලෙස තබනවා. Selection සිට පැය 24 හෝ offer expiry යන දෙකෙන් මුලින් එන වෙලාව fee deadline වේ. මේ defaults user විසින් demo සඳහා අනුමත කළා; real provider/pricing තවම pending. Adapter එක local/testing තුළ explicit enable කළ විට පමණක් ක්‍රියාත්මක වේ.

Borrower Approver payment attempt එකක් ආරම්භ කළාම server එක frozen quote එකෙන් amount/currency සහ random payment reference එකක් සකස් කරනවා. එම pending attempt එක නැවත ඉල්ලුවොත් එකම record එක ලැබෙනවා. අසාර්ථක attempt එකකට deadline තුළ නැවත උත්සාහ කළ හැකි නමුත් refund review outstanding නම් අලුත් payment ආරම්භ කළ නොහැක.

Demo callback එක timestamp සහ raw JSON body එකේ HMAC signature පරීක්ෂා කරනවා. Event ID සහ payload digest මඟින් delivery replay හඳුනාගන්නවා; provider charge reference එකකට එක් canonical receipt එකක් පමණයි. එක charge එක වෙනත් payment/deal එකකට allocate කළ නොහැක. Correct receipt එක verify කිරීම සහ deal unlock කිරීම එකම transaction එකෙන් වෙනවා. Fake client fields හෝ ordinary status updates ලබාගන්න endpoint එකක් නැහැ.

Deal terms view එක unlock පසුවත් identity-free ලෙස පවතිනවා. **View settlement details** වෙනම authorized treasury view එකෙන් selected lender snapshot එක පමණක් ලබාදෙනවා; සෑම read එකක්ම audit කර no-store ලෙස serve කරනවා. Banks දෙකේ eligibility, current membership/role සහ verified payment proof read කරන සෑම විටම පරීක්ෂා කරනවා. Setup/simulation commands සඳහා [project README](../README.md#demo-fee-payment-and-disclosure) බලන්න.

Failed notification එකෙන් unlock නොවේ. Wrong amount/currency/deal, unmatched reference, late receipt, suspended bank හෝ stale instructions තිබෙන paid receipt එක RefundReview වෙත යනවා. Same delivery එක නැවත ලැබීමෙන් duplicate unlock නොවේ; අලුත් charge එකක් නම් වෙනම refund-review receipt එකක් සෑදෙනවා. Operations reason එකක් සමඟ receipt බලලා externally handled refund reference එක record කළ හැකියි; app එකෙන් refund transfer කරන්නේ නැහැ. Additional receipt එක refund කළත් original verified proof එක වෙනස් නොවේ.

## 6. Off-platform settlement

මේ කොටස අදියර 5 තුළ implement කර තිබෙනවා. Confirmations යනු institution attestations පමණයි; actual transfer execution/independent verification නැහැ.

බැංකු දෙක platform එකෙන් ලැබුණු instructions මත තම නිල banking process එකෙන් settlement කරනවා. MVP එක RTGS/SWIFT integration එකක් හෝ transfer execution එකක් සපයන්නේ නැහැ.

එක් එක් බැංකුවේ Maker විසින් settlement confirmation එක යෝජනා කර තම බැංකුවේ වෙනත් Approver කෙනෙක් approve කරනවා. Bank, user, time සහ optional settlement reference එක record කරන්න. මේවා bank attestations මිස platform එකෙන් transfer independently verify කිරීමක් නොවේ.

එක් බැංකුවක් පමණක් confirm කළ විට deal එක Unlocked ලෙසම පවතින අතර අනෙක් confirmation එක pending බව පෙන්වන්න. බැංකු දෙකම confirm කළාම පමණක් `Settled` කරන්න.

Maker විසින් UTC settlement time, optional reference සහ note සපයනවා. Time එක fee verification සහ current time අතර විය යුතුයි. වෙනත් same-bank Approver කෙනෙක් immutable attestation එක approve/reject කරනවා; reject reason අවශ්‍යයි. References/notes/review reasons encrypted සහ own treasury staff ට පමණක් පෙනේ. Approve වෙලාවේ proposing Maker තවම active/eligible ද, banks දෙකේ capabilities සහ approved instructions current ද, payment proof වලංගු ද නැවත පරීක්ෂා කරනවා.

Normal flow එකේ second bank approval එක deal සහ request දෙකම එක transaction එකේ Settled කරනවා. Disputed deal එකක attestations collect කළ හැකි නමුත් automatic settlement අවහිරයි. Operations reason එකක් සමඟ evidence review කර approved confirmations දෙක සහ current eligibility තිබේ නම් Settled resolution එකක් record කරනවා. නැත්නම් reason එකක් සමඟ ClosedUnsettled කළ හැකියි; request Cancelled වී pending attestations supersede වෙනවා. Resolution replay සහ arbitrary status overrides නැහැ.

## සරල state rules

Request, Offer, Deal සහ Payment statuses වෙන වෙනම තබන්න. Client එකට arbitrary `PATCH status` endpoint එකක් නොදී publish/select/confirm වැනි authorized actions ලබාදෙන්න.

| Record | සාමාන්‍ය flow | වෙනත් outcomes |
|---|---|---|
| Request | Draft → PendingApproval → Open → Selected → Settled | Approval reject → Draft; deadline → Expired; allowed cancellation → Cancelled |
| Offer | Draft → PendingApproval → Active → Selected | Approval reject → Draft; Withdrawn, Expired හෝ Unsuccessful |
| Deal | AwaitingFee → Unlocked → Settled | Fee deadline → Cancelled; settlement issue → Disputed → Settled හෝ ClosedUnsettled |
| FeePayment | Pending → Verified | Failed, RefundReview, Refunded |

දැනට ඉහත Request/Offer/Deal/FeePayment states implement කර තිබෙනවා. SettlementConfirmation Pending → Approved හෝ Rejected; instruction correction/terminal closure පසු Superseded විය හැකියි. DealDispute Open → Settled හෝ ClosedUnsettled. `deals:expire-fees` සෑම විනාඩියකම expired reservations close කරනවා; payment guards deadline එක වහාම enforce කරනවා.

Request `Open` සිට select කළ හැක්කේ bidding deadline එකට පෙර පමණයි. Deadline එකට winner කෙනෙක් නැත්නම් request එක Expired කර active offers close කරන්න. Selection වුණොත් අලුත් bids නවත්වන්න.

## Failure සහ dispute rules

- **Fee නොගෙවීම:** Deadline එකට deal/request Cancelled කරන්න; identities reveal නොකරන්න. MVP එකේ auto-reopen නැහැ; borrower අලුත් request එකක් සකස් කරනවා.
- **Late payment / suspended bank:** Unlock නොකර payment එක RefundReview/operations review වෙත යවන්න. Retry එකක් හරහා cancelled deal එක revive කරන්න එපා.
- **Selected වීමට පෙර cancellation:** අනුමත borrower action එකකින් request එක cancel කර offers close කරන්න.
- **Unlocked පසු settlement fail වීම:** අදාළ bank Approver ට dispute එකක් open කළ හැකියි. Operations විසින් evidence සමඟ resolution record කරන්න. Transfer එක platform එකෙන් reverse කරන්න බැහැ.
- **Dispute resolution:** දෙපාර්ශ්වයේ approved confirmations තිබේ නම් Settled කරන්න; නැත්නම් operations reason එකක් සමඟ ClosedUnsettled outcome එක record කරන්න. එවිට request එක Cancelled ලෙස close කරන්න. Admin කෙනෙකුට තනිව settlement successful බව fabricate කළ නොහැක.
- **Refund:** Demo එකේ exception receipts සඳහා manual refund-reference attestation implement කර තිබෙනවා. Live commercial policy සහ actual refund execution තවම pending. Refund කළාම දැනටමත් හෙළි කළ identity මකා හෝ නැවත රහස් කළ නොහැක.
- **Settlement account වෙනස් කිරීම:** Fee pending විට lender Maker වෙනම approved instruction version එකක් යෝජනා කර වෙනත් lender Approver කෙනෙක් deal change එක approve කරනවා. Pending payment හෝ refund review තිබේ නම් change block වේ; pending change අතර payment/settlement confirmation සහ settlement-details disclosure block වේ. Approved change එකෙන් old encrypted snapshot history සඳහා තබා borrower ට identity-free notice එකක් දෙනවා; fee quote/deadline සහ selected financial terms වෙනස් නොවේ. Unlock පසු changes සඳහා open dispute එකක් සහ independent lender approval අවශ්‍යයි. Corrected version approve කළාම previous confirmations supersede වෙනවා; fresh attestations දෙක ලබාගෙන operations resolution එකක් කළ යුතුයි. Settled/ClosedUnsettled records overwrite කළ නොහැක.

## Notifications සහ timeline

Pending attestation එක own-bank වෙනත් Approvers ටත්, rejection එක own-bank Makers ටත්, outcomes banks දෙකේ eligible staff ටත් in-app notification ලෙස ලැබෙනවා. Stored payloads සහ reads current bank/roles/deal relationship අනුව සීමා වේ. Fixed messages වල lender names, contacts, accounts, actors, references හෝ narratives නැහැ. Email/exports තවම නැහැ.

Deal timeline එක selection/fee milestones සහ paginated settlement events සපයනවා. Events append-only; privileged dispute evidence සහ private institution references වෙනම authorized views වල තිබෙනවා. Timeline එකෙන් payment/disclosure gate bypass කළ නොහැක.

## අවම data records

| Record | අරමුණ |
|---|---|
| Bank, User, BankMembership | Verified organization, capability සහ staff permissions |
| Document | Private onboarding/request files, ownership සහ version |
| FundingRequest | Borrower, financial terms, documents සහ deadline |
| Offer | Lender, structured terms, approval සහ validity |
| Deal | Selected offer snapshot, fee deadline, disclosure සහ settlement state |
| FeePayment | Frozen expected amount/currency, server reference සහ payment-attempt outcome |
| PaymentReceipt, PaymentNotification | Canonical charge, exact received amount/currency, event digest, deduplication සහ refund-review evidence |
| DealInstructionChange | Independent pre-payment/disputed version approval සහ encrypted previous snapshot |
| DealDispute | Encrypted issue/resolution narratives, originating institution සහ operations outcome |
| DealEvent, database notifications | Immutable generic workflow history සහ role-scoped in-app alerts |
| Approval, SettlementConfirmation | Maker/checker evidence සහ එක් එක් bank confirmation |
| AuditEvent | Actor, bank, action, record, timestamp සහ අවශ්‍ය සීමිත change metadata |

Audit logs වල raw financial documents, full settlement account details හෝ credentials copy නොකරන්න. Borrower-facing audit projection එකත් anonymity rules වලට යටත් විය යුතුයි.

Implementation database එක PostgreSQL (local 16.14) යි. SQLite fast tests සඳහා පමණක් භාවිතා කරනවා; පෙර local source snapshot එක unchanged ලෙස රැකෙනවා. Separate PostgreSQL sessions මඟින් selection/payment/settlement races verify කර තිබෙනවා. Run/recovery evidence සහ live-only pending tasks [delivery plan](04-delivery-plan.md) එකේ තිබෙනවා.
