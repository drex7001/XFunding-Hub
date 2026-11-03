# XFunding Hub

**භාෂාව:** [English](README.md) · Singlish (සිංහල)

B2B interbank liquidity marketplace එකක්. Product scope සහ implementation පිළිවෙළ [docs/README.si.md](docs/README.si.md) එකේ තිබෙනවා.

## දැනට ඇති milestone එක

Bank onboarding, access control, funding requests, blind offer selection, demo fee verification/disclosure සහ settlement attestations implement කරලා තිබෙනවා:

- Invitation-only bank accounts සහ වෙනම platform operations accounts.
- Login, logout, password reset, rate limits සහ session revocation.
- Bank verification, suspension, capability approval සහ profile review.
- Fixed bank roles, independently approved invitations/role changes සහ last-admin protection.
- Authorized, audited downloads සහිත private PDF onboarding documents.
- Bank workspace සහ platform operations screens.
- Funding drafts, private financial PDFs, independent publication/cancellation approval සහ frozen published terms.
- Borrower request dashboard සහ verified lender treasury marketplace.
- Exact monetary amounts, UTC deadlines සහ scheduled request expiry.
- Independent approval සහිත encrypted lender instruction versions.
- Lender offer drafts/approvals, withdrawal/replacement සහ validity expiry.
- Anonymous borrower comparison, independent selection සහ request එකකට එක් reserved deal එකක්.
- Frozen demo fee quotes, authenticated payment notifications සහ atomic verification/unlock.
- Selected lender ගේ විස්තර පමණක් audited treasury disclosure, fee exception review සහ refund attestations.
- Encrypted history සහිත, independently approved pre-payment instruction changes.
- එක් එක් බැංකුවෙන් වෙනම maker–checker settlement attestations, disputes සහ audited operations resolutions.
- Role-based in-app notifications සහ paginated deal timeline එකක්.
- සෑම selection එකක් සමඟම තෝරන, fee verify වූ පසු පමණක් selected lender ට පෙන්වන borrower receiving instructions.
- Institution audit history, platform account administration, instruction version retirement සහ revoke කළ staff reinstate කිරීම.
- Role-aware Home page එකක්, සෑම request, offer සහ deal එකකම progress tracker එකක් සහ පැහැදිලි ඊළඟ පියවරක්.

Demo workflow එක concurrent sessions සහ isolated backup restore එකක් ඇතුළුව PostgreSQL මත verify කරලා තිබෙනවා. ඉතිරි live preparation [delivery plan](docs/04-delivery-plan.si.md) එකේ තිබෙනවා. දැනට payments සඳහා synthetic local/test adapter එකක් භාවිතා කරනවා; සැබෑ මුදල් අය කරන්නේ නැති අතර production වල එය disabled. Settlement records යනු bank attestations මිස interbank transfer එකක independent verification එකක් නොවේ.

## Local setup

PDO PostgreSQL, PDO SQLite, XML/DOM, mbstring, fileinfo සහ cURL සමඟ PHP 8.4+ සහ Composer භාවිතා කරන්න. දැනට Lerd runtime එක PHP 8.4.25 සහ PostgreSQL 16.14. Laravel 13 සහ package versions composer.lock එකේ pin කර තිබෙනවා. වේගවත් isolated tests සඳහා SQLite තවමත් භාවිතා කළ හැකියි.

Lerd සමඟ **fresh checkout** එකක් සඳහා:

~~~sh
lerd link
lerd composer install
cp .env.example .env
lerd env
lerd php artisan migrate --no-interaction
lerd php artisan db:seed --no-interaction
~~~

**http://xfunding-hub.test** open කරන්න. Track කරන .php-version එක PHP 8.4 තෝරන අතර .lerd.yaml එක PostgreSQL declare කරනවා. Lerd විසින් local connection values සපයා application/testing databases සාදනවා. Existing installation එකක .env සහ APP_KEY තබාගෙන dependencies install කර migrations run කරන්න; අලුත් key එකක් generate කළොත් stored encrypted instructions කියවිය නොහැකි වේ.

මේ screens Blade සහ static CSS භාවිතා කරන නිසා frontend build එකක් අවශ්‍ය නැහැ. Native PHP installation එකක් සඳහා PostgreSQL connection එක configure කර local server එක start කිරීමට පෙර එම Artisan migrations/seeder run කරන්න.

