# 02 — Access control

## මූලික නීතිය

හැම action එකකදීම **authenticated user + active membership + bank verification/capability + permission + record relationship + workflow state** පරීක්ෂා කරන්න. මේ පරීක්ෂණ අදාළ නැති action වලදී පමණක් ඒ කොටස ඉවත් කරන්න; උදාහරණයක් ලෙස pending bank එකකට onboarding profile සම්පූර්ණ කළ හැකියි.

Default එක deny විය යුතුයි. Bank ID එක request body එකෙන් විශ්වාස කර නොගෙන authenticated membership එකෙන් ගන්න. URL/record ID එක වෙනස් කිරීමෙන් වෙනත් බැංකුවක private data ලබාගත නොහැකි විය යුතුයි.

## Roles

| Role | කළ හැකි දේ | සීමාව |
|---|---|---|
| Platform Admin | Bank verification/suspension, platform account administration, audited operational reviews | Bank එකක් වෙනුවෙන් bid/select/settle කළ නොහැක; fee gate bypass කළ නොහැක |
| Bank Admin | තම බැංකුවේ profile, invitations, membership සහ role administration | මේ role එකෙන් පමණක් treasury approvals හෝ payment unlock කළ නොහැක |
| Treasury Maker | තම bank capability අනුව requests/offers draft කිරීම; selection/confirmation යෝජනා කිරීම | තමා සකස් කළ action එක approve කළ නොහැක |
| Treasury Approver | තම බැංකුවේ වෙනත් user කෙනෙක් යෝජනා කළ treasury actions approve/reject කිරීම; fee payment ආරම්භ කිරීම | වෙනත් බැංකුවක action එකක් හෝ තම action එකක් approve කළ නොහැක |
| Bank Auditor | තම බැංකුවට අවසර ඇති records සහ audit history කියවීම | Write access නැහැ; settlement account/contact fields බලන්න අවසර නැහැ |

Bank Admin role එක treasury permission එකක් නොවේ. වෙනත් treasury role එකක් ද ලබාදී ඇත්නම් පමණක් එම permissions ලැබේ. User කෙනෙකු roles කිහිපයක් දැරුවත් maker–checker පරීක්ෂාව user ID අනුව කළ යුතුයි.

Approver හෝ Bank Admin ලෙස privilege වැඩි කරන role grant එකක් තමාටම approve කළ නොහැක. වෙනත් authorized Bank Admin කෙනෙක් හෝ Platform Admin කෙනෙක් එය අනුමත කර audit කරන්න. මුල් Bank Admin account එක platform onboarding හරහා සකස් කරන්න.

පළමු implementation එකේ invitations සහ සියලු role changes එකම independent approval flow එකෙන් යනවා. Request එක යෝජනා කළ user ටත්, එම role change එකෙන් ප්‍රතිලාභ ලබන user ටත් එය approve කරන්න බැහැ. Membership/role වෙනස් කළ විට පැරණි sessions නැවත sign in කළ යුතුයි. අවසාන active Bank Admin ඉවත් කළ නොහැක.

## Data visibility

| තොරතුරු | කාට පෙනේද? |
|---|---|
| Bank onboarding සහ unpublished drafts | එම බැංකුවේ අවසර ඇති staff; verification සඳහා අවශ්‍ය සීමිත platform staff |
| Published request, borrower identity සහ attached financial documents | අදාළ verified lender banks සහ owning borrower bank |
| Lender offer එකේ terms | Owning lender bank staff; request එකේ borrower ට anonymous projection එකක් |
| Lender instruction versions | එම verified lender bank එකේ Maker/Approver users පමණයි; Admin-only/Auditor-only/platform users ට නැහැ |
| වෙනත් lenders ගේ offers | Lenders ට නොපෙනේ |
| Selected lender identity | Fee verify වන තුරු borrower ට නොපෙනේ; පසුව එම deal එකේ authorized treasury Maker/Approver users ට පමණයි |
| Contact සහ settlement instructions | Verified fee සහ unlocked deal එකකට පමණක්, අදාළ බැංකු දෙකේ treasury staff ට |
| Non-selected lender identities | Borrower ට කිසිම අවස්ථාවක හෙළි නොකරන්න |

