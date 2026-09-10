<?php

declare(strict_types=1);

namespace App\Document;

use App\Repository\ProductRepository;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;

#[MongoDB\Document(collection: 'products', repositoryClass: ProductRepository::class)]
#[MongoDB\HasLifecycleCallbacks]
#[MongoDB\Index(keys: ['sku' => 'asc'], options: ['unique' => true, 'name' => 'uniq_sku'])]
#[MongoDB\Index(keys: ['category' => 'asc', 'brand' => 'asc'])]
class Product
{
    public const CATEGORIES = [
        'smartphone', 'laptop', 'tablet', 'tv', 'audio',
        'wearable', 'accessory', 'console', 'camera', 'other',
    ];

    #[MongoDB\Id]
    private ?string $id = null;

    #[MongoDB\Field(type: 'string')]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 64)]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9._-]+$/', message: 'SKU: lettres, chiffres, . _ - uniquement.')]
    private string $sku = '';

    #[MongoDB\Field(type: 'string')]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 180)]
    private string $name = '';

    #[MongoDB\Field(type: 'string')]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 120)]
    private string $brand = '';

    #[MongoDB\Field(type: 'string')]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: self::CATEGORIES, message: 'Categorie invalide.')]
    private string $category = 'other';

    #[MongoDB\Field(type: 'string', nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $description = null;

    #[MongoDB\Field(type: 'float')]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    private float $price = 0.0;

    #[MongoDB\Field(type: 'string')]
    #[Assert\Currency]
    private string $currency = 'EUR';

    #[MongoDB\Field(type: 'int')]
    #[Assert\GreaterThanOrEqual(0)]
    private int $stock = 0;

    #[MongoDB\Field(type: 'int')]
    #[Assert\Range(min: 0, max: 120)]
    private int $warrantyMonths = 24;

    /** @var array<string, mixed> paires cle/valeur de caracteristiques techniques */
    #[MongoDB\Field(type: 'hash')]
    private array $specifications = [];

    #[MongoDB\Field(type: 'bool')]
    private bool $active = true;

    #[MongoDB\Field(type: 'date_immutable')]
    private \DateTimeImmutable $createdAt;

    #[MongoDB\Field(type: 'date_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[MongoDB\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[MongoDB\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function setSku(string $sku): self
    {
        $this->sku = trim($sku);

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getBrand(): string
    {
        return $this->brand;
    }

    public function setBrand(string $brand): self
    {
        $this->brand = $brand;

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function setPrice(float $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = strtoupper($currency);

        return $this;
    }

    public function getStock(): int
    {
        return $this->stock;
    }

    public function setStock(int $stock): self
    {
        $this->stock = $stock;

        return $this;
    }

    public function getWarrantyMonths(): int
    {
        return $this->warrantyMonths;
    }

    public function setWarrantyMonths(int $warrantyMonths): self
    {
        $this->warrantyMonths = $warrantyMonths;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getSpecifications(): array
    {
        return $this->specifications;
    }

    /** @param array<string, mixed> $specifications */
    public function setSpecifications(array $specifications): self
    {
        $this->specifications = $specifications;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'brand' => $this->brand,
            'category' => $this->category,
            'description' => $this->description,
            'price' => $this->price,
            'currency' => $this->currency,
            'stock' => $this->stock,
            'warrantyMonths' => $this->warrantyMonths,
            'specifications' => (object) $this->specifications,
            'active' => $this->active,
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
            'updatedAt' => $this->updatedAt->format(\DATE_ATOM),
        ];
    }
}
