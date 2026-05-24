<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class IslamicShariaAuthorityService
{
    public const AUTHORITY_TYPES = ['board', 'committee', 'advisor_panel'];

    public const STATUSES = ['draft', 'active', 'suspended', 'revoked', 'retired'];

    public const MEMBER_ROLES = ['chair', 'reviewer', 'approver', 'observer', 'administrator'];

    public const MEMBER_STATUSES = ['active', 'suspended', 'revoked', 'expired'];

    public const APPROVAL_ROLES = ['chair', 'approver', 'administrator'];

    public const SCOPE_TYPES = ['institution', 'product_family', 'agency'];

    public const DECISION_TYPE_PRODUCT_COMPLIANCE_APPROVAL = 'islamic_product_compliance_approval';

    public const DECISION_TYPE_APPROVAL_WORKFLOW = 'islamic_approval_workflow_decision';

    /**
     * Return a list of failure messages explaining why the actor cannot approve.
     * Empty array means the actor is authorized.
     *
     * @param  array<string, mixed>  $scope
     * @return array<int, string>
     */
    public function activeMandateFailures(
        User $actor,
        string $decisionType,
        array $scope,
        ?CarbonInterface $asOf = null,
        ?int $requesterUserId = null,
    ): array {
        $asOfDate = ($asOf ?? CarbonImmutable::now())->toDateString();

        // Self-approval guard: default deny for material decisions if requesterUserId not supplied.
        if ($requesterUserId === null) {
            return ['Requester identity unknown; material decision cannot be approved without a verified requester.'];
        }
        if ($requesterUserId === $actor->id) {
            return ['Requester cannot self-approve a material Sharia decision.'];
        }

        $rows = DB::table('islamic_sharia_authority_members as m')
            ->join('islamic_sharia_authorities as a', 'a.id', '=', 'm.islamic_sharia_authority_id')
            ->leftJoin('documents as d', 'd.id', '=', 'a.document_id')
            ->where('m.user_id', $actor->id)
            ->select([
                'm.id as member_id',
                'm.member_role',
                'm.scope as member_scope',
                'm.starts_on',
                'm.ends_on',
                'm.status as member_status',
                'a.id as authority_id',
                'a.status as authority_status',
                'a.effective_date as authority_effective',
                'a.expiry_date as authority_expiry',
                'a.mandate_scope',
                'd.status as document_status',
            ])
            ->get();

        if ($rows->isEmpty()) {
            return ['Actor has no Sharia authority membership.'];
        }

        $sawAnyAuthorityActiveAndInForce = false;
        $sawDocumentArchived = false;
        $sawAuthorityLifecycle = false;
        $sawMemberLifecycle = false;
        $sawMemberWindowInvalid = false;
        $sawRoleNotApproval = false;
        $sawScopeMismatch = false;

        foreach ($rows as $row) {
            $authorityStatus = is_string($row->authority_status) ? $row->authority_status : '';
            $authorityEffective = is_string($row->authority_effective) ? $row->authority_effective : '';
            $authorityExpiry = is_string($row->authority_expiry) ? $row->authority_expiry : null;
            $documentStatus = is_string($row->document_status) ? $row->document_status : '';
            $memberRole = is_string($row->member_role) ? $row->member_role : '';
            $memberStatus = is_string($row->member_status) ? $row->member_status : '';
            $memberStarts = is_string($row->starts_on) ? $row->starts_on : '';
            $memberEnds = is_string($row->ends_on) ? $row->ends_on : null;
            $mandateScope = is_string($row->mandate_scope) ? $row->mandate_scope : null;
            $memberScope = is_string($row->member_scope) ? $row->member_scope : null;

            if ($authorityStatus !== 'active') {
                $sawAuthorityLifecycle = true;

                continue;
            }
            if ($authorityEffective === '' || $authorityEffective > $asOfDate) {
                $sawAuthorityLifecycle = true;

                continue;
            }
            if ($authorityExpiry !== null && $authorityExpiry <= $asOfDate) {
                $sawAuthorityLifecycle = true;

                continue;
            }
            if ($documentStatus !== 'active') {
                $sawDocumentArchived = true;

                continue;
            }
            $sawAnyAuthorityActiveAndInForce = true;

            if ($memberStatus !== 'active') {
                $sawMemberLifecycle = true;

                continue;
            }
            if ($memberStarts === '' || $memberStarts > $asOfDate) {
                $sawMemberWindowInvalid = true;

                continue;
            }
            if ($memberEnds !== null && $memberEnds <= $asOfDate) {
                $sawMemberWindowInvalid = true;

                continue;
            }
            if (! in_array($memberRole, self::APPROVAL_ROLES, true)) {
                $sawRoleNotApproval = true;

                continue;
            }
            if (! $this->scopeMatches($mandateScope, $memberScope, $scope)) {
                $sawScopeMismatch = true;

                continue;
            }

            return [];
        }

        $failures = [];
        if (! $sawAnyAuthorityActiveAndInForce) {
            if ($sawAuthorityLifecycle) {
                $failures[] = 'Actor belongs to a Sharia authority that is not currently active and in force.';
            }
            if ($sawDocumentArchived) {
                $failures[] = 'Actor\'s Sharia authority has no active mandate evidence document.';
            }
        }
        if ($sawMemberLifecycle) {
            $failures[] = 'Actor\'s Sharia mandate is suspended, revoked, or expired.';
        }
        if ($sawMemberWindowInvalid) {
            $failures[] = 'Actor\'s Sharia mandate is outside its validity window.';
        }
        if ($sawRoleNotApproval) {
            $failures[] = 'Actor\'s Sharia role does not permit approving '.$decisionType.'.';
        }
        if ($sawScopeMismatch) {
            $failures[] = 'Actor\'s Sharia mandate scope does not cover the requested decision scope.';
        }

        if ($failures === []) {
            $failures[] = 'Actor has no eligible Sharia mandate for this decision.';
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    public function assertCanApproveDecision(
        User $actor,
        string $decisionType,
        array $scope,
        ?CarbonInterface $asOf = null,
        ?int $requesterUserId = null,
    ): void {
        $failures = $this->activeMandateFailures($actor, $decisionType, $scope, $asOf, $requesterUserId);
        if ($failures !== []) {
            throw new ReadinessGateFailure(['islamic_sharia_authority' => $failures]);
        }
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array{ok: bool, reason: string|null}
     */
    public function canReviewDecision(
        User $actor,
        string $decisionType,
        array $scope,
        ?CarbonInterface $asOf = null,
        ?int $requesterUserId = null,
    ): array {
        $failures = $this->activeMandateFailures($actor, $decisionType, $scope, $asOf, $requesterUserId);
        if ($failures === []) {
            return ['ok' => true, 'reason' => null];
        }

        return ['ok' => false, 'reason' => implode(' ', $failures)];
    }

    /**
     * Matches an authority's mandate_scope (and optional member scope override) against the
     * decision scope. Returns true if any of the structured scope shapes accepts the decision.
     *
     * @param  array<string, mixed>  $decisionScope
     */
    private function scopeMatches(?string $mandateScopeJson, ?string $memberScopeJson, array $decisionScope): bool
    {
        $effectiveScope = null;
        if (is_string($memberScopeJson) && $memberScopeJson !== '') {
            $decoded = json_decode($memberScopeJson, true);
            if (is_array($decoded) && $decoded !== []) {
                $effectiveScope = $decoded;
            }
        }
        if ($effectiveScope === null && is_string($mandateScopeJson) && $mandateScopeJson !== '') {
            $decoded = json_decode($mandateScopeJson, true);
            if (is_array($decoded)) {
                $effectiveScope = $decoded;
            }
        }
        if (! is_array($effectiveScope)) {
            return false;
        }

        $type = isset($effectiveScope['type']) && is_string($effectiveScope['type']) ? $effectiveScope['type'] : '';
        if ($type === 'institution') {
            return true;
        }
        if ($type === 'product_family') {
            $requested = isset($decisionScope['product_family']) && is_string($decisionScope['product_family']) ? $decisionScope['product_family'] : '';
            $codes = isset($effectiveScope['codes']) && is_array($effectiveScope['codes']) ? $effectiveScope['codes'] : [];

            return $requested !== '' && in_array($requested, $codes, true);
        }
        if ($type === 'agency') {
            $requested = isset($decisionScope['agency']) && is_string($decisionScope['agency']) ? $decisionScope['agency'] : '';
            $codes = isset($effectiveScope['codes']) && is_array($effectiveScope['codes']) ? $effectiveScope['codes'] : [];

            return $requested !== '' && in_array($requested, $codes, true);
        }

        return false;
    }
}
