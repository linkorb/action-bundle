<?php

declare(strict_types=1);

namespace LinkORB\Bundle\ActionBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use LinkORB\Bundle\ActionBundle\Entity\ActionApproval;
use LinkORB\Bundle\ActionBundle\Repository\ActionApprovalRepository;
use LinkORB\Component\Action\ActionResult;
use LinkORB\Component\Action\Approval\ApprovalRuleMatcher;
use LinkORB\Component\Action\Host\HostInterface;
use LinkORB\Component\Action\Message\ApprovalGrantedMessage;
use LinkORB\Component\Action\Message\ApprovalRejectedMessage;
use LinkORB\Component\Action\Message\ApprovalRequiredMessage;
use LinkORB\Component\Action\Message\LogMessage;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ActionApprovalService
{
    private ApprovalRuleMatcher $ruleMatcher;
    private int $defaultTimeoutSeconds;

    /**
     * @param array<int, array{caller: string, action: string, input?: array<string, mixed>, effect: string}> $rules
     */
    public function __construct(
        private readonly ActionApprovalRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ActionService $actionService,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        array $rules = [],
        int $defaultTimeoutMinutes = 10,
    ) {
        $this->ruleMatcher = new ApprovalRuleMatcher($rules);
        $this->defaultTimeoutSeconds = $defaultTimeoutMinutes * 60;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function isPreApproved(string $caller, string $actionName, array $input): bool
    {
        return $this->ruleMatcher->isPreApproved($caller, $actionName, $input);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function createApproval(
        string $actionName,
        array $input,
        string $callerIdentifier,
        ?string $webhookUrl = null,
    ): ActionApproval {
        $approval = new ActionApproval();
        $approval->setActionName($actionName);
        $approval->setInput($input);
        $approval->setCallerIdentifier($callerIdentifier);
        $approval->setExpiresAt(time() + $this->defaultTimeoutSeconds);

        if ($webhookUrl !== null) {
            $approval->setWebhookUrl($webhookUrl);
        }

        $this->entityManager->persist($approval);
        $this->entityManager->flush();

        return $approval;
    }

    public function findByXuid(string $xuid): ?ActionApproval
    {
        return $this->repository->findOneByXuid($xuid);
    }

    /**
     * @return ActionApproval[]
     */
    public function findPending(): array
    {
        return $this->repository->findAllPending();
    }

    /**
     * @return ActionApproval[]
     */
    public function findWithFilters(?string $status = null, ?string $caller = null, ?string $actionName = null): array
    {
        return $this->repository->findWithFilters($status, $caller, $actionName);
    }

    public function approve(ActionApproval $approval, string $decidedBy, ?string $reason = null): void
    {
        if (!$approval->isPending()) {
            throw new \LogicException("Cannot approve a non-pending approval (status: {$approval->getStatus()})");
        }

        if ($approval->isExpired()) {
            $approval->setStatus(ActionApproval::STATUS_EXPIRED);
            $this->entityManager->flush();

            throw new \LogicException('Cannot approve an expired approval');
        }

        $approval->setStatus(ActionApproval::STATUS_APPROVED);
        $approval->setDecidedAt(time());
        $approval->setDecidedBy($decidedBy);

        if ($reason !== null) {
            $approval->setReason($reason);
        }

        $this->entityManager->flush();

        $this->sendWebhook($approval);
    }

    public function reject(ActionApproval $approval, string $decidedBy, ?string $reason = null): void
    {
        if (!$approval->isPending()) {
            throw new \LogicException("Cannot reject a non-pending approval (status: {$approval->getStatus()})");
        }

        $approval->setStatus(ActionApproval::STATUS_REJECTED);
        $approval->setDecidedAt(time());
        $approval->setDecidedBy($decidedBy);

        if ($reason !== null) {
            $approval->setReason($reason);
        }

        $this->entityManager->flush();

        $this->sendWebhook($approval);
    }

    /**
     * Execute the action associated with an approved approval and store the result.
     */
    public function executeApproved(ActionApproval $approval, ?HostInterface $host = null): ActionResult
    {
        if (!$approval->isApproved()) {
            throw new \LogicException("Cannot execute a non-approved approval (status: {$approval->getStatus()})");
        }

        $result = $this->actionService->executeAction(
            $approval->getActionName(),
            $approval->getInput(),
            $host,
        );

        $approval->setStatus(ActionApproval::STATUS_EXECUTED);
        $approval->setExecutionResult([
            'success' => $result->isSuccess(),
            'data' => $result->getData(),
            'error' => $result->getError(),
        ]);
        $this->entityManager->flush();

        return $result;
    }

    /**
     * Wait for an approval decision within a streaming context.
     * Polls the DB and emits NDJSON messages via the host.
     *
     * Returns the refreshed approval (approved, rejected, or expired).
     */
    public function waitForDecision(ActionApproval $approval, HostInterface $host): ActionApproval
    {
        /** @var string $xuid */
        $xuid = $approval->getXuid();

        $host->emit(new ApprovalRequiredMessage(
            $xuid,
            $approval->getActionName(),
            $approval->getInput(),
        ));

        $start = time();
        while (time() - $start < $this->defaultTimeoutSeconds) {
            sleep(2);

            $this->entityManager->clear(ActionApproval::class);
            $refreshed = $this->repository->findOneByXuid($xuid);

            if ($refreshed === null) {
                $host->emit(new LogMessage('error', 'Approval record not found'));
                break;
            }

            $approval = $refreshed;

            if ($approval->isExpired()) {
                $approval->setStatus(ActionApproval::STATUS_EXPIRED);
                $this->entityManager->flush();
                $host->emit(new LogMessage('info', 'Approval expired'));
                break;
            }

            if ($approval->isApproved()) {
                $host->emit(new ApprovalGrantedMessage($xuid, $approval->getDecidedBy()));
                break;
            }

            if ($approval->isRejected()) {
                $host->emit(new ApprovalRejectedMessage($xuid, $approval->getDecidedBy(), $approval->getReason()));
                break;
            }

            $host->emit(new LogMessage('debug', 'Waiting for approval...'));
            flush();
        }

        if ($approval->isPending()) {
            $approval->setStatus(ActionApproval::STATUS_EXPIRED);
            $this->entityManager->flush();
            $host->emit(new LogMessage('info', 'Approval timed out'));
        }

        return $approval;
    }

    public function getTimeoutSeconds(): int
    {
        return $this->defaultTimeoutSeconds;
    }

    private function sendWebhook(ActionApproval $approval): void
    {
        $webhookUrl = $approval->getWebhookUrl();
        if ($webhookUrl === null) {
            return;
        }

        try {
            $response = $this->httpClient->request('POST', $webhookUrl, [
                'json' => [
                    'xuid' => $approval->getXuid(),
                    'action' => $approval->getActionName(),
                    'status' => $approval->getStatus(),
                    'decided_by' => $approval->getDecidedBy(),
                    'decided_at' => $approval->getDecidedAt(),
                    'reason' => $approval->getReason(),
                ],
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->warning('Approval webhook returned non-2xx status', [
                    'url' => $webhookUrl,
                    'statusCode' => $statusCode,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to send approval webhook', [
                'url' => $webhookUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
