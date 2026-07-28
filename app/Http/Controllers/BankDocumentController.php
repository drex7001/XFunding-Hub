<?php

namespace App\Http\Controllers;

use App\Actions\RecordAuditEvent;
use App\Http\Requests\StoreDocumentRequest;
use App\Models\Bank;
use App\Models\BankDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BankDocumentController extends Controller
{
    public function store(StoreDocumentRequest $request, Bank $bank, RecordAuditEvent $audit): RedirectResponse
    {
        $file = $request->file('document');
        $path = $file->store('onboarding/'.$bank->id, 'private');
        try {
            DB::transaction(function () use ($request, $bank, $audit, $file, $path): void {
                $bank = Bank::query()->lockForUpdate()->findOrFail($bank->id);
                Gate::authorize('create', [BankDocument::class, $bank]);
                $document = $bank->documents()->create(['uploaded_by' => $request->user()->id, 'original_name' => $file->getClientOriginalName(), 'path' => $path, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize()]);
                $audit->handle($request->user(), 'document.uploaded', $document);
            });
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }

        return back()->with('status', 'Document stored privately for onboarding review.');
    }

    public function show(Request $request, BankDocument $document, RecordAuditEvent $audit): StreamedResponse
    {
        Gate::authorize('view', $document);
        $context = [];
        if ($request->user()->is_platform_admin) {
            $context = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:500']]);
        }
        abort_unless(Storage::disk('private')->exists($document->path), 404);
        $audit->handle($request->user(), 'document.downloaded', $document, $context);

        return Storage::disk('private')->download($document->path, $document->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
