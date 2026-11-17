<?php

use App\Http\Controllers\Api\V1\Admin\Content as C;
use Illuminate\Support\Facades\Route;

// Slice 20 (S20) — banners, pages, FAQs (content.manage) and broadcast campaigns (notifications.broadcast). D300–D306.
Route::middleware('permission:content.manage')->group(function () {
    Route::prefix('banners')->group(function () {
        Route::get('/', C\ListBannersController::class);
        Route::post('/', C\CreateBannerController::class);
        Route::get('/{banner}', C\ShowBannerController::class)->whereNumber('banner');
        Route::put('/{banner}', C\UpdateBannerController::class)->whereNumber('banner');
        Route::post('/{banner}/show', C\PublishBannerController::class)->whereNumber('banner');
        Route::post('/{banner}/hide', C\HideBannerController::class)->whereNumber('banner');
    });
    Route::prefix('content-pages')->group(function () {
        Route::get('/', C\ListPagesController::class);
        Route::post('/', C\CreatePageController::class);
        Route::get('/{page}', C\ShowPageController::class)->whereNumber('page');
        Route::put('/{page}', C\UpdatePageController::class)->whereNumber('page');
        Route::post('/{page}/show', C\PublishPageController::class)->whereNumber('page');
        Route::post('/{page}/hide', C\HidePageController::class)->whereNumber('page');
    });
    Route::prefix('faqs')->group(function () {
        Route::get('/', C\ListFaqsController::class);
        Route::post('/', C\CreateFaqController::class);
        Route::get('/{faq}', C\ShowFaqController::class)->whereNumber('faq');
        Route::put('/{faq}', C\UpdateFaqController::class)->whereNumber('faq');
        Route::post('/{faq}/show', C\PublishFaqController::class)->whereNumber('faq');
        Route::post('/{faq}/hide', C\HideFaqController::class)->whereNumber('faq');
    });
});

Route::middleware('permission:notifications.broadcast')->prefix('broadcasts')->group(function () {
    Route::get('/', C\ListBroadcastsController::class);
    Route::post('/', C\CreateBroadcastController::class);
    Route::get('/{broadcast}', C\ShowBroadcastController::class)->whereNumber('broadcast');
    Route::put('/{broadcast}', C\UpdateBroadcastController::class)->whereNumber('broadcast');
    Route::get('/{broadcast}/audience', C\BroadcastAudienceController::class)->whereNumber('broadcast');
    Route::post('/{broadcast}/send', C\SendBroadcastController::class)->whereNumber('broadcast');
    Route::post('/{broadcast}/schedule', C\ScheduleBroadcastController::class)->whereNumber('broadcast');
    Route::post('/{broadcast}/cancel', C\CancelBroadcastController::class)->whereNumber('broadcast');
});
