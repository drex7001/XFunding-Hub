<?php

namespace App\Actions;

use App\BankRole;
use App\BankStatus;
use App\DealStatus;
use App\FundingRequestStatus;
use App\Models\AccessRequest;
use App\Models\Bank;
use App\Models\Deal;
use App\Models\DealDispute;
use App\Models\FeePayment;
use App\Models\FundingRequest;
use App\Models\Offer;
use App\Models\PaymentReceipt;
use App\Models\SettlementConfirmation;
use App\Models\SettlementInstruction;
use App\Models\User;
use App\OfferStatus;
use App\PaymentReceiptStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Tells each person what is waiting for them, using the same policies that guard the actions.
 * Borrower-facing items describe deals through their request and label, never the lender.
 */
class BuildWorkQueue
{
    private const LIMIT = 50;

    public function __construct(private VisibleDealNotifications $notifications) {}

    /** @return array{actions: array<int, array<string, mixed>>, waiting: array<int, array<string, mixed>>, setup: array<int, array<string, mixed>>} */
    public function for(User $user): array
    {
        return $user->is_platform_admin ? $this->forOperations() : $this->forBankStaff($user);
    }

    public function unreadNotifications(User $user): int
    {
        return $user->hasVerifiedBankAccess() ? $this->notifications->for($user)->whereNull('read_at')->count() : 0;
    }

    /** @return array{actions: array<int, array<string, mixed>>, waiting: array<int, array<string, mixed>>, setup: array<int, array<string, mixed>>} */
    private function forOperations(): array
    {
        $actions = [];
        foreach (Bank::query()->where('status', BankStatus::Pending)->withCount('documents')->oldest()->limit(self::LIMIT)->get() as $bank) {
            $actions[] = $this->item('Verify institution', $bank->name, $bank->documents_count.' onboarding files', route('banks.show', $bank), 'Review', $bank->updated_at);
        }
        foreach (AccessRequest::query()->where('status', 'pending')->with('bank')->oldest()->limit(self::LIMIT)->get() as $request) {
            $actions[] = $this->item('Review access request', $request->name, $request->bank->name.' · '.str_replace('_', ' ', $request->kind), route('banks.show', $request->bank).'#access', 'Review', $request->created_at);
        }
        foreach (DealDispute::query()->where('status', 'open')->oldest()->limit(self::LIMIT)->get() as $dispute) {
            $actions[] = $this->item('Resolve settlement dispute', 'Deal '.$this->reference($dispute->deal_id), 'Reading the evidence requires an audited reason', route('disputes.operations'), 'Open', $dispute->created_at);
        }
        foreach (PaymentReceipt::query()->where('status', PaymentReceiptStatus::RefundReview)->oldest('received_at')->limit(self::LIMIT)->get() as $receipt) {
            $actions[] = $this->item('Review payment exception', $receipt->currency.' '.FundingRequest::groupDecimal($receipt->amount), ucfirst(str_replace('_', ' ', (string) $receipt->reason)), route('payments.operations'), 'Open', $receipt->received_at);
        }

        return ['actions' => $this->ordered($actions), 'waiting' => [], 'setup' => []];
    }

    /** @return array{actions: array<int, array<string, mixed>>, waiting: array<int, array<string, mixed>>, setup: array<int, array<string, mixed>>} */
    private function forBankStaff(User $user): array
    {
        $queue = ['actions' => [], 'waiting' => [], 'setup' => []];
        $bank = $user->membership->bank;
        if ($user->hasBankRole(BankRole::Admin)) {
            $this->addAccessRequests($user, $queue);
            if ($bank->status === BankStatus::Pending) {
                $queue['setup'][] = $this->item('Complete onboarding', 'Institution verification', 'Upload your verification documents. Platform operations then verifies the profile and approves capabilities.', route('banks.show', $bank), 'Open bank profile', $bank->updated_at);
            }
        } elseif ($bank->status === BankStatus::Pending) {
            $queue['setup'][] = $this->item('Institution verification is pending', 'Institution verification', 'Marketplace, funding and deal screens open once platform operations verifies your bank.', route('banks.show', $bank), 'Open bank profile', $bank->updated_at);
        }
        if (! $user->hasVerifiedBankAccess()) {
            return $this->finished($queue);
        }
        if ($bank->can_borrow) {
            $this->addFundingRequests($user, $queue);
        }
        if ($bank->can_lend) {
            $this->addOffers($user, $queue);
        }
        if ($user->canManageSettlementInstructions()) {
            $this->addInstructions($user, $queue);
        }
        $this->addDeals($user, $queue);

        return $this->finished($queue);
    }