පවතින local demo එක users, bank decisions හෝ history reset නොකර SQLite සිට xfunding_hub වෙත copy කළා. මුල් database/database.sqlite එක local snapshot එකක් ලෙස වෙනස් නොකර තබා තිබෙනවා. Sessions, password-reset tokens සහ cache exclude කළ නිසා නැවත sign in කරන්න. xfunding_hub_testing වෙනම database එකක් වන අතර එහි ඇත්තේ ඉවත දැමිය හැකි synthetic test data පමණයි.

තවත් local SQLite-to-PostgreSQL demo copy එකක් සඳහා application එක maintenance mode එකේ තබා, existing key/private files තබාගෙන, පළමුව **empty PostgreSQL target** එකක් migrate කරන්න:

~~~sh
lerd php artisan marketplace:import-sqlite /absolute/path/to/source.sqlite --dry-run
lerd php artisan marketplace:import-sqlite /absolute/path/to/source.sqlite
~~~

Importer එක production සහ non-empty targets ප්‍රතික්ෂේප කරනවා, encrypted records සහ document checksums validate කරනවා, එක් transaction එකකින් copy කරනවා සහ PostgreSQL ID sequences reset කරනවා. SQLite source එකට කිසිවිටක write කරන්නේ නැහැ. මෙය local demo migration tool එකක් මිස general production migration service එකක් නොවේ.

දැනට queue සහ scheduler Lerd workers ලෙස run වෙනවා. Fresh installation එකකදී ඒවා start කරන්න:

~~~sh
lerd queue:start
lerd schedule:start
~~~

Lerd නැතිනම් php artisan queue:work --tries=3 සහ php artisan schedule:work සඳහා වෙනම terminals භාවිතා කරන්න. Scheduler එක සෑම විනාඩියකම funding:expire, offers:expire සහ deals:expire-fees run කරනවා; guards deadlines වහාම enforce කරනවා. Deployment සඳහා Laravel ගේ සාමාන්‍ය scheduler cron එක භාවිතා කරන්න.

දැනට environment එක Lerd විසින් configure කළ Mailpit SMTP භාවිතා කරනවා; inbox එක http://localhost:8025 හි තිබෙනවා. Transport එකක් configure කරන තුරු example environment එක default ලෙස log mail භාවිතා කරනවා. Invitations දින තුනකින් expire වන අතර එක් වරක් පමණක් accept කළ හැකියි; staff invitations සහ role changes සඳහා independent approval අවශ්‍යයි.

## Demo accounts

Demo seeder එක local/test environment එකක පමණක් run කරන්න. Production වල එය refuse කරනවා. පහත සියලු institutions සහ addresses synthetic. සෑම demo account එකක්ම **DemoBank123!** password එක භාවිතා කරනවා.

| Account | Email |
|---|---|
| Platform operations | operations@xfunding.example |
| Borrower bank admin | borrower.admin@xfunding.example |
| Borrower maker / approver / auditor | borrower.maker@xfunding.example / borrower.approver@xfunding.example / borrower.auditor@xfunding.example |
| Verified lender staff | lender-one.admin@xfunding.example / lender-one.maker@xfunding.example / lender-one.approver@xfunding.example / lender-one.auditor@xfunding.example |
| දෙවන verified demo lender | lender-three.admin@xfunding.example / lender-three.maker@xfunding.example / lender-three.approver@xfunding.example / lender-three.auditor@xfunding.example |
| Pending lender staff | lender-two.admin@xfunding.example / lender-two.maker@xfunding.example / lender-two.approver@xfunding.example / lender-two.auditor@xfunding.example |

Banks review කිරීමට operations ලෙස, නැත්නම් තම profile review කර staff invitations ඉල්ලීමට bank admin ලෙස පටන්ගන්න. Institution profile එකක් වෙනස් කළොත් එය නැවත pending verification වෙත ගොස් approved capabilities disable වෙනවා.

Funding workflow එක සඳහා `borrower.maker@xfunding.example` ලෙස sign in කර `/funding-requests` open කරන්න. Seed එක draft එකක්, approval බලාපොරොත්තුවෙන් ඇති publication එකක් සහ synthetic PDFs සහිත published requests දෙකක් සාදනවා. Pending publication එක review කිරීමට `borrower.approver@xfunding.example` ද, `/marketplace` සහ එහි published borrower documents browse කිරීමට `lender-one.maker@xfunding.example` ද භාවිතා කරන්න. Admin-only සහ auditor-only users ට වෙනත් institutions වල requests browse කළ නොහැක.

