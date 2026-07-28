<?php

namespace App\Policies;

use App\Models\FundingDocument;
use App\Models\FundingRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class FundingDocumentPolicy
{
    public function view(User $user, FundingDocument $document): Response
    {
        return (new FundingRequestPolicy)->view($user, $document->fundingRequest);
    }

    public function create(User $user, FundingRequest $fundingRequest): bool
    {
        return (new FundingRequestPolicy)->update($user, $fundingRequest);
    }

    public function delete(User $user, FundingDocument $document): bool
    {
        return $this->create($user, $document->fundingRequest);
    }
}