    /** @param array<string, array<int, array<string, mixed>>> $queue */
    private function addAccessRequests(User $user, array &$queue): void
    {
        $requests = AccessRequest::query()->where('bank_id', $user->membership->bank_id)->where('status', 'pending')->with(['bank', 'membership'])->oldest()->limit(self::LIMIT)->get();
        foreach ($requests as $request) {
            $row = $this->item('Approve access request', $request->name, str_replace('_', ' ', ucfirst($request->kind)), route('banks.show', $user->membership->bank_id).'#access', 'Review', $request->created_at);
            if (Gate::forUser($user)->allows('review', $request)) {
                $queue['actions'][] = $row;
            } else {
                $queue['waiting'][] = ['note' => 'Waiting for another authorized reviewer'] + $row;
            }
        }
    }

    /** @param array<string, array<int, array<string, mixed>>> $queue */
    private function addFundingRequests(User $user, array &$queue): void
    {
        $isMaker = $user->hasBankRole(BankRole::Maker);
        $isApprover = $user->hasBankRole(BankRole::Approver);
        $requests = FundingRequest::query()->where('bank_id', $user->membership->bank_id)
            ->whereIn('status', [FundingRequestStatus::Draft, FundingRequestStatus::PendingApproval, FundingRequestStatus::Open])
            ->with(['pendingApproval', 'pendingSelection.offer'])
            ->withCount(['documents', 'offers as active_offers_count' => fn (Builder $offers): Builder => $offers->where('status', OfferStatus::Active)->where('valid_until', '>', now())])
            ->latest()->limit(self::LIMIT)->get();
        foreach ($requests as $request) {
            if ($request->currentStatus() === FundingRequestStatus::Expired) {
                continue;
            }
            $show = route('funding.show', $request);
            $compare = route('funding.offers', $request);
            $approval = $request->pendingApproval;
            $selection = $request->pendingSelection;
            if ($approval !== null) {
                $task = $approval->kind === 'publish' ? 'Approve publication' : 'Approve cancellation';
                $row = $this->item($task, $request->title, $this->amount($request), $show, 'Review', $approval->created_at, $request->bidding_deadline);
                if (Gate::forUser($user)->allows('review', $request)) {
                    $queue['actions'][] = $row;
                } else {
                    $queue['waiting'][] = ['note' => 'Waiting for an independent treasury approver'] + $row;
                }
            } elseif ($selection !== null) {
                $row = $this->item('Approve offer selection', $request->title, 'Offer '.$selection->offer->label_number.' · '.$selection->offer->rate.'% · '.$this->amount($request), $compare, 'Review', $selection->created_at, $request->bidding_deadline);
                if ($isApprover && $selection->proposed_by !== $user->id) {
                    $queue['actions'][] = $row;
                } else {
                    $queue['waiting'][] = ['note' => 'Waiting for an independent treasury approver'] + $row;
                }
            } elseif ($request->status === FundingRequestStatus::Draft && $isMaker) {
                $ready = $request->documents_count > 0;
                $queue['actions'][] = $this->item($ready ? 'Submit for approval' : 'Attach financial documents', $request->title, $this->amount($request), $show, $ready ? 'Open draft' : 'Add files', $request->updated_at);
            } elseif ($request->status === FundingRequestStatus::Open && $isMaker && $request->active_offers_count > 0) {
                $queue['actions'][] = $this->item('Compare offers', $request->title, $request->active_offers_count.' available · '.$this->amount($request), $compare, 'Compare', $request->published_at, $request->bidding_deadline);
            }
        }
    }

