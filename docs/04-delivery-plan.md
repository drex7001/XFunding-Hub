# 04 — Delivery plan

මේ අදියර පිළිවෙළට කරන්න. එක් අදියරේ tasks සහ acceptance checks අවසන් වූ පසු පමණක් ඊළඟ අදියර සම්පූර්ණ ලෙස සැලකිය හැකියි. දැනට අදියර 0–5 implement කර අදියර 6 demo verification අවසන් කර තිබෙනවා; live preparation ඉතිරියි. Payment verification එක synthetic demo adapter එකකටත් settlement evidence එක bank attestations වලටත් සීමා වේ.

## අදියර 0 — අලුත් foundation එක

**ප්‍රතිඵලය:** Existing test application එක මත depend නොවන MVP skeleton එකක්.

- [x] [Scope defaults සහ open decisions](01-product-scope.md) review කර onboarding සඳහා අවශ්‍ය decisions record කරන්න.
- [x] Supported stack/runtime තෝරන්න. සරල Laravel monolith එකක් යෝජිත default එකයි; existing sample version/schema/API contract preserve කිරීම requirement එකක් නොවේ.
- [x] Implementation පටන්ගන්න විට repository `AGENTS.md` setup instructions අදාළ පරිදි සම්පූර්ණ කරන්න. Existing demo code තබනවාද ඉවත් කරනවාද තීරණය කර public sample routes disable කරන්න.
- [x] Dev/test databases, private file storage, job runner සහ migrations සකස් කරන්න.
- [x] Fake banks/users සමඟ seed data සහ repeatable test setup එකක් සකස් කරන්න.

**අවසන් බව පෙන්වන්නේ:** අලුත් developer කෙනෙකුට app එක boot කර tests run කළ හැකියි. Demo customer IDs/statuses මත marketplace flow එක depend නොවේ.

## අදියර 1 — Identity සහ access control

**Dependency:** අදියර 0.

- [x] Login/logout, invitations, password reset, session revocation සහ login rate limits.
- [x] Bank, membership, capability සහ fixed roles; platform accounts වෙන් කිරීම.
- [x] Platform bank verification/suspension සහ සීමිත onboarding screen.
- [x] Bank-scoped policies, role administration සහ maker–checker approvals.
- [x] Private download authorization සහ access/approval audit events.

**අවසන් බව පෙන්වන්නේ:** Bank A ට Bank B private data access නැහැ. Pending/suspended bank marketplace access, self-approval, self-promotion සහ revoked sessions වල access ප්‍රතික්ෂේප වෙනවා. UI සහ API දෙකෙන්ම checks pass වෙනවා.

## අදියර 2 — Borrower requests සහ financial documents

**Dependency:** අදියර 1.

- [x] Bank profile, document upload සහ authorized download; file type/size limits.
- [x] FundingRequest draft, validation, approval, publish සහ cancellation.
- [x] Currency/rate/tenor conventions සහ exact decimal handling.
- [x] Published terms/documents freeze කිරීම සහ deadline expiry job එක.
- [x] Borrower request dashboard සහ verified lenders සඳහා published-request browse/detail screens.

**අවසන් බව පෙන්වන්නේ:** Maker කෙනෙක් request සකස් කර වෙනත් Approver කෙනෙක් publish කරනවා. Verified lender treasury user කෙනෙකුට borrower profile/documents පෙනෙනවා; public/unauthorized user ට නොපෙනේ. Deadline ඉක්මවූ request එක lender marketplace/details/downloads වලට ලබාගත නොහැක. Bid/select actions සඳහාත් එම cutoff එක enforce කිරීම අදියර 3 acceptance එකේ කොටසක්.

**Validation:** Funding feature tests 66ක් මඟින් independent approvals, forged ownership/status, bank/role isolation, exact amounts, frozen snapshots/files, stale/replayed approvals, cancellation සහ immediate/idempotent expiry verify කරනවා. Document count වැඩි කළත් funding-detail queries වැඩි නොවන regression check එකක් තිබෙනවා. Demo seeder එක Draft, PendingApproval සහ Open requests සමඟ synthetic PDF එකක් සපයනවා. Live database locking සහ Lerd route timings අදියර 6 දක්වා pending.

සම්පූර්ණ suite එක: tests 157 / assertions 460 pass. Pint, compiled Blade syntax සහ Markdown links checks pass. Browser එකෙන් Maker create/edit/detail, Approver publication controls සහ Lender published-only marketplace/profile screens පරීක්ෂා කළා. Local expiry scheduler එකත් run කර command execution verify කළා.

## අදියර 3 — Blind offers සහ selection

**Dependency:** අදියර 2.

