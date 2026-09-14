<?php

declare(strict_types=1);

namespace LinkORB\Bundle\ActionBundle\Controller;

use LinkORB\Bundle\ActionBundle\Service\ActionApprovalService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED')]
#[Route('/action/approvals')]
class ApprovalController extends AbstractController
{
    public function __construct(
        private readonly ActionApprovalService $approvalService,
    ) {
    }

    #[Route('', name: 'linkorb_bundle__action_approval_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = $request->query->get('status');
        $caller = $request->query->get('caller');
        $actionName = $request->query->get('action');

        $approvals = $this->approvalService->findWithFilters(
            is_string($status) ? $status : null,
            is_string($caller) ? $caller : null,
            is_string($actionName) ? $actionName : null,
        );

        return $this->render('@LinkORBAction/approval/index.html.twig', [
            'approvals' => $approvals,
            'currentStatus' => $status,
        ]);
    }

    #[Route('/{xuid}', name: 'linkorb_bundle__action_approval_view', methods: ['GET'])]
    public function view(string $xuid): Response
    {
        $approval = $this->approvalService->findByXuid($xuid);

        if ($approval === null) {
            throw $this->createNotFoundException("Approval not found: {$xuid}");
        }

        return $this->render('@LinkORBAction/approval/view.html.twig', [
            'approval' => $approval,
        ]);
    }

    #[Route('/{xuid}/approve', name: 'linkorb_bundle__action_approval_approve', methods: ['POST'])]
    public function approve(string $xuid): Response
    {
        $approval = $this->approvalService->findByXuid($xuid);

        if ($approval === null) {
            throw $this->createNotFoundException("Approval not found: {$xuid}");
        }

        /** @var \Symfony\Component\Security\Core\User\UserInterface $user */
        $user = $this->getUser();
        $decidedBy = $user->getUserIdentifier();

        try {
            $this->approvalService->approve($approval, $decidedBy);
            $this->approvalService->executeApproved($approval);
            $this->addFlash('success', "Approval {$xuid} granted and action executed.");
        } catch (\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('linkorb_bundle__action_approval_index');
    }

    #[Route('/{xuid}/reject', name: 'linkorb_bundle__action_approval_reject', methods: ['POST'])]
    public function reject(Request $request, string $xuid): Response
    {
        $approval = $this->approvalService->findByXuid($xuid);

        if ($approval === null) {
            throw $this->createNotFoundException("Approval not found: {$xuid}");
        }

        /** @var \Symfony\Component\Security\Core\User\UserInterface $user */
        $user = $this->getUser();
        $decidedBy = $user->getUserIdentifier();

        /** @var string|null $reason */
        $reason = $request->request->get('reason');

        try {
            $this->approvalService->reject($approval, $decidedBy, is_string($reason) ? $reason : null);
            $this->addFlash('warning', "Approval {$xuid} rejected.");
        } catch (\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('linkorb_bundle__action_approval_index');
    }
}
