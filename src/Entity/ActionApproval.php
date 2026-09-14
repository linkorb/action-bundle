<?php

declare(strict_types=1);

namespace LinkORB\Bundle\ActionBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use LinkORB\Bundle\ActionBundle\Repository\ActionApprovalRepository;
use Xuid\Xuid;

#[ORM\Table('linkorb_action_action_approval')]
#[ORM\Entity(repositoryClass: ActionApprovalRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'unique_xuid', columns: ['xuid'])]
#[ORM\Index(name: 'idx_status', columns: ['status'])]
#[ORM\Index(name: 'idx_caller', columns: ['caller_identifier'])]
#[ORM\Index(name: 'idx_action_name', columns: ['action_name'])]
class ActionApproval
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_EXECUTED = 'executed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    /** @phpstan-ignore property.unusedType */
    private ?int $id = null;

    #[ORM\Column(length: 16)]
    private ?string $xuid = null;

    #[ORM\Column(length: 255)]
    private string $actionName;

    #[ORM\Column(type: Types::TEXT)]
    private string $input;

    #[ORM\Column(length: 255)]
    private string $callerIdentifier;

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column]
    private int $createdAt;

    #[ORM\Column]
    private int $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?int $decidedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $decidedBy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $executionResult = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $webhookUrl = null;

    public function __construct()
    {
        $this->createdAt = time();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getXuid(): ?string
    {
        return $this->xuid;
    }

    #[ORM\PrePersist]
    public function generateXuid(): void
    {
        if ($this->xuid === null) {
            // @phpstan-ignore-next-line always returns string even if type doesn't reflect that
            $this->xuid = Xuid::getXuid();
        }
    }

    public function getActionName(): string
    {
        return $this->actionName;
    }

    public function setActionName(string $actionName): static
    {
        $this->actionName = $actionName;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInput(): array
    {
        $decoded = json_decode($this->input, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function setInput(array $input): static
    {
        $encoded = json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->input = $encoded !== false ? $encoded : '{}';

        return $this;
    }

    public function getCallerIdentifier(): string
    {
        return $this->callerIdentifier;
    }

    public function setCallerIdentifier(string $callerIdentifier): static
    {
        $this->callerIdentifier = $callerIdentifier;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isExpired(): bool
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return true;
        }

        return $this->status === self::STATUS_PENDING && time() > $this->expiresAt;
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    public function setCreatedAt(int $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getExpiresAt(): int
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(int $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getDecidedAt(): ?int
    {
        return $this->decidedAt;
    }

    public function setDecidedAt(?int $decidedAt): static
    {
        $this->decidedAt = $decidedAt;

        return $this;
    }

    public function getDecidedBy(): ?string
    {
        return $this->decidedBy;
    }

    public function setDecidedBy(?string $decidedBy): static
    {
        $this->decidedBy = $decidedBy;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getExecutionResult(): ?array
    {
        if ($this->executionResult === null) {
            return null;
        }

        $decoded = json_decode($this->executionResult, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed>|null $result
     */
    public function setExecutionResult(?array $result): static
    {
        $this->executionResult = $result !== null
            ? (json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null)
            : null;

        return $this;
    }

    public function getWebhookUrl(): ?string
    {
        return $this->webhookUrl;
    }

    public function setWebhookUrl(?string $webhookUrl): static
    {
        $this->webhookUrl = $webhookUrl;

        return $this;
    }
}
