<?php

namespace App\Http\Controllers\Api\V1\Shared\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Shared\Catalog\PackageResource;
use App\Services\Catalog\PackageBrowser;
use App\Traits\ApiResponse;

/**
 * One visible package with its services and their "starting from" prices (Slice 24 §5, D355). A stopped package or one
 * without a visible service is a 404 (D352). Public.
 */
class ShowPackageController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PackageBrowser $packages) {}

    public function __invoke(int $package)
    {
        return $this->success(new PackageResource($this->packages->find($package)));
    }
}
