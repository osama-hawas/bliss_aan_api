<?php

namespace Tests\Feature\Foundation;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use RuntimeException;
use Tests\TestCase;

class ResponseEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $responder = new class
        {
            use ApiResponse {
                success as public;
            }
        };

        Route::middleware('api')->prefix('api/v1/_test')->group(function () use ($responder) {
            Route::get('/item', fn () => $responder->success(['id' => 1], 'Done'));
            Route::post('/created', fn () => $responder->success(new JsonResource(['id' => 2]), status: 201));
            Route::get('/list', fn () => $responder->success(JsonResource::collection(User::query()->paginate(2))));
            Route::post('/validate', fn (Request $r) => $r->validate(['name' => 'required']));
            Route::get('/state', fn () => throw new ApiException(ErrorCode::InvalidState));
            Route::get('/boom', fn () => throw new RuntimeException('secret internals'));
            Route::get('/private', fn () => 'ok')->middleware('auth:api');
        });
    }

    public function test_success_envelope(): void
    {
        $this->getJson('/api/v1/_test/item')
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => ['id' => 1], 'message' => 'Done', 'meta' => []]);

        $this->postJson('/api/v1/_test/created')
            ->assertCreated()
            ->assertJson(['success' => true, 'data' => ['id' => 2], 'message' => null]);
    }

    public function test_paginated_envelope(): void
    {
        User::factory()->count(3)->create();

        $this->getJson('/api/v1/_test/list')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['success', 'data', 'message', 'links' => ['first', 'last', 'prev', 'next'], 'meta'])
            ->assertJsonPath('meta', ['current_page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);
    }

    public function test_validation_error(): void
    {
        $this->postJson('/api/v1/_test/validate')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['message', 'errors' => ['name']]);
    }

    public function test_api_exception_uses_its_code_and_status(): void
    {
        $this->getJson('/api/v1/_test/state')
            ->assertStatus(409)
            ->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_not_found_and_method_not_allowed(): void
    {
        $this->getJson('/api/v1/_test/missing')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->deleteJson('/api/v1/_test/item')->assertStatus(405)->assertJsonPath('code', 'METHOD_NOT_ALLOWED');
    }

    public function test_server_error_hides_internals_without_debug(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/v1/_test/boom')
            ->assertStatus(500)
            ->assertJsonPath('code', 'SERVER_ERROR')
            ->assertJsonMissingPath('debug');

        $this->assertStringNotContainsString('secret internals', $response->getContent());
    }

    public function test_server_error_includes_debug_in_debug_mode(): void
    {
        config(['app.debug' => true]);

        $this->getJson('/api/v1/_test/boom')
            ->assertStatus(500)
            ->assertJsonPath('debug.message', 'secret internals');
    }

    public function test_missing_or_invalid_token_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/_test/private')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->withToken('not-a-jwt')->getJson('/api/v1/_test/private')->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_expired_token_is_reported_as_expired(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        $this->travel(config('jwt.ttl') + 1)->minutes();

        $this->withToken($token)->getJson('/api/v1/_test/private')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'TOKEN_EXPIRED');
    }

    public function test_blacklisted_token_is_unauthenticated(): void
    {
        $token = $this->tokenFor(User::factory()->create());
        JWTAuth::setToken($token)->invalidate();

        $this->withToken($token)->getJson('/api/v1/_test/private')->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