Bank Admin කෙනෙකුට documents publish කිරීම හෝ lender identity unlock කිරීම සෘජුව කළ නොහැක. Bank Auditor සහ Bank Admin-only users සඳහා settlement/contact fields exclude කරන්න.

අදියර 2 implementation එකේ තම verified bank එකේ funding records staff roles හතරටම read කරන්න පුළුවන්. Create/edit/upload/submit/cancellation proposal සඳහා Borrower capability + Maker role අවශ්‍යයි. Publication/cancellation review සඳහා Borrower capability + Approver role අවශ්‍යයි. Published requests සහ financial documents වෙනත් bank එකකින් browse කළ හැක්කේ verified Lender capability ඇති Maker/Approver users ට පමණයි; Admin-only සහ Auditor-only accounts ට marketplace access නැහැ. Borrower eligibility නැති වූ වහාම lender reads/downloads අවහිර වෙනවා.

Funding documents platform staff ට default access නැහැ. Onboarding PDFs සහ request financial PDFs වෙනම ownership/policies යටතේ තබනවා. Document download audit event එකේ lender actor backend එකේ record කළත් owning borrower view එකේ එම lender identity පෙන්වන්නේ නැහැ.

Platform-wide admin role එකෙන් sensitive documents/accounts සෑම විටම කියවන්න ඉඩ නොදෙන්න. Verification සඳහා අදාළ onboarding records පමණක් පෙන්වන්න. Payment/dispute review සඳහා අවශ්‍ය privileged read එක reason එකක් සහ audit event එකක් සමඟ වෙනම authorize කරන්න; එයින් borrower access unlock නොවිය යුතුයි.

අදියර 3 implementation එකේ offer draft/edit/submit/withdraw සඳහා Lender Maker role එකත්, independent offer review සඳහා Lender Approver role එකත් අවශ්‍යයි. Offer contributors ට publication approve කරන්න බැහැ. Borrower staff ට lender internal offer routes access නැහැ; comparison එකට random request-local token, label, rate, structured conditions සහ validity පමණක් ලැබෙනවා.

Selection proposal එක Borrower Maker කෙනෙක් යෝජනා කර වෙනත් Borrower Approver කෙනෙක් approve/reject කරනවා. Admin/Auditor users ට own-bank comparison/deal terms read කළ හැකි නමුත් treasury actions කළ නොහැක. Selected lender treasury staff ට borrower financial documents දිගටම කියවිය හැකියි; losing lenders ට request/document access close වෙනවා. Pre-fee deal view එකේ කිසිම borrower role එකකට lender identity/account/contact fields නැහැ. Privileged platform role එකකටත් treasury/fee gate bypass නැහැ.

අදියර 4 implementation එකේ payment ආරම්භ කළ හැක්කේ owning verified Borrower Approver කෙනෙකුට පමණයි. Amount, currency, reference සහ paid/unlocked status client එකෙන් ලබාදිය නොහැක. Payment adapter එක authenticated provider notification එකේ amount/currency/reference/deal සහ current eligibility නැවත පරීක්ෂා කර unlock කරනවා; browser success හෝ operations action එකක් payment proof නොවේ.

Unlock වුණත් සාමාන්‍ය deal API/HTML responses වල lender identity/account/contact fields ඇතුළත් නැහැ. වෙනම disclosure endpoint එක deal participation, Maker/Approver role, banks දෙකේ verification/capabilities, approved instruction snapshot සහ එම deal එකටම අදාළ verified receipt පරීක්ෂා කරනවා. සෑම disclosure read එකක්ම audit කරන අතර private no-store response එකක් ලබාදෙනවා. Admin-only, Auditor-only සහ platform users ට bypass නැහැ; suspension/role revocation පසු reads වහාම නවත්වනවා.

