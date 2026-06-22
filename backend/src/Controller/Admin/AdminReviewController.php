<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Auth\AuthException;
use App\Service\Auth\AuthService;
use App\Service\Review\ReviewService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Valoraciones en el panel (doc 13): lista paginada y agregados (nota media
 * global y por profesional/servicio).
 */
final class AdminReviewController extends AdminController
{
    public function __construct(
        private readonly ReviewService $reviews,
        private readonly AuthService $auth,
    ) {
    }

    #[Route('/api/v1/admin/reviews', name: 'admin_review_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, ['recepcion', 'admin_sede', 'admin_cadena']);
            $requested = $request->query->get('location_id');
            $locationId = $this->auth->resolveLocation($user, $requested !== null && (int) $requested > 0 ? (int) $requested : null);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $pg = self::pagination($request);

        return $this->json([
            'reviews' => $this->reviews->listForLocation($locationId, $pg['per_page'], $pg['offset']),
            'page' => $pg['page'],
            'per_page' => $pg['per_page'],
            'total' => $this->reviews->countForLocation($locationId),
        ]);
    }

    #[Route('/api/v1/admin/reports/ratings', name: 'admin_report_ratings', methods: ['GET'])]
    public function ratings(Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, ['admin_sede', 'admin_cadena']);
            $requested = $request->query->get('location_id');
            $locationId = $this->auth->resolveLocation($user, $requested !== null && (int) $requested > 0 ? (int) $requested : null);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        return $this->json($this->reviews->aggregates($locationId));
    }
}