- [x] Offer drafting, approval, withdrawal/replacement සහ expiry.
- [x] Lender contact/settlement instructions සඳහා maker–checker approval සහ version history.
- [x] Own-request bidding සහ duplicate active offers block කිරීම.
- [x] Lender ට තම offers පමණක් පෙන්වීම; borrower ට structured anonymous comparison එකක්.
- [x] Borrower selection proposal + වෙනත් Approver approval.
- [x] Concurrent selection transactions සහ database constraints මඟින් එක් request එකකට එක් deal එකක්; selected terms snapshot සහ losing offers closure.

**අවසන් බව පෙන්වන්නේ:** Lenders දෙදෙනෙකු bid කර borrower කෙනෙක් compare/select කරනවා. Winner එකක් පමණක් ලැබෙනවා. දැනට තිබෙන API/HTML responses සහ audit views හරහා pre-fee lender identity හෙළි නොවේ. Expired/withdrawn offer select කරන්න බැහැ. Notifications/exports එකතු කළ විට ඒවා සඳහාත් anonymity checks අවශ්‍යයි.

**Validation:** Offer/instruction/selection feature tests 99ක්; සම්පූර්ණ suite එක tests 256 / assertions 857 pass. Independent approvals, exact rates, anonymity, immutable encrypted instruction snapshots, withdrawal/replacement, eligibility/cutoff changes, stale sessions, approval replay සහ winner constraints verify කළා. Offers 1 සිට 9 දක්වා වැඩි කළත් comparison query count bounded බවත් demo seed idempotency එකත් tests වලින් verify වෙනවා. Live database එකේ multi-session locking සහ Lerd timings අදියර 6 තුළ verify කළ යුතුයි.

අදියර 3 handover එකේ deal එක AwaitingFee ලෙස locked කර selected terms/instructions snapshot කළා. Fee quote/deadline/verification පහත අදියර 4 තුළ එකතු කර තිබෙනවා. Notification/export anonymity checks ඒ surfaces implement කළ විට නැවත කරන්න.

Browser එකෙන් lender offer dashboard/instruction version සහ borrower comparison → Maker proposal → independent Approver approval → locked deal flow එක verify කළා. Local walkthrough එකේ එක් offer එකක් Selected සහ අනෙක් offer එක Unsuccessful; Working capital request එකේ offers දෙක තවම compare කළ හැකියි. Compiled Blade syntax, Pint, Markdown links සහ whitespace checks pass.

## අදියර 4 — Fee payment සහ controlled disclosure

**Dependency:** අදියර 3; fee formula/currency/deadline/refund decisions record කර තිබීම.

- [x] Demo fee formula/currency/deadline/tax/refund defaults user සමඟ තහවුරු කර scope document එකේ record කිරීම.
- [x] Fee calculation, frozen payment quote/policy සහ fee deadline; user ට ගාස්තුව පෙර පෙන්වීම.
- [x] Demo සඳහා authenticated test adapter එකක්; explicit local/testing enable සහ production rejection.
- [x] Amount/currency/reference/deal validation, event/charge deduplication සහ idempotent verification handler.
- [x] Atomic payment verification + unlock; selected lender පමණක් disclose කිරීම.
- [x] Failed/duplicate/late/unmatched payments, bank suspension සහ RefundReview flow; reason-audited receipt reads සහ manual refund attestations.
- [x] Treasury staff සඳහා authorized, audited, no-store contact/settlement details view.
- [x] Pre-payment instruction changes සඳහා independent approval, encrypted history සහ identity-free notice; pending payments/refunds සහ post-unlock overwrite block කිරීම.
- [x] Fee deadline expiry command/scheduler, idempotent demo seeder සහ safe local setup/simulation commands.

**අවසන් බව පෙන්වන්නේ:** Failed/fake හෝ wrong-amount/currency payment එකකින් unlock නොවේ. Verified correct payment එකෙන් deal එක එක් වරක් unlock වේ. අනෙක් lenders hidden ලෙසම පවතිනවා. Late payment එක cancelled deal එක revive කරන්නේ නැහැ.

**Demo decisions:** 10 bps (0.1%) of principal, loan currency එකම, smallest unit දක්වා round up, tax simulation 0, selection සිට පැය 24 offer expiry එකට සීමා කරලා, refunds සඳහා manual review. මේ defaults user විසින් demo සඳහා අනුමත කළා. Real pricing/provider/refund policy වෙනම launch decisions.

**Validation:** නව fee/disclosure/refund/instruction-change tests 82ක්; සම්පූර්ණ suite එක **tests 338 / assertions 1,248 pass**. Raw-body signature/timestamp, malformed/forged payloads, exact amounts, stale roles/sessions, bank suspension, expired reservations, copied payment proof, event conflicts, charge reuse, unmatched/extra receipts සහ atomic rollback verify කරනවා. Refund idempotency/reference uniqueness, independent instruction approvals සහ immutable fee/financial snapshotsත් verify කරනවා. Deal list එක deals 2 සිට 8 දක්වා වැඩි කළත් query count bounded.

