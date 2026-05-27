<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\IslamicFinance\IslamicFinanceWorkflowControllerAdapter;
use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IslamicFinancedAssetController extends BaseController
{
    public function __construct(private readonly IslamicFinanceWorkflowControllerAdapter $islamic) {}

    public function storeFinancingAsset(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->islamic->storeFinancingAsset($request, $financingPublicId);
    }

    public function transitionFinancingAsset(Request $request, string $assetPublicId): JsonResponse
    {
        return $this->islamic->transitionFinancingAsset($request, $assetPublicId);
    }

    public function showFinancedAsset(Request $request, string $assetPublicId): JsonResponse
    {
        return $this->islamic->showFinancedAsset($request, $assetPublicId);
    }

    public function showFinancedAssetTimeline(Request $request, string $assetPublicId): JsonResponse
    {
        return $this->islamic->showFinancedAssetTimeline($request, $assetPublicId);
    }
}
