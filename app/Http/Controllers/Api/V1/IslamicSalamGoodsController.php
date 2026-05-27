<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\IslamicFinance\IslamicSalamGoodsWorkflow;
use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IslamicSalamGoodsController extends BaseController
{
    public function __construct(private readonly IslamicSalamGoodsWorkflow $workflow) {}

    public function store(Request $request): JsonResponse
    {
        return $this->workflow->storeGoods($request);
    }

    public function show(Request $request, string $goodsPublicId): JsonResponse
    {
        return $this->workflow->showGoods($request, $goodsPublicId);
    }

    public function timeline(Request $request, string $goodsPublicId): JsonResponse
    {
        return $this->workflow->showTimeline($request, $goodsPublicId);
    }

    public function storeDelivery(Request $request, string $goodsPublicId): JsonResponse
    {
        return $this->workflow->storeDelivery($request, $goodsPublicId);
    }

    public function transition(Request $request, string $goodsPublicId): JsonResponse
    {
        return $this->workflow->transitionGoods($request, $goodsPublicId);
    }

    public function storeUpfrontPayment(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->workflow->storeUpfrontPayment($request, $financingPublicId);
    }
}
