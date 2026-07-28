<?php

namespace App\Http\Controllers;

use App\Actions\ManageFundingRequests;
use App\Actions\RecordAuditEvent;
use App\Http\Requests\StoreFundingDocumentRequest;
use App\Models\FundingDocument;
use App\Models\FundingRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FundingDocumentController extends Controller
{
    public function store(StoreFundingDocumentRequest $request, FundingRequest $fundingRequest, ManageFundingRequests $action): RedirectResponse
    {
        $action->addDocument($request->user(), $fundingRequest, $request->file('document'));

        return redirect()->route('funding.show', $fundingRequest)->with('status', 'Financial PDF added. Publication requires approval of the current document set.');
    }

    public function show(Request $request, FundingDocument $document, RecordAuditEvent $audit): StreamedResponse
    {
        $document->load('fundingRequest.bank');
        Gate::authorize('view', $document);
        abort_unless(Storage::disk('private')->exists($document->path), 404);
        $audit->handle($request->user(), 'funding.document_downloaded', $document->fundingRequest, ['document_id' => $document->id]);

        return Storage::disk('private')->download($document->path, $document->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Request $request, FundingDocument $document, ManageFundingRequests $action): RedirectResponse
    {
        Gate::authorize('view', $document);
        $id = $document->funding_request_id;
        $action->removeDocument($request->user(), $document);

        return redirect()->route('funding.show', $id)->with('status', 'Document removed. Any previous publication proposal was superseded.');
    }
}