    /** @param array<string, array<int, array<string, mixed>>> $queue */
    private function addOffers(User $user, array &$queue): void
    {
        $offers = Offer::query()->where('bank_id', $user->membership->bank_id)->whereIn('status', [OfferStatus::Draft, OfferStatus::PendingApproval])
            ->with(['fundingRequest', 'pendingApproval'])->latest()->limit(self::LIMIT)->get();
        foreach ($offers as $offer) {
            if ($offer->fundingRequest->currentStatus() !== FundingRequestStatus::Open || ! $offer->valid_until->isFuture()) {
                continue;
            }
            $detail = $offer->rate.'% · '.$offer->fundingRequest->borrower_profile['name'];
            $title = $offer->fundingRequest->title;
            if ($offer->status === OfferStatus::Draft) {
                if ($user->hasBankRole(BankRole::Maker)) {
                    $queue['actions'][] = $this->item('Send offer for approval', $title, $detail, route('offers.show', $offer), 'Open offer', $offer->updated_at, $offer->fundingRequest->bidding_deadline);
                }

                continue;
            }
            $row = $this->item('Approve offer', $title, $detail, route('offers.show', $offer), 'Review', $offer->updated_at, $offer->fundingRequest->bidding_deadline);
            if (Gate::forUser($user)->allows('review', $offer)) {
                $queue['actions'][] = $row;
            } else {
                $queue['waiting'][] = ['note' => 'Waiting for an independent treasury approver'] + $row;
            }
        }
    }

    /** @param array<string, array<int, array<string, mixed>>> $queue */
    private function addInstructions(User $user, array &$queue): void
    {
        $instructions = SettlementInstruction::query()->where('bank_id', $user->membership->bank_id)->whereIn('status', ['pending', 'approved'])->with('bank')->latest('version')->limit(self::LIMIT)->get();
        foreach ($instructions->where('status', 'pending') as $instruction) {
            $row = $this->item('Approve settlement instructions', 'Version '.$instruction->version, strtoupper($instruction->details['channel']), route('instructions.show', $instruction), 'Review', $instruction->created_at);
            if (Gate::forUser($user)->allows('review', $instruction)) {
                $queue['actions'][] = $row;
            } else {
                $queue['waiting'][] = ['note' => 'Waiting for an independent treasury approver'] + $row;
            }
        }
        $hasCurrentVersion = $instructions->where('status', 'approved')->contains(fn (SettlementInstruction $record): bool => $record->matchesBankProfile());
        if (! $hasCurrentVersion && $instructions->where('status', 'pending')->isEmpty() && $user->hasBankRole(BankRole::Maker)) {
            $queue['setup'][] = $this->item('Add settlement instructions', 'Approved banking details', 'Offers and selections need an approved account version. Propose one for independent approval.', route('instructions.index'), 'Manage instructions', now());
        }
    }

    /** @param array<string, array<int, array<string, mixed>>> $queue */
    private function addDeals(User $user, array &$queue): void
    {
        $bankId = $user->membership->bank_id;
        $deals = Deal::query()->where(fn (Builder $query): Builder => $query->where('borrower_bank_id', $bankId)->orWhere('lender_bank_id', $bankId))
            ->whereIn('status', [DealStatus::AwaitingFee, DealStatus::Unlocked, DealStatus::Disputed])
            ->withWorkflowContext()->latest()->limit(self::LIMIT)->get();
        foreach ($deals as $deal) {
            if ($deal->currentStatus() === DealStatus::Cancelled) {
                continue;
            }
            $side = $deal->sideOf($bankId);
            $title = $deal->fundingRequest->title;
            $detail = $deal->labelFor($bankId).' · '.$deal->terms['rate'].'% · '.$deal->terms['currency'].' '.FundingRequest::groupDecimal($deal->terms['amount']);
            $url = route('deals.show', $deal);
            $step = $this->dealStep($user, $deal, $side);
            $row = $this->item($step['task'], $title, $detail, $url, $step['cta'] ?? 'Open deal', $deal->updated_at, $step['due'] ?? null);
            if ($step['action']) {
                $queue['actions'][] = $row;
            } else {
                $queue['waiting'][] = ['note' => $step['note']] + $row;
            }
        }
    }

