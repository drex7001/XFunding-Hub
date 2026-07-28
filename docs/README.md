# XFunding Hub — MVP වැඩ සැලැස්ම

**තත්ත්වය:** අදියර 0–5 implemented; අදියර 6 demo verification අවසන්, live preparation ඉතිරියි · **දිනය:** 2026-10-16

**Implementation:** Foundation, bank access, funding requests, blind offers/selection, demo fee verification/disclosure සහ දෙපාර්ශ්වයේ settlement attestations/disputes implement කරලා තිබෙනවා. Role-based in-app notifications සහ deal timeline එකත් තිබෙනවා. Run instructions සඳහා [project README](../README.md) බලන්න. PostgreSQL තෝරා local app/history migrate කර concurrent database sessions සහ backup restore verify කළා. Actual payment integration, MFA සහ production operational checks තවම pending.

මේ documents වලින් B2B interbank liquidity marketplace එකේ පළමු version එකට අවශ්‍ය දේ සහ ඒවා කරන පිළිවෙළ විස්තර කරනවා. දැනට repository එකේ තිබෙන test application එකේ models, endpoints, statuses හෝ database schema මේ සැලැස්මේ requirements නොවේ.

## කියවන පිළිවෙළ

| අනුපිළිවෙළ | Document | පිළිතුරු දෙන ප්‍රශ්නය |
|---|---|---|
| 1 | [Product scope](01-product-scope.md) | අපි හදන්නේ මොකක්ද? පළමු version එකට මොනවාද ඇතුළත්? |
| 2 | [Access control](02-access-control.md) | කාටද, කුමන බැංකුවේ, මොන දේ කරන්න සහ බලන්න පුළුවන්? |
| 3 | [Marketplace workflow](03-marketplace-workflow.md) | Request එකේ සිට settlement දක්වා ගනුදෙනුව යන්නේ කොහොමද? |
| 4 | [Delivery plan](04-delivery-plan.md) | මුලින් කරන්නේ මොකක්ද? එක් එක් අදියර අවසන් බව දැනගන්නේ කොහොමද? |

## මේවා භාවිතා කරන හැටි

1. Scope එකේ working defaults බලන්න. Fee demo defaults සඳහා user approval record කර තිබෙනවා; ඒවා live pricing decisions ලෙස සලකන්න එපා.
2. Delivery plan එකේ අදියර පිළිවෙළට කරන්න. Dependency එක අවසන් නොකර ඊළඟ අදියර සම්පූර්ණ යැයි සලකන්න එපා.
3. එක් අදියරක tasks සහ acceptance checks අවසන් වූ පසු checklist එක update කරන්න.
4. Requirement එකක් වෙනස් වුණොත් අදාළ document එක සහ ඒකට සම්බන්ධ tasks පමණක් update කරන්න.

## වැඩ කරන සීමාව

- පළමුව එක සම්පූර්ණ flow එකක්: verified banks → request → blind offers → selection → verified fee → identity unlock → දෙපාර්ශ්වයේ settlement confirmations.
- Access control සහ anonymity මුල සිටම backend එකෙන් enforce කරන්න.
- එක් application එකක් සහ එක් database එකක් මත MVP එක තබන්න. Microservices හෝ වෙනම workflow engine එකක් මේ අදියරට අවශ්‍ය නැහැ.
- Spec-kit installation, generators හෝ අමතර process documents මේ සැලැස්මට අවශ්‍ය නැහැ.
- මේ checklist එක implementation සඳහායි. Documents සකස් වීමෙන් application එක implemented හෝ production-ready වෙන්නේ නැහැ.
