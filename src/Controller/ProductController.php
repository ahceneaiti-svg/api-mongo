<?php

declare(strict_types=1);

namespace App\Controller;

use App\Document\Product;
use App\Repository\ProductRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\Driver\Exception\BulkWriteException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/products')]
final class ProductController extends AbstractController
{
    public function __construct(
        private readonly DocumentManager $dm,
        private readonly ProductRepository $products,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'products_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(100, max(1, $request->query->getInt('limit', 20)));

        $filters = [
            'q' => $request->query->get('q'),
            'category' => $request->query->get('category'),
            'brand' => $request->query->get('brand'),
            'active' => $request->query->has('active') ? $request->query->getBoolean('active') : null,
        ];

        $items = $this->products->search($filters, $page, $limit);
        $total = $this->products->countFiltered($filters);

        return $this->json([
            'data' => array_map(static fn (Product $p): array => $p->toArray(), $items),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ]);
    }

    #[Route('/{id}', name: 'products_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        return $this->json($this->find($id)->toArray());
    }

    #[Route('', name: 'products_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $product = new Product();
        $this->hydrate($product, $this->decode($request));

        if (null !== $error = $this->validate($product)) {
            return $error;
        }

        try {
            $this->dm->persist($product);
            $this->dm->flush();
        } catch (BulkWriteException $e) {
            if (str_contains($e->getMessage(), 'E11000')) {
                return $this->json([
                    'error' => ['status' => 409, 'message' => \sprintf('SKU "%s" deja utilise.', $product->getSku())],
                ], Response::HTTP_CONFLICT);
            }
            throw $e;
        }

        return $this->json($product->toArray(), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'products_update', methods: ['PUT', 'PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $product = $this->find($id);
        $this->hydrate($product, $this->decode($request));

        if (null !== $error = $this->validate($product)) {
            return $error;
        }

        $this->dm->flush();

        return $this->json($product->toArray());
    }

    #[Route('/{id}', name: 'products_delete', methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        $this->dm->remove($this->find($id));
        $this->dm->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function find(string $id): Product
    {
        $product = $this->products->find($id);
        if (!$product instanceof Product) {
            throw new NotFoundHttpException(\sprintf('Produit "%s" introuvable.', $id));
        }

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Request $request): array
    {
        $raw = $request->getContent();
        if ('' === $raw) {
            return [];
        }

        try {
            $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BadRequestHttpException('Corps JSON invalide: '.$e->getMessage());
        }

        if (!\is_array($data)) {
            throw new BadRequestHttpException('Le corps doit etre un objet JSON.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrate(Product $product, array $data): void
    {
        if (\array_key_exists('sku', $data)) {
            $product->setSku((string) $data['sku']);
        }
        if (\array_key_exists('name', $data)) {
            $product->setName((string) $data['name']);
        }
        if (\array_key_exists('brand', $data)) {
            $product->setBrand((string) $data['brand']);
        }
        if (\array_key_exists('category', $data)) {
            $product->setCategory((string) $data['category']);
        }
        if (\array_key_exists('description', $data)) {
            $product->setDescription(null !== $data['description'] ? (string) $data['description'] : null);
        }
        if (\array_key_exists('price', $data)) {
            $product->setPrice((float) $data['price']);
        }
        if (\array_key_exists('currency', $data)) {
            $product->setCurrency((string) $data['currency']);
        }
        if (\array_key_exists('stock', $data)) {
            $product->setStock((int) $data['stock']);
        }
        if (\array_key_exists('warrantyMonths', $data)) {
            $product->setWarrantyMonths((int) $data['warrantyMonths']);
        }
        if (\array_key_exists('specifications', $data) && \is_array($data['specifications'])) {
            $product->setSpecifications($data['specifications']);
        }
        if (\array_key_exists('active', $data)) {
            $product->setActive((bool) $data['active']);
        }
    }

    private function validate(Product $product): ?JsonResponse
    {
        $violations = $this->validator->validate($product);
        if (0 === $violations->count()) {
            return null;
        }

        $list = [];
        foreach ($violations as $v) {
            $list[] = ['field' => $v->getPropertyPath(), 'message' => (string) $v->getMessage()];
        }

        return $this->json([
            'error' => ['status' => 422, 'message' => 'Validation echouee.'],
            'violations' => $list,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