Platform Operations ට payment exception list එක සහ reason එකක් සමඟ audited receipt details කියවිය හැකියි. Refund reference සහ review reason record කිරීම transfer execution එකක් නොවේ; එම route එකෙන් paid/unlocked කළ නොහැක. Lender Maker විසින් pre-payment instruction change යෝජනා කර වෙනත් own-bank Approver කෙනෙක් review කරනවා; borrower ට ලැබෙන්නේ identity-free notice එකක් පමණයි.

අදියර 5 implementation එකේ verified participating bank Maker settlement attestation එකක් යෝජනා කර වෙනත් same-bank Approver කෙනෙක් review කරනවා. Pending/approved record එකකට වෙනත් confirmation එකක් යෝජනා කළ නොහැක. Approval වෙලාවේ payment proof, banks දෙක, approved instruction version සහ proposing makerගේ active membership/role නැවත පරීක්ෂා කරනවා. Reference, note සහ review reason encrypted ලෙස තබා owning bank treasury users ට පමණක් ලබාදෙනවා; අනෙක් පාර්ශ්වයට සහ Admin/Auditor-only users ට status පමණයි.

Owning bank Approver ට unlocked deal එකක් Disputed කළ හැකියි; අනෙක් bank එක suspended වුණත් තමන් verified නම් issue report කළ හැකියි. Platform Operations ට reason-audited dispute evidence review සහ resolution පමණක් කළ හැකියි. Approved confirmations දෙක නොමැතිව Settled කරන්න හෝ bank confirmation එකක් සකස් කරන්න බැහැ. Dispute තුළ independently approved instruction correction එකක් කළොත් කලින් confirmations supersede කර දෙපාර්ශ්වයටම fresh approvals අවශ්‍ය කරනවා.

In-app notifications recipient + current bank + current role + deal participation අනුව filter කරනවා. Read acknowledgement එක වෙනත් user කෙනෙකුගේ notification ID එකෙන් කළ නොහැක. Notifications සහ paginated deal timeline fixed status text පමණක් සපයනවා; accounts, contacts, actor identities, references හෝ private narratives ඇතුළත් නැහැ.

## Bank සහ workflow සීමාවන්

- Unverified හෝ suspended bank එකකට marketplace browse, publish, bid, select, pay හෝ confirm කරන්න බැහැ. Unverified bank එකකට තම onboarding process එක පමණක් භාවිතා කළ හැකියි.
- දෙපාර්ශ්වයේ eligibility එක selection සහ unlock වෙලාවෙත් නැවත පරීක්ෂා කරන්න. Selected bank එක suspended නම් payment/unlock exception එක review කරන්න.
- Borrower/Lender capabilities දෙකම ඇති බැංකුවකටත් තම request එකට bid කරන්න බැහැ.
- Approval එකකට යොමු කළ terms වෙනස් කළොත් කලින් approval එක අවලංගු කර නැවත approve කරන්න.
- Publication approval එක proposer ට හෝ එම request එක create/edit/document upload/remove කළ වෙනත් contributor කෙනෙකුට approve කළ නොහැක. Roles කිහිපයක් තිබුණත් මේ සීමාව user ID අනුව enforce වෙනවා.
- Pending publication එකක terms/files වෙනස් කළොත් approval එක supersede කර Draft කරන්න. Proposal එකේ borrower identity snapshot එකත් approval වෙලාවේ නැවත පරීක්ෂා කරන්න.
- Published request එකේ borrower profile, financial terms සහ documents freeze කරන්න. Cancellation සඳහා වෙනත් approver කෙනෙක් සහ reason එකක් අවශ්‍යයි.
- Membership revoke කිරීමෙන් පසු sessions, downloads සහ අනෙක් reads/writes වල access නැවත පරීක්ෂා විය යුතුයි.

## Enforcement

