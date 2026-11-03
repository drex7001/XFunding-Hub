# XFunding Hub — MVP work plan

**Language:** English · [Singlish (සිංහල)](README.si.md)

**Status:** Phases 0–5 implemented; Phase 6 demo verification complete, live preparation remaining · **Date:** 2026-10-16

**Implementation:** Foundation, bank access, funding requests, blind offers/selection, demo fee verification/disclosure and settlement attestations/disputes from both parties are implemented. Role-based in-app notifications, a deal timeline, borrower receiving instructions and a role-aware Home page are also in place. See the [project README](../README.md) for run instructions. PostgreSQL was chosen, the local app/history was migrated, and concurrent database sessions and a backup restore were verified. Actual payment integration, MFA and production operational checks are still pending.

These documents describe what the first version of the B2B interbank liquidity marketplace needs and the order in which to build it. The models, endpoints, statuses and database schema of the test application currently in the repository are not requirements of this plan.

## Reading order

| Order | Document | Question it answers |
|---|---|---|
| 1 | [Product scope](01-product-scope.md) | What are we building? What is included in the first version? |
| 2 | [Access control](02-access-control.md) | Who, at which bank, can do and see what? |
| 3 | [Marketplace workflow](03-marketplace-workflow.md) | How does a transaction move from request to settlement? |
| 4 | [Delivery plan](04-delivery-plan.md) | What comes first? How do we know each phase is complete? |

## How to use these

1. Review the working defaults in the scope. User approval is recorded for the demo fee defaults; do not treat them as live pricing decisions.
2. Work through the delivery plan phases in order. Do not consider the next phase complete until its dependency is finished.
3. Update the checklist once a phase's tasks and acceptance checks are complete.
4. If a requirement changes, update only the relevant document and its related tasks.
5. Every document has two versions: English (`X.md`) and Singlish (`X.si.md`). When you change one, update the other in the same change.

## Working boundaries

- First, one complete flow: verified banks → request → blind offers → selection → verified fee → identity unlock → settlement confirmations from both parties.
- Enforce access control and anonymity in the backend from the start.
- Keep the MVP on one application and one database. Microservices or a separate workflow engine are not needed at this stage.
- Spec-kit installation, generators or additional process documents are not needed for this plan.
- This checklist is for implementation. Preparing documents does not make the application implemented or production-ready.
