<?php

declare(strict_types=1);

namespace LinkORB\Bundle\ActionBundle\Controller;

use LinkORB\Bundle\ActionBundle\Entity\ActionApproval;
use LinkORB\Bundle\ActionBundle\Service\ActionApprovalService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED')]
#[Route('/api/v1/action/approvals')]
class ApprovalApiController extends AbstractController
{
    public function __construct(
        private readonly ActionApprovalService $approvalService,
    ) {
    }

    #[Route('', name: 'linkorb_bundle__action_approval_api_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $status = $request->query->get('status');
        $caller = $request->query->get('caller');
        $actionName = $request->query->get('action');

        $approvals = $this->approvalService->findWithFilters(
            is_string($status) ? $status : null,
            is_string($caller) ? $caller : null,
            is_string($actionName) ? $actionName : null,
        );

        return new JsonResponse([
            'approvals' => array_map($this->serializeApproval(...), $approvals),
        ]);
    }

    #[Route('/{xuid}', name: 'linkorb_bundle__action_approval_api_view', methods: ['GET'])]
    public function view(string $xuid): JsonResponse
    {
        $approval = $this->approvalService->findByXuid($xuid);

        if ($approval === null) {
            return new JsonResponse([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => "Approval not found: {$xuid}",
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        if ($approval->isPending() && $approval->isExpired()) {
            $approval->setStatus(ActionApproval::STATUS_EXPIRED);
        }

        $data = $this->serializeApproval($approval);

        if ($approval->getExecutionResult() !== null) {
            $data['result'] = $approval->getExecutionResult();
        }

        return new JsonResponse($data);
    }

    #[Route('/{xuid}/approve', name: 'linkorb_bundle__action_approval_api_approve', methods: ['POST'])]
    public function approve(Request $request, string $xuid): JsonResponse
    {
        $approval = $this->approvalService->findByXuid($xuid);

        if ($approval === null) {
            return new JsonResponse([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => "Approval not found: {$xuid}",
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        /** @var \Symfony\Component\Security\Core\User\UserInterface $user */
        $user = $this->getUser();
        $decidedBy = $user->getUserIdentifier();

        /** @var string $content */
        $content = $request->getContent();

        /** @var array{reason?: string}|null $body */
        $body = json_decode($content, true);
        $reason = is_array($body) ? ($body['reason'] ?? null) : null;

        try {
            $this->approvalService->approve($approval, $decidedBy, is_string($reason) ? $reason : null);
        } catch (\LogicException $e) {
            return new JsonResponse([
                'error' => [
                    'code' => 'INVALID_STATE',
                    'message' => $e->getMessage(),
                ],
            ], Response::HTTP_CONFLICT);
        }

        $this->approvalService->executeApproved($approval);

        return new JsonResponse($this->serializeApproval($approval));
    }

    #[Route('/{xuid}/reject', name: 'linkorb_bundle__action_approval_api_reject', methods: ['POST'])]
    public function reject(Request $request, string $xuid): JsonResponse
    {
        $approval = $this->approvalService->findByXuid($xuid);

        if ($approval === null) {
            return new JsonResponse([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => "Approval not found: {$xuid}",
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        /** @var \Symfony\Component\Security\Core\User\UserInterface $user */
        $user = $this->getUser();
        $decidedBy = $user->getUserIdentifier();

        /** @var string $content */
        $content = $request->getContent();

        /** @var array{reason?: string}|null $body */
        $body = json_decode($content, true);
        $reason = is_array($body) ? ($body['reason'] ?? null) : null;

        try {
            $this->approvalService->reject($approval, $decidedBy, is_string($reason) ? $reason : null);
        } catch (\LogicException $e) {
            return new JsonResponse([
                'error' => [
                    'code' => 'INVALID_STATE',
                    'message' => $e->getMessage(),
                ],
            ], Response::HTTP_CONFLICT);
        }

        return new JsonResponse($this->serializeApproval($approval));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeApproval(ActionApproval $approval): array
    {
        $data = [
            'xuid' => $approval->getXuid(),
            'action' => $approval->getActionName(),
            'input' => $approval->getInput(),
            'caller' => $approval->getCallerIdentifier(),
            'status' => $approval->getStatus(),
            'created_at' => $approval->getCreatedAt(),
            'expires_at' => $approval->getExpiresAt(),
        ];

        if ($approval->getDecidedAt() !== null) {
            $data['decided_at'] = $approval->getDecidedAt();
            $data['decided_by'] = $approval->getDecidedBy();
        }

        if ($approval->getReason() !== null) {
            $data['reason'] = $approval->getReason();
        }

        return $data;
    }
}
