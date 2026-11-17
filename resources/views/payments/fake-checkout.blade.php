<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('payments.fake_checkout_title') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f6f3f5; margin: 0; padding: 24px 16px; color: #2b2330; }
        main { max-width: 420px; margin: 0 auto; background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 2px 12px rgba(0,0,0,.06); }
        h1 { font-size: 20px; margin: 0 0 8px; }
        p { color: #6b6170; line-height: 1.5; }
        .amount { font-size: 28px; font-weight: 700; margin: 16px 0; }
        form { display: flex; gap: 12px; margin-top: 16px; }
        button { flex: 1; padding: 14px; border-radius: 12px; border: 0; font-size: 16px; cursor: pointer; }
        .approve { background: #8e3b74; color: #fff; }
        .decline { background: #eee6ec; color: #2b2330; }
    </style>
</head>
<body>
<main>
    <h1>{{ __('payments.fake_checkout_title') }}</h1>
    <p>{{ __('payments.fake_checkout_body') }}</p>
    <div class="amount">{{ $payment->amount }} {{ $payment->currency }}</div>
    <p>{{ $payment->reference }} · {{ $payment->status->label() }}</p>
    @if ($done)
        <p><strong>{{ __('payments.fake_checkout_done') }}</strong></p>
    @else
        <form method="post" action="{{ url('/webhooks/payments/fake/checkout/'.$reference) }}">
            <button class="approve" name="decision" value="approve" type="submit">{{ __('payments.fake_checkout_approve') }}</button>
            <button class="decline" name="decision" value="decline" type="submit">{{ __('payments.fake_checkout_decline') }}</button>
        </form>
    @endif
</main>
</body>
</html>