Existing installation එකකට funding examples පමණක් එකතු කිරීමට `php artisan db:seed --class=DemoFundingRequestSeeder` භාවිතා කරන්න. මේ seeder එක existing examples skip කර bank profiles, verification සහ memberships preserve කරනවා. සම්පූර්ණ demo bank seeder එක නැවත run කළොත් demo account/bank settings reset වෙනවා.

Existing installation එකකට blind-offer examples එකතු කිරීමට `php artisan db:seed --class=DemoMarketplaceSeeder` run කරන්න. එය වෙනම synthetic verified lender කෙනෙක් (Meridian Bank), approved instructions, සෑම open demo request එකකටම offers දෙක බැගින් සහ demo borrower ට approved receiving version එකක් එකතු කරනවා. Summit Bank හි suspension එකක් තිබේ නම් එයත් ඇතුළුව existing bank decisions, accounts සහ offer history preserve වෙනවා. Comparison එකේ unsecured 8.1250% offer එකක් සහ sovereign bonds අවශ්‍ය 7.9000% offer එකක් තිබෙනවා.

Borrower maker ලෙස published request එකක් open කර **Compare offers** තෝරන්න. ඔබගේ receiving account එක තෝරා එක් offer එකක් propose කර, borrower approver ලෙස sign in වී selection approve කරන්න. ප්‍රතිඵලය locked deal එකක්; lender names, contacts සහ settlement accounts hidden ලෙසම පවතිනවා. Selection උත්සාහ කිරීමට **Offer comparison walkthrough** වෙනම request එකක් සපයන අතර **Working capital liquidity** comparison සඳහා තවමත් ලබාගත හැකියි. සියලු demo accounts ඉහත password එක භාවිතා කරනවා.

## Funding request rules

Local demo එක decimal places දෙකක් සහිත LKR, USD සහ GBP, tenor දින 1–3,650, ACT/360 හෝ ACT/365F සහ annualized simple rate basis එකක් support කරනවා. මේවා `config/marketplace.php` හි working defaults; launch conventions සඳහා තවම business decision එකක් අවශ්‍යයි. Amounts integer minor units භාවිතා කරන අතර API boundary එකේ `0.01` සිට `999999999999.99` දක්වා decimal strings ලෙස තිබෙනවා. JSON number එකක් වෙනුවට `"amount": "250000000.00"` ලෙස යවන්න.

Bidding deadline එක අනාගත UTC වෙලාවක් විය යුතු අතර settlement date එකට පෙර හෝ එදින විය යුතුයි. Publication ඉල්ලීමට පෙර අවම වශයෙන් financial PDF එකක් upload කරන්න; file එකකට උපරිම 10 MB. මේ request documents private onboarding evidence වලින් වෙනම තිබෙනවා.

Request එකක් draft කිරීමට හෝ වෙනස් කිරීමට හැක්කේ verified borrower treasury maker කෙනෙකුට පමණයි. එම request එක create, edit හෝ documents attach නොකළ වෙනත් approver කෙනෙක් publication approve කළ යුතුයි. Terms edit කිරීම හෝ files එකතු/ඉවත් කිරීම pending approval එක supersede කරනවා. Bank identity වෙනසකටත් fresh proposal එකක් අවශ්‍යයි. Publication එක borrower identity/background snapshot කර terms/documents freeze කරනවා; material changes සඳහා cancellation සහ අලුත් request එකක් අවශ්‍යයි. Cancellation සඳහා independent approver කෙනෙක් සහ reason එකක් අවශ්‍යයි.

Verified lender makers/approvers ට eligible borrower banks වල open, unexpired requests කියවිය හැකියි. Default ලෙස platform operations ට treasury හෝ funding-document access නැහැ. සෑම download එකක්ම authorize සහ audit කර private, non-cacheable responses ලෙස ලබාදෙනවා.

## Blind offers සහ selection rules

