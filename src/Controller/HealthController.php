<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends AbstractController
{
    public function __construct(private readonly DocumentManager $dm)
    {
    }

    #[Route('/', name: 'root', methods: ['GET'])]
    public function root(): JsonResponse
    {
        return $this->json([
            'name' => 'electronics-api',
            'description' => 'API REST CRUD produits electroniques (Symfony + MongoDB)',
            'endpoints' => [
                'GET    /health',
                'GET    /health/ready',
                'GET    /api/products?q=&category=&brand=&active=&page=&limit=',
                'POST   /api/products',
                'GET    /api/products/{id}',
                'PUT    /api/products/{id}',
                'PATCH  /api/products/{id}',
                'DELETE /api/products/{id}',
            ],
        ]);
    }

    #[Route('/health', name: 'health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return $this->json(['status' => 'ok']);
    }

    #[Route('/health/ready', name: 'health_ready', methods: ['GET'])]
    public function ready(): JsonResponse
    {
        try {
            $this->dm->getClient()->selectDatabase('admin')->command(['ping' => 1]);

            return $this->json(['status' => 'ready', 'mongo' => 'up']);
        } catch (\Throwable $e) {
            return $this->json(
                ['status' => 'unavailable', 'mongo' => 'down', 'error' => $e->getMessage()],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }
    }
}
