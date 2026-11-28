<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanetaryPlanTemplateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PlanetaryPlanTemplateRepository::class)]
#[ORM\Table(name: 'planetary_plan_templates')]
#[ORM\Index(columns: ['user_id', 'created_at'])]
class PlanetaryPlanTemplate
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 100)]
    private string $name;

    /** @var list<array{typeId: int, targetPerDay: int|float}> */
    #[ORM\Column(type: Types::JSON)]
    private array $productions = [];

    /** @var array<int, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $choices = [];

    /** @var array{purchaseSource?: string, saleSource?: string, priceSource?: string, taxPercent: int|float, daysBetweenRounds: int|float} */
    #[ORM\Column(type: Types::JSON)]
    private array $settings = ['purchaseSource' => 'jita-sell', 'saleSource' => 'jita-sell', 'taxPercent' => 4, 'daysBetweenRounds' => 4];

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $followed = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /** @return list<array{typeId: int, targetPerDay: int|float}> */
    public function getProductions(): array
    {
        return $this->productions;
    }

    /** @param list<array{typeId: int, targetPerDay: int|float}> $productions */
    public function setProductions(array $productions): static
    {
        $this->productions = $productions;

        return $this;
    }

    /** @return array<int, string> */
    public function getChoices(): array
    {
        return $this->choices;
    }

    /** @param array<int, string> $choices */
    public function setChoices(array $choices): static
    {
        $this->choices = $choices;

        return $this;
    }

    /** @return array{purchaseSource: string, saleSource: string, taxPercent: int|float, daysBetweenRounds: int|float} */
    public function getSettings(): array
    {
        if (!isset($this->settings['priceSource'])) {
            assert(isset($this->settings['purchaseSource'], $this->settings['saleSource']));

            return [
                'purchaseSource' => $this->settings['purchaseSource'],
                'saleSource' => $this->settings['saleSource'],
                'taxPercent' => $this->settings['taxPercent'],
                'daysBetweenRounds' => $this->settings['daysBetweenRounds'],
            ];
        }

        return [
            'purchaseSource' => $this->settings['priceSource'],
            'saleSource' => $this->settings['priceSource'],
            'taxPercent' => $this->settings['taxPercent'],
            'daysBetweenRounds' => $this->settings['daysBetweenRounds'],
        ];
    }

    /** @param array{purchaseSource: string, saleSource: string, taxPercent: int|float, daysBetweenRounds: int|float} $settings */
    public function setSettings(array $settings): static
    {
        $this->settings = $settings;

        return $this;
    }

    public function isFollowed(): bool
    {
        return $this->followed;
    }

    public function setFollowed(bool $followed): static
    {
        $this->followed = $followed;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