Lender හෝ borrower බැංකුවක treasury makers settlement instructions propose කරන අතර එම බැංකුවේ වෙනත් approver කෙනෙක් සෑම immutable version එකක්ම approve කරනවා. Account/contact details සහ selected instruction snapshots database එකේ encrypt කර තිබෙනවා. මේ versions කියවිය හැක්කේ own-bank maker/approver users ට පමණයි. [Swift BIC policy](https://www2.swift.com/knowledgecentre/rest/v1/publications/bic_policy/_latest/bic_policy.pdf?logDownload=true) අනුව BIC syntax එක alphabetic country element එකක් සහිත අක්ෂර 8ක් හෝ 11ක් accept කරනවා. Account/routing verification institution එකේ approval process එකේ කොටසක් ලෙසම පවතිනවා. Active offer, pending selection හෝ open deal එකක් භාවිතා නොකරන පැරණි version එකක් approver කෙනෙකුට retire කළ හැකියි.

Offers published amount/currency/tenor සම්පූර්ණයෙන් cover කරනවා. Demo එක annual simple percentage rates decimal strings ලෙස, 0–100 අතර decimal places 4ක් දක්වා quote කරනවා; internally rates integer units භාවිතා කරනවා. Structured conditions `unsecured` / `sovereign_bonds` සහ interest payment `at_maturity` / `monthly`. Offer validity එක bidding deadline එක cover කර settlement day (UTC) එකට පෙර හෝ එදින අවසන් විය යුතුයි. Arbitrary lender text, contact fields සහ attachments borrower comparison එකෙන් exclude කර තිබෙනවා.

Draft edits pending offer approvals supersede කරන අතර publication contributors ට තමන්ගේම වැඩ approve කළ නොහැක. එක් lender bank එකකට request එකකට active offer එකක් පමණක් තිබිය හැකියි. Active terms immutable: withdraw කර replacement එකකට approval ලබාගන්න. Labels සහ random comparison tokens අයත් වන්නේ එක් request එකකට පමණයි; borrower payloads වල internal offer IDs, lender bank IDs, instruction IDs හෝ actors නැහැ. Lenders ට කියවිය හැක්කේ තමන්ගේ offers පමණයි. Own-bank admin/auditor users ට banking instruction fields exclude කර offer terms කියවිය හැකියි.

Borrower maker selection සහ independent borrower approval වෙනම actions දෙකක්. Proposal එක bank එකේ approved versions වලින් එකක් receiving account ලෙස සඳහන් කරන අතර validity විනාඩි 60කට වඩා අඩුවෙන් ඉතිරි offer එකක් තේරිය නොහැක. Approval එක request එක lock කර, banks දෙක, offer version/validity සහ approved instruction identity නැවත පරීක්ෂා කර, selected terms සහ බැංකු දෙකේම instructions snapshot කර losing offers close කරනවා. Unique database constraints මඟින් request එකකට එක් pending selection එකක් සහ එක් deal එකක් enforce කරනවා. Pending cancellation සහ selection proposals එකවර පැවතිය නොහැක. Selected lender ට borrower financial evidence access දිගටම තිබෙන අතර request එක close වූ පසු අනෙක් lenders ට එම access නැති වෙනවා.

## Demo fee payment සහ disclosure

අනුමත demo defaults අනුව borrower ගෙන් **principal එකෙන් 10 bps (0.1%)** loan currency එකෙන්, simulated tax 0 සමඟ අය කරනවා. Fees smallest currency unit දක්වා round up කරනවා. Payment window එක selection සිට පැය 24ක් වන අතර selected offer expiry එකට සීමා වේ. Amount, currency, deadline සහ policy deal එකේ freeze වෙනවා; පසුව configuration වෙනස් කළත් එය reprice වෙන්නේ නැහැ. මේවා demo rules මිස live commercial pricing නොවේ.

Offer එකක් select කිරීමට පෙර synthetic payments locally enable කරන්න:

~~~sh
php artisan payments:setup-demo
php artisan db:seed --class=DemoPaymentSeeder
~~~

Setup එක key එක print නොකර සහ unrelated settings වෙනස් නොකර `.env` එකේ private signing key එකක් සාදනවා. Payment seeder එක existing selected demo deals පමණක් quote කරනවා; bank decisions preserve කරන අතර payments successful ලෙස mark කරන්නේ නැහැ. `php artisan fees:quote-pending` වෙනත් existing reservations quote කරනවා; deadline එක ඒවායේ original selection time එකට anchor වෙනවා.

Borrower approver ලෙස sign in වී **Selected deals** open කර **Request demo fee payment** තෝරන්න. පෙන්වන `XFP-…` payment reference එක copy කර මෙය run කරන්න:

~~~sh
php artisan payments:simulate 'XFP-REPLACE-WITH-THE-DISPLAYED-REFERENCE'
~~~

Command එක fee-payment ID එකත් accept කරනවා. එය `POST /api/payments/demo/notifications` භාවිතා කරන එම authentication, validation සහ verification handler එක හරහාම synthetic signed notification එකක් යවනවා. Payment provider කෙනෙක් contact කිරීම හෝ මුදල් හුවමාරු කිරීම සිදු නොවේ. Verification පසු deal එක refresh කර participating bank එකක authorized treasury maker/approver ලෙස **View settlement details** open කරන්න. එහි lender ගේ account එක සහ borrower ගේ receiving account එක පෙන්වනවා. සාමාන්‍ය deal responses දිගටම lender identities සහ banking details exclude කරනවා; සෑම disclosure එකක්ම වෙනම authorize, audit කර non-cacheable ලෙස ලබාදෙනවා. Admin-only, auditor-only සහ platform accounts ට මේ view එක භාවිතා කළ නොහැක.

Payment එකක් initiate කළ හැක්කේ owning borrower approver කෙනෙකුට පමණයි. Amount සහ reference තීරණය කරන්නේ server එකයි; bank users හෝ operations ට fee එකක් paid ලෙස mark කිරීමට හෝ verification bypass කිරීමට නොහැක. Handler එක timestamp සහ raw payload signature authenticate කර, exact amount/currency/deal/reference සහ current bank/instruction eligibility validate කර, receipt එක verify කිරීම සහ deal unlock කිරීම atomically කරනවා. Late payment එකකින් cancelled reservations revive කළ නොහැක.

Failure cases පරීක්ෂා කිරීමට simulator එක `--outcome=failed`, `--amount=`, `--currency=`, `--deal=`, `--event=` සහ `--charge=` support කරනවා. මේවා ලබා නොදුන්නොත් සෑම invocation එකක්ම අලුත් event එකක් සහ charge එකක් සාදනවා. එක් delivery එකක් replay කිරීමට එම payload එක සමඟ එකම `--event` සහ `--charge` UUIDs නැවත භාවිතා කරන්න. Successful payment පසු අලුත් charge එකක් refund review අවශ්‍ය additional receipt එකක්; එය duplicate delivery එකක් නොවේ.

Operations ට `/operations/payments` හි payment exceptions review කළ හැකියි; detailed receipt access සඳහා recorded reason එකක් අවශ්‍යයි. Wrong, late, unmatched හෝ additional receipts refund review වෙත යනවා. Refund reference එකක් record කිරීම manual attestation එකක් පමණයි; එය කිසිවිටක refund transfer එකක් execute කරන්නේ නැහැ. Additional receipt එකක් refund කිරීමෙන් original verified payment එක ඉවත් නොවේ. Actual provider verification, reconciliation සහ refund execution තවම launch work ලෙස ඉතිරියි.

Payment එකට පෙර ඕනෑම බැංකුවක maker කෙනෙකුට එම බැංකුවේ independent approval සඳහා තම instructions වල වෙනත් approved version එකක් propose කළ හැකියි. Pending payment attempts හෝ refund reviews changes block කරන අතර pending changes payment block කරනවා. Approved changes පැරණි encrypted snapshot එක සහ fee/financial terms preserve කර identity-free notice එකක් දෙනවා. Unlock පසු changes සඳහා open dispute එකක් සහ එම බැංකුවේම independent review එකක් අවශ්‍යයි; correction එකක් approve කළාම previous confirmations supersede වන නිසා institutions දෙකම නැවත attest කළ යුතුයි.

## Settlement සහ disputes

Fee verification පසු, එක් එක් participating bank එකේ maker ට UTC transfer time, optional reference සහ institution note සමඟ settlement attestation එකක් propose කළ හැකියි. එම bank එකේ වෙනත් approver කෙනෙක් එය review කළ යුතුයි. References, notes සහ review reasons encrypt කර ඇති අතර ඒවා පෙනෙන්නේ එම bank එකේ treasury maker/approver users ට පමණයි. අනෙක් participants සහ admin/auditor users ට confirmation status පමණක් පෙනේ. Approval වෙලාවේදීත් proposing maker eligible විය යුතුයි.

එක් institution එකක් පමණක් approve කර ඇති විට deal එක `Unlocked` ලෙසම තිබෙනවා. Current instruction version එකට එරෙහිව independently approved confirmations දෙකක් ලැබුණු විට deal එක සහ funding request එක දෙකම atomically `Settled` ලෙස mark වෙනවා. Existing bank/request/deal locks යටතේ payment proof, bank eligibility සහ approved instructions නැවත පරීක්ෂා කරනවා. කිසිම browser හෝ operations action එකකට කුමන හෝ institution එකක approval එක ආදේශ කළ නොහැක.

Deal එක unlocked අතරතුර owning bank approver කෙනෙකුට issue එකක් open කළ හැකියි. එවිට deal එක `Disputed` වෙනවා. Institutions ට attestations දිගටම collect කළ හැකි නමුත් confirmations දෙකෙන් disputed deal එකක් ස්වයංක්‍රීයව finalize වෙන්නේ නැහැ. Operations එය `/operations/disputes` හි review කරනවා; evidence කියවීමට reason එකක් සහ audit record එකක් අවශ්‍යයි. `Settled` resolution එකකට approved confirmations දෙකම සහ current eligibility/instructions අවශ්‍යයි. නැත්නම් operations ට reason එකක් සහිත `ClosedUnsettled` outcome එකක් record කළ හැකියි; එය request එක cancel කර pending attestations සහ instruction reviews supersede කරනවා. මෙයින් කිසිම transfer එකක් execute හෝ reverse කරන්නේ නැති අතර platform fee එකක් refund කරන්නේත් නැහැ.

Existing paid demo deal එකක synthetic pending borrower attestation එකක් සකස් කිරීමට:

~~~sh
php artisan db:seed --class=DemoSettlementSeeder
~~~

මේ optional local/test seeder එක bank profiles සහ existing confirmations preserve කරනවා. එය fee එකක් ගෙවන්නේ, banks දෙකම confirm කරන්නේ හෝ completed deal එකක් reset කරන්නේ නැහැ. `borrower.approver@xfunding.example` ලෙස approve කර, පසුව `lender-three.maker@xfunding.example` ලෙස propose කර `lender-three.approver@xfunding.example` ලෙස approve කරන්න. වෙනත් offer එකක් තෝරා තිබේ නම් actual selected lender ගේ account එක භාවිතා කරන්න. සියලු references synthetic; මේ demonstration එකේ principal transfer එකක් සිදු නොවේ.

දැනට **Offer comparison walkthrough** example එකේ synthetic attestations දෙකම සහ audited dispute resolution එකක් සම්පූර්ණ කර තිබෙනවා. **Working capital liquidity** offers compare කිරීමට තවමත් ලබාගත හැකියි. සම්පූර්ණ workflow එක නැවත කිරීමට අලුත් request එකක් පටන්ගන්න; completed histories preserve වෙනවා.

**Notifications** යනු in-app database notifications. Pending confirmations එම bank එකේ අනෙක් approvers ට ද, rejections එහි makers ට ද, outcomes banks දෙකේ eligible staff ට ද notify කරනවා. Selection, fee verification සහ reservation closure ගැනත් banks දෙකේ eligible staff ට notify කරනවා. Reads සහ acknowledgements current recipient, bank, role සහ deal relationship නැවත පරීක්ෂා කරනවා. Messages සහ timelines වල ඇත්තේ fixed status text පමණයි; lender names, account/contact fields, actors, references හෝ private narratives නැහැ. Settlement emails සහ exports implement කර නැහැ. Deal timeline එක selection/fee milestones සහ paginated settlement events පෙන්වනවා.

## Workspace flow

Sign in වූ පසු **Home** විවෘත වෙනවා. Sign in වූ කෙනාට දැන් කළ යුතු දේ සහ වෙනත් අය ළඟ ඇති දේ, actions ආරක්ෂා කරන එම policies භාවිතයෙන්ම එහි පෙන්වනවා. එම ගණන සෑම page එකකම navigation එකේ පෙනෙනවා. Queue එකේ කිසිවක් borrower ට lender ගේ නම පෙන්වන්නේ නැහැ.

සෑම funding request, offer සහ deal එකකම progress එක සහ දැනට සිටින කෙනාට අදාළ ඊළඟ පියවර, එම card එකේම action එක සමඟ පෙන්වනවා. වෙනස් වූ record එකකට එරෙහිව submit කළ form එකක් හේතුව සමඟ එම page එකටම ආපසු එනවා; API clients ට තවමත් 409 ලැබෙනවා. Lender makers ට offer එකක් save කර එක පියවරකින් approval සඳහා යැවිය හැකියි. මුදල් group කරන්නේ කියවීමට පමණයි; API values නිවැරදි decimal strings ලෙසම පවතිනවා.

Screens තවමත් Blade සහ `public/app.css` හි static CSS; build step හෝ external assets නැහැ.

## Audit history, accounts සහ upgrades

Bank admins, auditors සහ platform operations ට bank page එකේ **Full audit history** (`/banks/{bank}/audit-events`, `/api/v1/banks/{bank}/audit-events`) විවෘත කළ හැකියි. එය paginated වන අතර action, actor, subject සහ වේලාව පෙන්වනවා; private reasons පෙන්වන්නේ නැහැ. Staff නම් පෙන්වන්නේ ඔවුන්ගේම institution එක තුළ පමණයි. Bank user කෙනෙකුගේ action එක අයත් වන්නේ ඔහුගේ හෝ ඇයගේ institution history එකට; ඒ නිසා lender ගේ document download හෝ settlement read එකක් පෙන්වන්නේ lender ට මිස borrower ට නොවේ. මේ rule එකට පෙර record වූ events වල original bank එක රැඳෙන අතර actor `Another institution` ලෙස පෙන්වනවා.

පළමු platform account එක server එකේ හදන්න; එය password setup link එකක් email කරනවා:

~~~sh
php artisan platform:create-admin operations@example.test 'Full Name'
~~~

ඉන්පසු platform admins `/operations/accounts` එකේ accounts කළමනාකරණය කරනවා. සෑම action එකක්ම reason එකක් record කරන අතර කිසිවෙකුට තමන්ගේම account එක deactivate කළ නොහැක. Bank admins ට team list එකෙන් revoke කළ සගයෙකු reinstate කිරීමට request කළ හැකියි; role change එකක් සඳහා මෙන්ම independent approval එකක් අවශ්‍යයි.

පවතින installation එකක් update කිරීමට .env සහ APP_KEY තබාගෙන මෙය run කරන්න:

~~~sh
lerd php artisan migrate --no-interaction
lerd php artisan db:seed --class=DemoMarketplaceSeeder
~~~

Migration එක retired `loan_applications` sample table එකද drop කරනවා. කලින් select වූ deals වල borrower receiving version එකක් නැහැ. Open deals pay හෝ settle කිරීමට borrower maker කෙනෙක් deal page එකේ version එකක් යෝජනා කර වෙනත් approver කෙනෙක් approve කළ යුතුයි. ඒවායේ lender disclosure තවදුරටත් ලැබෙන අතර closed deals වලට බලපෑමක් නැහැ.

## Tests

~~~sh
lerd php vendor/bin/pest --compact
lerd php vendor/bin/pest --configuration=phpunit.pgsql.xml --compact
lerd php vendor/bin/pint --dirty --format agent
~~~

Tests fake mail/storage සමඟ isolated PostgreSQL සහ in-memory SQLite databases භාවිතා කරනවා. ඒවා bank isolation, maker–checker approvals, private downloads, exact amounts, immutable snapshots, anonymous comparison, winner constraints, authenticated payment notifications, atomic unlock/settlement, restricted disclosure, refund review, corrective instruction changes, disputes, role-based notifications සහ bounded query counts cover කරනවා. නවතම PostgreSQL run එකේ **tests 522 / assertions 2,850 pass වුණා**. SQLite run එකේ tests 509 / assertions 2,782 pass වූ අතර PostgreSQL-only tests 13ක් skip වුණා. `RenderedFormsJourneyTest` browser එකක් මෙන් render වූ forms වල ඇති දේ පමණක් submit කරමින් Home page එක අනුව draft සිට settled දක්වා යනවා.

phpunit.xml in-memory SQLite database එකක් force කරනවා. phpunit.pgsql.xml local Lerd database එක වන xfunding_hub_testing force කරනවා; එය කිසිවිටක retained data වෙත point කරන්න එපා. Concurrency tests හිතාමතාම එම test database එක rebuild කරන නිසා --parallel නැතිව serially run කළ යුතුයි. ඒවා competing selections, duplicate/extra payment receipts සහ simultaneous settlement approvals සඳහා වෙනම PostgreSQL backend sessions synchronize කරනවා. සම්පූර්ණ HTTP journey එක real payment provider කෙනෙක් නැතිව possible lender winners දෙදෙනාම, refund review, disclosure සහ settlement පරීක්ෂා කරනවා.

Session-authenticated offer/instruction endpoints `/api/v1/offers` සහ `/api/v1/settlement-instructions`; selected deals සඳහා `/api/v1/deals/{deal}` භාවිතා කරනවා. Borrower comparison `GET /api/v1/funding-requests/{request}/offers`; selection proposals `offer_token` සහ `settlement_instruction_id` සමඟ `POST /api/v1/funding-requests/{request}/selection` භාවිතා කරනවා. Explicit review/withdrawal routes සඳහා `php artisan route:list` භාවිතා කරන්න.

Fee initiation සඳහා `POST /api/v1/deals/{deal}/fee-payments` ද, disclosure සඳහා `GET /api/v1/deals/{deal}/settlement-instructions` ද භාවිතා කරනවා. මේ routes session authentication සහ mutations සඳහා CSRF භාවිතා කරනවා. වෙනම demo notification endpoint එක browser session එකක් වෙනුවට timestamped HMAC signature එකක් භාවිතා කරන අතර demo configuration enable කළත් production වල refuse කරනවා.

Settlement proposals සඳහා `POST /api/v1/deals/{deal}/confirmations` ද, independent reviews සඳහා `POST /api/v1/deals/{deal}/confirmations/{confirmation}/review` ද භාවිතා කරනවා. Disputes සඳහා `POST /api/v1/deals/{deal}/disputes` භාවිතා කරනවා. Timeline සහ in-app feed සඳහා `GET /api/v1/deals/{deal}/timeline` සහ `GET /api/v1/notifications` භාවිතා කරනවා. Operations dispute review/resolution endpoints `/operations/disputes` යටතේ ඇති session-authenticated, CSRF-protected POST actions.

Session-authenticated endpoints අතර `GET /api/v1/me`, `GET /api/v1/banks/{bank}`, `/api/v1/funding-requests` සහ `GET /api/v1/marketplace` ඇතුළත්. Funding actions explicit submit/cancellation/approval routes භාවිතා කරනවා; browser/session mutations සඳහා CSRF අවශ්‍යයි. Public registration හෝ arbitrary status update endpoint එකක් සපයන්නේ නැහැ.

## Environment status සහ ඉතිරි launch work

App එක දැන් PostgreSQL 16.14 සමඟ Lerd හරහා **http://xfunding-hub.test** හි run වෙනවා. Port 8017 හි තිබුණු පෙර native preview එක නවතා තිබෙනවා. Lerd විසින් PostgreSQL, Redis සහ Mailpit connection values ද සැපයුවා; existing application key සහ bank data රැකුණා.

Browser checks මඟින් migrated settled deal, in-app feed සහ selected lender ගේ encrypted instructions තහවුරු කළා. Lerd requests 11ක් 80 ms median එකක් සමඟ capture කළ අතර slow routes නැහැ. එහි generic query fingerprints වෙනස් tables වල fixed queries කිහිපයක් එකට group කළා; raw SQL review සහ PostgreSQL query-count regressions මඟින් record-count-dependent N+1 queries හමු වුණේ නැහැ. මේවා local development samples මිස load test එකක් නොවේ.

PostgreSQL SQL export එකක් වෙනම temporary database එකකට restore කළා. Persistent tables 29ක counts සහ canonical row-content hashes match වුණා. පසුව recovery databases ඉවත් කළා. Private temporary export එකක් /tmp/xfunding-runtime/recovery-check/ යටතේ තිබෙනවා; එය rehearsal artifact එකක් මිස durable backup එකක් නොවේ. Production recovery plan එකකට private documents, original application key, off-host encrypted backups සහ tested recovery target එකක් ඇතුළත් විය යුතුයි.

Browser hardening headers යොදා තිබෙනවා; HTTPS deployments සඳහා `SESSION_SECURE_COOKIE=true` set කරන්න. Payment-provider selection තවම පසුවට තබා තිබෙනවා. Real payment verification/reconciliation/refunds, staff MFA, production secret සහ backup management, monitoring, retention rules, bank due diligence, platform terms සහ regulatory review තවම launch work ලෙස ඉතිරියි. Demo-ready යනු live-ready යන්න නොවේ.