    /** @return array{task: string, action: bool, note?: string, cta?: string, due?: mixed} */
    private function dealStep(User $user, Deal $deal, string $side): array
    {
        $isMaker = $user->hasBankRole(BankRole::Maker);
        $isApprover = $user->hasBankRole(BankRole::Approver);
        $change = $deal->pendingInstructionChange;
        if ($change !== null) {
            return $change->side === $side && Gate::forUser($user)->allows('review', $change)
                ? ['task' => 'Review instruction update', 'action' => true, 'cta' => 'Review']
                : ['task' => 'Instruction update', 'action' => false, 'note' => 'Waiting for an independent instruction review'];
        }
        if ($deal->status === DealStatus::AwaitingFee) {
            return $this->feeStep($user, $deal, $side);
        }
        if ($side === 'borrower' && $deal->borrower_settlement_instruction_id === null && $deal->status === DealStatus::Unlocked) {
            return $isMaker
                ? ['task' => 'Add receiving instructions', 'action' => true, 'cta' => 'Add']
                : ['task' => 'Receiving instructions', 'action' => false, 'note' => 'Waiting for a treasury maker to add the receiving account'];
        }
        $own = $deal->confirmations->first(fn (SettlementConfirmation $record): bool => $record->bank_id === $user->membership->bank_id && in_array($record->status, ['pending', 'approved'], true));
        if ($own?->status === 'pending') {
            return $isApprover && $own->proposed_by !== $user->id
                ? ['task' => 'Review settlement confirmation', 'action' => true, 'cta' => 'Review']
                : ['task' => 'Settlement confirmation', 'action' => false, 'note' => 'Waiting for an independent treasury approver'];
        }
        if ($own === null && $isMaker && $deal->settlementReady()) {
            return ['task' => $side === 'lender' ? 'Send principal and confirm' : 'Confirm principal received', 'action' => true, 'cta' => 'Open deal'];
        }
        if ($deal->status === DealStatus::Disputed) {
            return ['task' => 'Settlement dispute', 'action' => false, 'note' => 'Under platform operations review'];
        }

        return ['task' => 'Settlement', 'action' => false, 'note' => $own === null ? 'Waiting for your treasury team to confirm' : 'Waiting for the other institution to confirm'];
    }

    /** @return array{task: string, action: bool, note?: string, cta?: string, due?: mixed} */
    private function feeStep(User $user, Deal $deal, string $side): array
    {
        if ($side === 'lender') {
            return ['task' => 'Platform fee', 'action' => false, 'note' => 'Waiting for the borrower to pay the platform fee', 'due' => $deal->fee_deadline];
        }
        if ($deal->borrower_settlement_instruction_id === null) {
            return $user->hasBankRole(BankRole::Maker)
                ? ['task' => 'Add receiving instructions', 'action' => true, 'cta' => 'Add', 'due' => $deal->fee_deadline]
                : ['task' => 'Receiving instructions', 'action' => false, 'note' => 'Waiting for a treasury maker to add the receiving account'];
        }
        if ($deal->isReadyForPayment() && Gate::forUser($user)->allows('create', [FeePayment::class, $deal])) {
            return ['task' => 'Pay platform fee', 'action' => true, 'cta' => 'Pay', 'due' => $deal->fee_deadline];
        }

        return ['task' => 'Platform fee', 'action' => false, 'due' => $deal->fee_deadline, 'note' => match (true) {
            $deal->hasPendingRefund() => 'A received payment is under operations review',
            $deal->hasPendingPayment() => 'Payment requested; waiting for provider confirmation',
            default => 'Waiting for a treasury approver to pay the fee',
        }];
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $queue
     * @return array{actions: array<int, array<string, mixed>>, waiting: array<int, array<string, mixed>>, setup: array<int, array<string, mixed>>}
     */
    private function finished(array $queue): array
    {
        return ['actions' => $this->ordered($queue['actions']), 'waiting' => $this->ordered($queue['waiting']), 'setup' => $queue['setup']];
    }

    /**
     * Items with a deadline come first, soonest on top; the rest follow oldest first.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function ordered(array $items): array
    {
        usort($items, function (array $first, array $second): int {
            return [$first['due'] === null, $first['due']?->getTimestamp() ?? $first['at']->getTimestamp()]
                <=> [$second['due'] === null, $second['due']?->getTimestamp() ?? $second['at']->getTimestamp()];
        });

        return $items;
    }

    /** @return array<string, mixed> */
    private function item(string $task, string $title, string $detail, string $url, string $cta, mixed $at, mixed $due = null): array
    {
        return ['task' => $task, 'title' => $title, 'detail' => $detail, 'url' => $url, 'cta' => $cta, 'at' => $at, 'due' => $due];
    }

    private function amount(FundingRequest $request): string
    {
        return $request->currency.' '.FundingRequest::groupDecimal($request->amount);
    }

    private function reference(string $id): string
    {
        return strtoupper(substr($id, -8));
    }
}
