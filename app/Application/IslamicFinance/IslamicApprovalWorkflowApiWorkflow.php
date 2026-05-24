<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use App\Http\Controllers\BaseController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * HTTP entry point for the reusable IF-011 approval workflow.
 *
 * Routes accept a subject type + subject public id; unknown subject types are
 * rejected deny-by-default at the request validation layer.
 */
final class IslamicApprovalWorkflowApiWorkflow extends BaseController
{
    public function __construct(
        private readonly IslamicApprovalWorkflowService $service,
    ) {}

    public function show(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        try {
            $this->assertSubjectType($subjectType);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['subject_type' => [$exception->getMessage()]]);
        }

        $workflow = $this->service->workflowFor($subjectType, $subjectPublicId);
        if ($workflow === null) {
            return $this->respondNotFound('Approval workflow not found.');
        }

        $payload = $this->service->payloadFor($workflow);
        $usability = $this->service->isUsableForNewActions($subjectType, $subjectPublicId);
        $payload['usability'] = $usability;

        return $this->respondSuccess($payload, 'Approval workflow retrieved');
    }

    public function submit(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->transition($request, $subjectType, $subjectPublicId, IslamicApprovalStateMachine::DECISION_SUBMIT);
    }

    public function approve(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->transition($request, $subjectType, $subjectPublicId, IslamicApprovalStateMachine::DECISION_APPROVE);
    }

    public function reject(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->transition($request, $subjectType, $subjectPublicId, IslamicApprovalStateMachine::DECISION_REJECT);
    }

    public function suspend(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->transition($request, $subjectType, $subjectPublicId, IslamicApprovalStateMachine::DECISION_SUSPEND);
    }

    public function revoke(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->transition($request, $subjectType, $subjectPublicId, IslamicApprovalStateMachine::DECISION_REVOKE);
    }

    public function expire(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->transition($request, $subjectType, $subjectPublicId, IslamicApprovalStateMachine::DECISION_EXPIRE);
    }

    public function archive(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->transition($request, $subjectType, $subjectPublicId, IslamicApprovalStateMachine::DECISION_ARCHIVE);
    }

    private function transition(Request $request, string $subjectType, string $subjectPublicId, string $decision): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $this->assertSubjectType($subjectType);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['subject_type' => [$exception->getMessage()]]);
        }

        $validated = Validator::make($request->all(), [
            'comments' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'conditions' => ['sometimes', 'nullable', 'array'],
            'evidence_document_public_id' => ['sometimes', 'nullable', 'string'],
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'requester_user_public_id' => ['sometimes', 'nullable', 'string'],
        ])->validate();

        $requesterUserId = null;
        if (isset($validated['requester_user_public_id']) && is_string($validated['requester_user_public_id']) && $validated['requester_user_public_id'] !== '') {
            $row = DB::table('users')->where('public_id', $validated['requester_user_public_id'])->first(['id']);
            if (! is_object($row) || ! is_numeric($row->id)) {
                return $this->respondUnprocessable(errors: ['requester_user_public_id' => ['Requester user not found.']]);
            }
            $requesterUserId = (int) $row->id;
        }

        try {
            $this->service->ensureWorkflow($subjectType, $subjectPublicId, $actor, $request);
            $options = [
                'comments' => $validated['comments'] ?? null,
                'conditions' => $validated['conditions'] ?? null,
                'evidence_document_public_id' => $validated['evidence_document_public_id'] ?? null,
                'effective_from' => $validated['effective_from'] ?? null,
                'effective_to' => $validated['effective_to'] ?? null,
                'metadata' => $validated['metadata'] ?? null,
                'requester_user_id' => $requesterUserId,
            ];
            $row = $this->service->applyDecision($subjectType, $subjectPublicId, $actor, $decision, $options, $request);
        } catch (ReadinessGateFailure $failure) {
            return $this->respondUnprocessable(errors: $failure->failures);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_approval_workflow' => [$exception->getMessage()]]);
        }

        $payload = $this->service->payloadFor($row);
        $currentState = is_string($payload['current_state'] ?? null) ? $payload['current_state'] : '';

        return $this->respondSuccess($payload, 'Approval workflow transitioned to '.$currentState);
    }

    private function assertSubjectType(string $subjectType): void
    {
        Validator::make(['subject_type' => $subjectType], [
            'subject_type' => ['required', Rule::in(IslamicApprovalStateMachine::SUBJECT_TYPES)],
        ])->validate();
    }

    private function requirePlatformAdmin(Request $request): bool
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasRole('platform-admin');
    }
}
