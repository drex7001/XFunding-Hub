# 01 — Product scope

## අරමුණ

Wholesale liquidity අවශ්‍ය බැංකුවක්, ණය දීමට කැමති verified බැංකු සමඟ සම්බන්ධ කිරීම. Lenders ට borrower බැංකුවේ identity සහ අදාළ financial documents පෙනෙනවා. Borrower ට offer terms පෙනෙන නමුත් platform fee එක verify වන තුරු lender identity පෙනෙන්නේ නැහැ.

Platform එක loan principal එක තබාගන්නේ හෝ හුවමාරු කරන්නේ නැහැ. බැංකු දෙක තම නිල banking channels හරහා settlement කරනවා. Platform එක ගන්නේ තම සේවා ගාස්තුවයි; MVP එකේ escrow service එකක් සපයන්නේ නැහැ.

## පාර්ශ්වයන්

| පාර්ශ්වය | වගකීම |
|---|---|
| Borrower Bank | Financial profile ඉදිරිපත් කිරීම, request පළ කිරීම, offer තේරීම, fee ගෙවීම, settlement confirm කිරීම |
| Lender Bank | Borrower assess කිරීම, offer ඉදිරිපත් කිරීම, settlement instructions ලබාදීම, settlement confirm කිරීම |
| Platform Operations | Banks verify කිරීම, platform access පාලනය, fee exceptions සහ disputes හසුරුවීම |

Borrower/Lender යනු බැංකුවක business capabilities. Bank Admin/Maker/Approver/Auditor යනු එම බැංකුවේ users ගේ roles. එකම බැංකුවට capabilities දෙකම තිබිය හැකියි.

## MVP එකට ඇතුළත් දේ

- Bank onboarding, platform verification සහ invitation මඟින් staff එකතු කිරීම.
- Bank profiles, request එකට අදාළ audit reports සහ private document access.
- Funding requests: amount, currency, tenor, settlement date සහ bid deadline.
- Lenders කිහිපදෙනෙකුගෙන් offers; borrower සඳහා anonymous comparison.
- Maker–checker approvals සහ bank-scoped permissions.
- එක් offer එකක් තෝරා ගැනීම, platform fee record කිරීම සහ verified payment එකෙන් identity unlock කිරීම.
- දෙපාර්ශ්වයෙන් settlement confirmation, basic dispute handling සහ audit history.
- Bank dashboard එකේ borrower/lender views සහ සීමිත operations dashboard එකක්.

## Working defaults

මේවා implementation සරල කරගැනීමට යෝජිත defaults. වෙනස් කරනවා නම් ඒ වෙනස development පටන්ගන්න පෙර අදාළ tasks වලට ඇතුළත් කරන්න.

| කරුණ | MVP default |
|---|---|
| Offer coverage | Request එකේ සම්පූර්ණ amount එක, currency එක සහ tenor එක සඳහා offer එකක් |
| Winner | එක් request එකකට එක් lender bank එකක් |
| Fee payer | Borrower Bank |
| Offer selection | Borrower විසින් terms සසඳා තෝරයි; අඩුම rate එක ස්වයංක්‍රීයව තෝරන්නේ නැහැ |
| Application database | PostgreSQL; user decision 2026-10-16; local PostgreSQL 16.14. SQLite fast tests සහ retained source snapshot සඳහා පමණයි |
| Staff membership | Bank user account එකකට එක් බැංකුවක්; platform staff වෙනම accounts |
| Roles | Fixed roles; custom permission editor එකක් නැහැ |
| Approvals | Publish, bid submission, selection සහ settlement confirmation සඳහා වෙනත් user කෙනෙකුගේ approval |
| Initial payment demo | Test payment adapter එකක්; එය production payment verification එකක් ලෙස භාවිතා නොකරන්න |
| Approved demo fee | Principal එකෙන් 10 bps (0.1%); loan currency එකම; smallest currency unit දක්වා round up; simulated tax 0 |
| Approved demo payment window | Selection සිට පැය 24; selected offer expiry එකට සීමා වේ; quote/deadline/policy freeze වේ |
| Approved demo refund handling | Manual review සහ refund reference attestation; app එක refund transfer execute නොකරයි |
| Request validation demo | LKR/USD/GBP; decimal places 2; tenor දින 1–3,650; ACT/360 හෝ ACT/365F; annualized simple rate basis |
| Financial documents | Request එකකට අවම PDF එකක්; file එකකට උපරිම 10 MB; onboarding evidence වලින් වෙන්ව private storage |
| Deadlines | UTC; අනාගත bid deadline එක settlement date එකට පසුව විය නොහැක |
| Offer validation demo | Annual simple percentage rate 0–100; decimal places 4; full request amount/currency/tenor |
| Structured offer conditions | Unsecured හෝ sovereign bonds; interest payment at maturity හෝ monthly; lender free text/attachments නැහැ |
| Settlement instructions | Own lender treasury staff පමණක් කියවන encrypted, independently approved immutable versions |

## පසුව කරන දේ

Partial fills/syndicated loans, auctions with automatic matching, open chat, lender attachments before unlock, automated credit scoring, principal custody, direct RTGS/SWIFT integration, repayment servicing, SSO සහ multi-bank user accounts MVP එකෙන් පිටතයි.