Browser එකෙන් borrower Approver payment attempt → signed synthetic confirmation → Unlocked deal → selected Meridian Bank (Demo) settlement details flow එක verify කළා. Ordinary deal view එක identity-free ලෙසම පවතිනවා. No real money collected. Actual payment provider/reconciliation, live database multi-session locking සහ Lerd route timings අදියර 6 තුළ pending; native preview එකෙන් Lerd traffic samples ලැබෙන්නේ නැහැ.

Pint, compiled Blade syntax (files 86), Markdown file links සහ whitespace checks pass. Local demo payment setup key එක output නොකර existing environment settings preserve කරන බවත් tests වලින් verify කළා. Run instructions [project README](../README.md#demo-fee-payment-and-disclosure) එකේ තිබෙනවා.

## අදියර 5 — Settlement සහ operations

**Dependency:** අදියර 4.

- [x] එක් එක් bank එකට maker–checker settlement attestation, encrypted reference/note සහ current instruction-version checks.
- [x] Confirmation එකක් සහ දෙකක් ලැබුණු අවස්ථා UI එකේ පැහැදිලිව පෙන්වීම.
- [x] Both confirmed → atomic deal/request Settled; dispute/ClosedUnsettled resolution සහ audit evidence.
- [x] Settlement disputes සඳහා reason-audited operations evidence view; no unilateral successful-settlement override.
- [x] Dispute තුළ independently approved instruction correction, encrypted history සහ old confirmations supersede කර fresh attestations අවශ්‍ය කිරීම.
- [x] Role අනුව in-app notifications සහ paginated deal timeline; emails/exports වෙනම future surfaces.
- [x] Existing paid demo deal එකකට idempotent optional pending-attestation seeder; user profiles/history preserve කිරීම.

**අවසන් බව පෙන්වන්නේ:** එක් bank එකක් confirm කිරීමෙන් Settled නොවේ. දෙපාර්ශ්වයේ approved confirmations තිබෙන විට පමණක් Settled වෙනවා. Operations ට successful transfer එකක් තනිව mark කරන්න බැහැ.

**Validation:** නව settlement/dispute/notification tests **68ක්**; සම්පූර්ණ suite එක **tests 406 / assertions 1,525 pass**. Own-bank maker/checker roles, self/cross-bank approval, forged fields, timestamps, stale membership/instructions, pending/approved uniqueness, approval replay සහ atomic rollback verify කරනවා. One-bank pending state, normal second approval, disputed dual-confirmation resolution, ClosedUnsettled closure, encrypted private evidence සහ corrected instructions සඳහා fresh approvals දෙකත් verify කළා. Notifications recipient/current-role checks, generic timeline privacy/pagination සහ bounded query counts pass.

Browser එකෙන් borrower approval → dispute → lender Maker proposal → independent lender approval → reason-audited operations review/resolution → Settled flow එක verify කළා. Role-scoped notification feed සහ paginated timeline screensත් පරීක්ෂා කළා. Pint, compiled Blade syntax (files 89), Markdown links සහ whitespace checks pass.

Live database multi-session locking, actual payment/refund integration සහ Lerd traffic timings අදියර 6 සඳහා තවම ඉතිරියි. Native preview එකේ Lerd `optimize_route` samples 0; automated query regressions pass වීම live runtime profiling ලෙස සලකන්නේ නැහැ.

## අදියර 6 — Demo verification සහ live preparation

**Dependency:** අදියර 1–5; live-only tasks සඳහා අදාළ business decisions.

- [x] PostgreSQL තෝරාගැනීම record කරන්න; user විසින් payment provider තීරණය පසුවට තැබුවා.
- [x] Borrower bank එකක්, lender banks දෙකක් සහ වෙන වෙනම Maker/Approver users සමඟ request → fee → settlement සම්පූර්ණ HTTP scenario එක run කරන්න; lenders දෙදෙනාම winner විය හැකි cases දෙක verify කරන්න.
- [x] Access-control acceptance checks, expiry, duplicate payment සහ actual separate-session PostgreSQL selection/payment/settlement race checks pass කරන්න.
- [x] Bank roles අනුව lists, private downloads, disclosure, in-app notifications සහ timeline නැවත review කරන්න.
- [x] PostgreSQL notification JSON filtering දෝෂය data-preserving migration එකකින් නිවැරදි කර existing payloads/IDs රැකෙන බව verify කරන්න.
- [x] Existing local SQLite history PostgreSQL වෙත transaction එකකින් copy කර encrypted proof, user-edited profiles, private-file checksums සහ ID sequences verify කරන්න.
- [x] Lerd PHP 8.4 / PostgreSQL runtime එකේ affected routes hit කර query capture/optimize_route මඟින් N+1/slow-route regressions review කරන්න.
- [x] Local PostgreSQL SQL export එක වෙනම recovery database එකකට restore කර tables 29ක counts/content hashes සසඳන්න; temporary recovery databases ඉවත් කරන්න.
- [x] Queue සහ expiry scheduler Lerd workers ලෙස run කර local Mailpit SMTP connection සකස් කරන්න.
- [ ] Email notifications/exports පසුව එකතු කළොත් ඒ surfaces වල anonymity/access review නැවත කරන්න; ඒවා දැනට නැහැ.
- [ ] Real payment provider/verified transfer process සම්බන්ධ කර sandbox reconciliation සහ actual refund checks අවසන් කරන්න. User තීරණය පසුවට තැබුවා; demo adapter production වල disabled.
- [ ] Real-bank staff MFA implement/verify කරන්න.
- [ ] Production off-host encrypted backups, separate APP_KEY recovery, monitoring/alerting සහ document retention policy සකස් කරන්න.
- [ ] Bank due-diligence process, platform terms, live fee/refund policy සහ jurisdiction-specific regulatory review අවසන් කරන්න.

**Validation — 2026-10-16:** PostgreSQL 16.14 suite එක **tests 423 / assertions 1,847 pass**. SQLite suite එක **tests 411 / assertions 1,783 pass**, PostgreSQL-only tests 12ක් intentionally skip වෙනවා. නව integrated journey tests දෙක API actions හරහා draft/PDF/publication, lenders දෙදෙනාගේ independently approved instructions/offers, blind comparison, selection, wrong-amount refund review, exact signed payment/replay, restricted disclosure සහ both-bank settlement verify කරනවා.

PostgreSQL concurrency tests 6ක් actual backend sessions දෙක barrier එකකින් එකවර පටන්ගෙන competing offer selections, approval replay, same-event/same-charge/additional-charge notifications සහ simultaneous bank approvals verify කරනවා. එක් pending choice/winner, එක් canonical allocation සහ එක් settlement event එකක් පමණයි. Test configuration fixed xfunding_hub_testing database එකකට සීමා කර තිබෙනවා; parallel suite execution නොකරන්න.

Local app එක http://xfunding-hub.test හි PostgreSQL වෙත migrate කර තිබෙනවා. Banks 5, users 17, notifications 39 සහ සියලු deal/fee/settlement/audit history copy කළා; source SQLite checksum එක unchanged. Sessions/reset tokens/cache migrate නොකළ නිසා නැවත sign in කළ යුතුයි. APP_KEY සහ private PDFs රැකුණා; missing/changed files, wrong key සහ non-empty targets මත importer rollback වෙන බව tests වලින් verify කළා.

Browser එකෙන් migrated Settled deal, role-scoped notifications සහ selected Meridian Bank encrypted account/contact details verify කළා. Lerd captured requests 11ක median 80 ms; slow routes නැහැ. Generic fingerprints වෙනස් tables වල fixed queries එකට group කරන findings raw SQL මඟින් review කළා; existing PostgreSQL query-count regression checks අනුව record count වැඩි වූ විට query count වැඩි වෙන N+1 නැහැ. මේ local samples load test එකක් නොවේ.

Backup rehearsal එකේ persistent tables 29ක counts සහ canonical row-content hashes match වුණා. Recovery databases ඉවත් කළා; private temporary export එක /tmp/xfunding-runtime/recovery-check/ තුළ තිබෙනවා. මේක durable production backup එකක් හෝ off-host recovery drill එකක් නොවේ. Real recovery සඳහා database, private documents සහ original key වෙන වෙනම secure කර restore target එකක verify කළ යුතුයි.

**Demo-ready:** සම්පූර්ණ fake-data/test-payment flow සහ rejection scenarios verify කර තිබෙනවා.

Pint, compiled Blade syntax (files 95), Markdown links සහ whitespace checks pass. Lerd queue/scheduler workers healthy; query capture නැවත disabled කර තිබෙනවා. Verification browser session එක අවසන් කළා; නව local URL එකෙන් නැවත sign in කළ හැකියි.

**Live-ready:** තවම නැහැ. Payment integration, MFA සහ ඉහත production/business checks ඉතිරියි. Phase 6 සම්පූර්ණ ලෙස mark කර නැහැ.

## එක් එක් අදියරේ handover එක

අවසන් කළ tasks tick කර, relevant tests/checks සහ තවම ඉතිරි decisions කෙටියෙන් record කරන්න. Requirement වෙනස් වුණොත් scope/access/workflow document එක update කර පසුව checklist එක වෙනස් කරන්න. අමතර ceremony හෝ වෙනම status documents අවශ්‍ය නැහැ.
