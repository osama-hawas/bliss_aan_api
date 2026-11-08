<?php

namespace App\Providers;

use App\Integrations\Payments\FakePaymentGateway;
use App\Integrations\Payments\PaymentGateway;
use App\Integrations\Push\FakePushNotifier;
use App\Integrations\Push\PushNotifier;
use App\Integrations\Sms\FakeSmsGateway;
use App\Integrations\Sms\SmsGateway;
use App\Services\Settings\AppSettings;
use App\Support\Phone;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Vendor drivers by config key → driver name → implementation.
     */
    private const DRIVERS = [
        SmsGateway::class => ['services.sms.driver', ['fake' => FakeSmsGateway::class]],
        PaymentGateway::class => ['services.payments.driver', ['fake' => FakePaymentGateway::class]],
        PushNotifier::class => ['services.push.driver', ['fake' => FakePushNotifier::class]],
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AppSettings::class);

        foreach (self::DRIVERS as $contract => [$configKey, $drivers]) {
            $this->app->singleton($contract, function ($app) use ($contract, $configKey, $drivers) {
                $driver = config($configKey);

                if (! isset($drivers[$driver])) {
                    throw new InvalidArgumentException("Unknown driver [{$driver}] for [{$contract}].");
                }

                return $app->make($drivers[$driver]);
            });
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The envelope adds `data`; resources must not wrap themselves.
        JsonResource::withoutWrapping();

        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        // OTP abuse (SMS pumping, code guessing): per number and per IP, checked before the account lookup.
        $phone = fn (Request $request) => Phone::normalizeSaudiMobile($request->string('phone')->toString()) ?? 'invalid';
        RateLimiter::for('otp-request', fn (Request $request) => [
            Limit::perHour(config('services.sms.rate_limits.request_per_phone_per_hour'))->by('otp-phone:'.$phone($request)),
            Limit::perHour(config('services.sms.rate_limits.request_per_ip_per_hour'))->by('otp-ip:'.$request->ip()),
        ]);
        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinute(config('services.sms.rate_limits.verify_per_ip_per_minute'))->by('otp-verify:'.$request->ip()));
    }
}
