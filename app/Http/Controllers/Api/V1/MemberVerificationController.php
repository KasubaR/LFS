<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\VerifyMemberRequest;
use App\Services\MemberVerificationService;
use Illuminate\Http\JsonResponse;

class MemberVerificationController extends Controller
{
    public function __construct(
        private readonly MemberVerificationService $verification,
    ) {}

    /**
     * POST /api/v1/members/verify
     *
     * POST rather than GET so surnames and email addresses never appear in
     * URLs, access logs, or proxy caches.
     */
    public function verify(VerifyMemberRequest $request): JsonResponse
    {
        $result = $this->verification->verifyByCredentials(
            (string) $request->input('membership_number'),
            $request->input('surname'),
            $request->input('email'),
        );

        // Always 200 for a well-formed request. A 404 would be ambiguous with a
        // wrong base URL and makes event-site error handling unreliable.
        return response()->json(['data' => $result]);
    }

    /**
     * GET /api/v1/members/token/{token}
     *
     * Card/QR lookup. The token is an unguessable UUID, so no second factor.
     */
    public function showByToken(string $token): JsonResponse
    {
        return response()->json([
            'data' => $this->verification->verifyByToken($token),
        ]);
    }
}
