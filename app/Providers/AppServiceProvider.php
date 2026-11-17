<?php

namespace App\Providers;

use App\Enums\PayableType;
use App\Integrations\Payments\FakePaymentGateway;
use App\Integrations\Payments\PaymentGateway;
use App\Integrations\Push\FakePushNotifier;
use App\Integrations\Push\FcmPushNotifier;
use App\Integrations\Push\PushNotifier;
use App\Integrations\Shipping\Carrier;
use App\Integrations\Shipping\FakeCarrier;
use App\Integrations\Sms\FakeSmsGateway;
use App\Integrations\Sms\SmsGateway;
use App\Listeners\Operations\NotifyRefundIssued;
use App\Listeners\Store\SettleStoreReturnRefund;
use App\Models\StoreOrder;
use App\Models\StoreReturn;
use App\Services\Payments\Handlers\OfferPaymentHandler;
use App\Services\Payments\PayableRegistry;
use App\Services\Settings\AppSettings;
use App\Services\Store\StoreOrderPaymentHandler;
use App\Support\Phone;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
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
        PushNotifier::class => ['services.push.driver', ['fake' => FakePushNotifier::class, 'fcm' => FcmPushNotifier::class]],
        Carrier::class => ['services.shipping.driver', ['fake' => FakeCarrier::class]],
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AppSettings::class);

        // Slice 10 (D190): one PayableHandler per payable type. S9b appends its store-order handler in this block.
        $this->app->singleton(PayableRegistry::class, function ($app) {
            $registry = new PayableRegistry($app);
            $registry->register(PayableType::Offer, OfferPaymentHandler::class);
            $registry->register(PayableType::StoreOrder, StoreOrderPaymentHandler::class); // S9b (D214)

            return $registry;
        });

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
        // S9b (Slice 9b store orders): the store order is the Slice 10 payable `store_order` (D214); its handler is
        // registered in the PayableRegistry block above.
        Relation::morphMap(['store_order' => StoreOrder::class]);
        // --- S23 (store returns): history / audit subject type. ---
        Relation::morphMap(['store_return' => StoreReturn::class]);
        Event::listen('App\\Events\\Payments\\RefundSettled', [SettleStoreReturnRefund::class, 'handle']);
        // --- end S23 ---
        // --- S17 (Slice 17, D263): E25 on a settled staff refund. Bound by class name: Slice 10 owns the event. ---
        Event::listen('App\\Events\\Payments\\RefundSettled', [NotifyRefundIssued::class, 'handle']);
        // --- end S17 ---

        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinute(config('services.sms.rate_limits.verify_per_ip_per_minute'))->by('otp-verify:'.$request->ip()));
    }
}
