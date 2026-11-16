<?php

namespace Tests\Feature\Notifications;

use App\Integrations\Push\FcmCredentials;
use App\Integrations\Push\FcmPushNotifier;
use App\Integrations\Push\PushMessage;
use App\Integrations\Push\PushNotifier;
use App\Integrations\Push\PushResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FCM HTTP v1 driver (D139) against a faked HTTP layer. This is **not** a real Firebase delivery test: no credentials
 * or Firebase project exist in this environment.
 */
class FcmPushNotifierTest extends TestCase
{
    private function driver(): FcmPushNotifier
    {
        return new FcmPushNotifier(new class extends FcmCredentials
        {
            public function projectId(): string
            {
                return 'demo-project';
            }

            public function accessToken(): string
            {
                return 'oauth-token';
            }
        });
    }

    private function message(): PushMessage
    {
        return new PushMessage('device-token', 'New offer', 'Noura Lens sent you an offer.', ['event' => 'offer_received', 'target_type' => 'offer', 'target_id' => '12'], 'offer_received-abc');
    }

    private function fcmError(int $status, string $code, string $message = 'x'): array
    {
        return ['error' => ['code' => $status, 'message' => $message, 'status' => $status === 404 ? 'NOT_FOUND' : 'INVALID_ARGUMENT',
            'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => $code]]]];
    }

    public function test_success_sends_the_v1_message(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/demo-project/messages/1'])]);

        $this->assertSame(PushResult::Sent, $this->driver()->send($this->message()));

        Http::assertSent(function (Request $r) {
            $m = $r['message'];

            return $r->url() === 'https://fcm.googleapis.com/v1/projects/demo-project/messages:send'
                && $r->hasHeader('Authorization', 'Bearer oauth-token')
                && $m['token'] === 'device-token'
                && $m['notification'] === ['title' => 'New offer', 'body' => 'Noura Lens sent you an offer.']
                && $m['data'] === ['event' => 'offer_received', 'target_type' => 'offer', 'target_id' => '12']
                && $m['apns']['headers']['apns-collapse-id'] === 'offer_received-abc'
                && $m['android']['collapse_key'] === 'offer_received-abc';
        });
    }

    public function test_answers_are_mapped(): void
    {
        $cases = [
            [Http::response($this->fcmError(404, 'UNREGISTERED'), 404), PushResult::InvalidToken],
            [Http::response($this->fcmError(403, 'SENDER_ID_MISMATCH'), 403), PushResult::InvalidToken],
            [Http::response($this->fcmError(400, 'INVALID_ARGUMENT', 'The registration token is not a valid FCM registration token'), 400), PushResult::InvalidToken],
            [Http::response($this->fcmError(400, 'INVALID_ARGUMENT', 'Invalid JSON payload received.'), 400), PushResult::Failed],
            [Http::response($this->fcmError(429, 'QUOTA_EXCEEDED'), 429), PushResult::Retry],
            [Http::response($this->fcmError(503, 'UNAVAILABLE'), 503), PushResult::Retry],
            [Http::response('', 500), PushResult::Retry],
            [Http::response($this->fcmError(401, 'THIRD_PARTY_AUTH_ERROR'), 401), PushResult::Failed],
        ];
        $sequence = Http::sequence();
        foreach ($cases as [$response]) {
            $sequence->pushResponse($response);
        }
        Http::fake(['fcm.googleapis.com/*' => $sequence]);
        foreach ($cases as [, $expected]) {
            $this->assertSame($expected, $this->driver()->send($this->message()));
        }
    }

    public function test_network_failure_is_a_retry(): void
    {
        Http::fake(['fcm.googleapis.com/*' => fn () => throw new ConnectionException('timeout')]);

        $this->assertSame(PushResult::Retry, $this->driver()->send($this->message()));
    }

    public function test_missing_configuration_never_throws_and_sends_nothing(): void
    {
        config(['services.push.fcm.credentials' => null, 'services.push.fcm.project_id' => null]);
        Http::fake();

        $this->assertSame(PushResult::Retry, (new FcmPushNotifier(new FcmCredentials))->send($this->message()));
        Http::assertNothingSent();
    }

    public function test_the_fcm_driver_is_selectable(): void
    {
        config(['services.push.driver' => 'fcm']);
        $this->app->forgetInstance(PushNotifier::class);

        $this->assertInstanceOf(FcmPushNotifier::class, app(PushNotifier::class));
    }
}
