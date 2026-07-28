<?php

namespace App\Http\Controllers;

use App\Http\Resources\DealEventResource;
use App\Models\Deal;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class DealTimelineController extends Controller
{
    public function __invoke(Deal $deal): JsonResponse
    {
        Gate::authorize('view', $deal);
        $events = DealEventResource::collection($deal->events()->latest('id')->paginate(25))->response()->getData(true);

        return response()->json(['milestones' => DealEventResource::milestones($deal)] + $events);
    }
}
