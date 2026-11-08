<?php

namespace App\Http\Controllers\Api\V1\Admin\Providers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Providers\AddNoteRequest;
use App\Http\Resources\Api\V1\Admin\Providers\ApplicationDetailResource;
use App\Models\Provider;
use App\Services\Providers\ProviderReview;
use App\Traits\ApiResponse;

class AddNoteController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderReview $review) {}

    public function __invoke(AddNoteRequest $request, Provider $provider)
    {
        $this->review->addNote($provider, $request->user(), $request->validated('note_to_provider'), $request->validated('internal_note'));

        return $this->success(new ApplicationDetailResource(ApplicationDetailResource::load($provider->refresh())), __('Note added.'), 201);
    }
}
