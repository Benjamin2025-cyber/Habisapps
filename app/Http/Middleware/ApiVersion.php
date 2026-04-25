<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApiVersion
{
    /** @var array<int, string> */
    private const array SUPPORTED_VERSIONS = ['1'];

    public function handle(Request $request, Closure $next): Response
    {
        $version = $request->header('X-API-Version', '1');

        $versionStr = is_array($version) ? implode(',', $version) : (string) $version;

        if (! in_array($versionStr, self::SUPPORTED_VERSIONS, true)) {
            return ApiResponse::error(
                sprintf(
                    'Unsupported API version: %s. Supported versions: %s',
                    $versionStr,
                    implode(', ', self::SUPPORTED_VERSIONS)
                ),
                null,
                Response::HTTP_BAD_REQUEST
            );
        }

        $request->attributes->set('api_version', (int) $versionStr);

        return $next($request);
    }
}