- Laravel භාවිතා කරනවා නම් Policies + bank-scoped queries + explicit response resources යොදාගන්න. Frontend visibility එක authorization එකක් ලෙස භාවිතා නොකරන්න.
- Financial documents private storage එකක තබන්න. Authorization පරීක්ෂා කරන download route එකකින් serve කරන්න; public permanent URLs නොදෙන්න.
- Borrower-facing payloads වල lender IDs, bank names, emails, actor names හෝ internal relationships ඇතුළත් නොකරන්න. Anonymous label එක request එක තුළට පමණක් සීමා කරන්න.
- Notifications, exports, search, cached responses සහ background jobs වලත් එම access rules යොදන්න. Audit history එකෙන් ද identity leak නොවිය යුතුයි.
- Fee verification යනු verified payment handler එකේ action එකක්. සාමාන්‍ය user/admin status update එකක් හරහා `paid` හෝ `unlocked` කළ නොහැක.
- Login rate limits සහ session revocation foundation එකට ඇතුළත් කරන්න. Real-bank launch එකට staff MFA සකස් කරන්න.

## Acceptance checks

- [x] Anonymous user කෙනෙකුට marketplace/private documents access නැහැ.
- [x] Bank A user කෙනෙකුට Bank B funding draft එක ID එකෙන් සොයාගත නොහැක.
- [x] Verified lender treasury user කෙනෙකුට open request එකේ borrower profile/documents පමණක් පෙනේ; private approvals/storage paths නොපෙනේ.
- [x] Publication self-approval, stale/replayed approval සහ published terms/document changes ප්‍රතික්ෂේප වේ.
- [x] Deadline ඉක්මවූ requests lender lists/details/downloads වලින් වහාම ඉවත් වේ; expiry job එක නැවත run කළත් duplicate events නොහැදේ.
- [x] Bank A user කෙනෙකුට Bank B private deal එක ID එකෙන් සොයාගත නොහැක.
- [x] Lender කෙනෙකුට competitor bids නොපෙනේ.
- [x] Borrower comparison/deal API, HTML සහ audit views වලින් pre-fee lender identity/instructions හෙළි නොවේ.
- [x] Cross-request offer token, self-selection approval, stale/replayed decision, withdrawn/expired/ineligible offer selection ප්‍රතික්ෂේප වේ.
- [x] Database constraints සහ request lock එකෙන් active lender offer, pending selection සහ winning deal uniqueness enforce වෙනවා.
- [x] Separate PostgreSQL backend sessions වල competing selection proposals/approvals, duplicate/extra payments සහ simultaneous settlement approvals එක winner/allocation/event එකකට සීමා වේ.
- [x] In-app notifications සහ deal timeline වල identity/reference/narrative leakage නැහැ; current role සහ recipient isolation enforce වේ.
- [ ] Email notifications සහ exports implement කළ විට anonymity rules නැවත verify කරන්න.
- [x] Fee verify කළ පසු selected lender පමණක් අදාළ treasury users ට වෙනම audited disclosure view එකෙන් පෙනේ.
- [x] Unsigned/fake, wrong amount/currency/deal, replay-conflicting සහ late payments මඟින් unlock කළ නොහැක; සාමාන්‍ය user/admin paid override නැහැ.
- [x] Admin/Auditor/platform accounts, copied payment proof සහ revoked/suspended membership මඟින් disclosure bypass කළ නොහැක.
- [x] Fee exception details සඳහා platform reason/audit අවශ්‍යයි; refund attestations සහ instruction changes bank/role/state සීමා enforce කරනවා.
- [x] Confirmation self-approval, cross-bank review, duplicate/replayed approvals, stale instruction/member සහ forged fields reject වේ.
- [x] One-bank approval Settled නොකරයි; operations ට දෙපාර්ශ්වයේ evidence නොමැතිව successful settlement fabricate කළ නොහැක.
- [x] Disputed instruction corrections old evidence preserve කර previous confirmations supersede කරනවා; fresh approvals දෙක අවශ්‍යයි.
- [x] Self-approval, self-promotion, arbitrary status update සහ revoked membership access ප්‍රතික්ෂේප වේ.
- [x] Suspension හෝ role වෙනසක් cached response/download මඟින් bypass කළ නොහැක.