## නිශ්චිත කරගත යුතු business decisions

අදියර 0–5 implementation එකේ bank access, funding requests, blind offers/selection, demo fee verification/disclosure, settlement attestations/disputes, in-app notifications සහ deal timeline තිබෙනවා. Laravel 13 / PHP 8.4+ monolith එක, Blade UI එක, local PostgreSQL application database, isolated PostgreSQL/SQLite test databases සහ database queue එක භාවිතා කරනවා. දැනට local demo එක Lerd PHP 8.4.25 / PostgreSQL 16.14 මත ක්‍රියාත්මකයි; පෙර native PHP 8.5 preview එක නවතා තිබෙනවා. Verification යනු authorized platform reviewer කෙනෙකු reason එකක් සමඟ කරන manual approval එකක්; real-bank due-diligence process එක තවම තීරණය කරලා නැහැ. Demo seed එකේ LK metadata සහ bank identities/documents synthetic data පමණයි. Request conventions ඉහත working defaults අනුව implement කරලා තිබෙනවා; ඒවා live business decisions ලෙස තහවුරු කළ බව අදහස් නොවේ.

Amounts database එකේ integer minor units ලෙසත් API එකේ decimal strings ලෙසත් තබනවා. Demo සීමාව `0.01` සිට `999999999999.99`; floating-point money භාවිතා කරන්නේ නැහැ. Offer rates integer units ලෙස තබා decimal strings ලෙස පෙන්වනවා; annualized simple percentage rate එකකට decimal places 4ක් තිබෙනවා. Legal interest calculation/servicing තවම MVP එකෙන් පිටතයි.

**Demo decision — 2026-10-16:** User විසින් ඉහත 10 bps, same-currency, පැය 24, tax 0 සහ manual refund-review defaults භාවිතා කිරීමට තහවුරු කළා. Fee base එක `ceil(principalMinor × 10 / 10000)` ලෙස integer arithmetic මඟින් ගණනය කරනවා. Quote එක selection transaction එකේ freeze කර approved policy version එක snapshot කරනවා. Existing selected deals සඳහා quote කළත් deadline එක original selection time එකෙන් ගණනය කරනවා. Test adapter එක local/testing තුළ explicit enable කළ විට පමණක් ක්‍රියාත්මක වේ; production වල unlock නොකරයි. Real pricing, tax සහ refund obligations තවම තහවුරු කර නැහැ.

Settlement confirmations යනු banks දෙකේ independently approved attestations; platform transfer verification නොවේ. References/notes encrypted සහ own treasury staff ට පමණයි. In-app notifications generic status text වලට සීමා වේ; email notifications/exports මේ implementation එකට ඇතුළත් නැහැ. Dispute තුළ instruction correction කළොත් old evidence preserve කර confirmations supersede කරනවා; නැවත දෙපාර්ශ්වයේ approval අවශ්‍යයි.

| තීරණය | අවශ්‍ය වන වෙලාව |
|---|---|
| Launch market, eligible banks, currencies, verification requirements සහ tenor/rate conventions | Real banks සමඟ onboarding/request validation භාවිතා කිරීමට පෙර; demo defaults ඉහත දක්වා ඇත |
| Live fee formula/currency, taxes, payment window, refunds සහ failed-deal rules | Real payment flow සක්‍රිය කිරීමට පෙර; demo defaults පමණක් ඉහත අනුමත කර ඇත |
| Payment provider හෝ independently verified bank-transfer process | Production payment integration කිරීමට පෙර |
| Terms, anti-circumvention agreement, document retention සහ jurisdiction-specific regulatory review | Real banks/data සමඟ live භාවිතයට පෙර |

Non-custodial යන්න මේ product එකේ මුදල් හසුරුවන සීමාවයි. Licensing හෝ වෙනත් regulatory obligations ගැන නිගමනයක් මේ documents තුළ කරලා නැහැ.

Borrower identity lenders ට පෙනෙන නිසා lenders විසින් සෘජුව borrower අමතන හැකියාව identity hiding එකෙන් පමණක් වළක්වන්න බැහැ. Platform agreements සහ operational rules ද business design එකේ කොටසක් විය යුතුයි.

**Database decision — 2026-10-16:** User විසින් PostgreSQL තෝරා payment verification ක්‍රමය/provider තීරණය පසුවට තැබුවා. Existing user-edited bank profiles, memberships, documents, encrypted fee/deal/settlement evidence සහ notifications local PostgreSQL වෙත migrate කර තිබෙනවා. Source SQLite file එක unchanged ලෙස රැකෙනවා. Separate PostgreSQL sessions වල selection/payment/settlement races සහ isolated SQL backup restore verify කළා. Production hosting, MFA, key/backup management සහ retention තවම pending.

## MVP සාර්ථක බව පෙන්වන scenario එක

Verified borrower bank එක request එකක් publish කරනවා. Verified lender banks දෙකක් offers දානවා. Borrower terms සසඳන අතර lender identities නොපෙනේ. Approver කෙනෙක් එක් offer එකක් select කරනවා. Fee verify වුණාම එම lender ගේ විස්තර පමණක් unlock වෙනවා. දෙපාර්ශ්වයම settlement confirm කළාම deal එක settled වෙනවා.
