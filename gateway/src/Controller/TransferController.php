<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreateTransferRequest;
use App\Service\TransferService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

class TransferController
{
    public function __construct(private TransferService $transferService) {}

    #[Route('/transfers', methods: ['POST'])]
    #[OA\Tag(name: 'Transfers')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CreateTransferRequest::class)))]
    #[OA\Parameter(
        name: 'X-Idempotency-Key',
        in: 'header',
        required: false,
        description: 'Idempotency key. Repeated requests with the same key return the original response.',
        schema: new OA\Schema(type: 'string', maxLength: 255),
    )]
    #[OA\Response(
        response: 202,
        description: 'Transfer accepted for asynchronous processing.',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'transfer_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'status', type: 'string', enum: ['pending', 'processing', 'completed', 'failed']),
        ]),
    )]
    #[OA\Response(response: 400, description: 'Validation failed.')]
    #[OA\Response(
        response: 409,
        description: 'Idempotency key conflict. `code=idempotency_conflict` indicates an in-flight reservation for the same key (retry shortly); `code=idempotency_payload_mismatch` indicates the key was previously used with a different request body (do not retry — fix the body).',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'error', type: 'string'),
            new OA\Property(
                property: 'code',
                type: 'string',
                enum: ['idempotency_conflict', 'idempotency_payload_mismatch'],
            ),
        ]),
    )]
    public function create(
        #[MapRequestPayload] CreateTransferRequest $payload,
        Request $request,
    ): JsonResponse {
        $transfer = $this->transferService->createTransfer(
            $payload->source_account_id,
            $payload->dest_account_id,
            $payload->amount,
            $payload->callback_url,
            $request->headers->get('X-Idempotency-Key'),
        );

        return new JsonResponse(
            ['transfer_id' => $transfer->getId(), 'status' => $transfer->getStatus()->value],
            Response::HTTP_ACCEPTED,
        );
    }

    #[Route('/transfers/{id}', methods: ['GET'])]
    #[OA\Tag(name: 'Transfers')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(
        response: 200,
        description: 'Transfer details.',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'transfer_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'source_account_id', type: 'integer'),
            new OA\Property(property: 'dest_account_id', type: 'integer'),
            new OA\Property(property: 'amount', type: 'integer', description: 'Amount in minor units.'),
            new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
            new OA\Property(property: 'status', type: 'string', enum: ['pending', 'processing', 'completed', 'failed']),
            new OA\Property(property: 'failure_reason', type: 'string', nullable: true),
            new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
            new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        ]),
    )]
    #[OA\Response(response: 404, description: 'Transfer not found.')]
    public function show(string $id): JsonResponse
    {
        $transfer = $this->transferService->getTransfer($id);

        if ($transfer === null) {
            return new JsonResponse(['error' => 'Transfer not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'transfer_id' => $transfer->getId(),
            'source_account_id' => $transfer->getSourceAccount()->getId(),
            'dest_account_id' => $transfer->getDestAccount()->getId(),
            'amount' => $transfer->getAmount(),
            'currency' => $transfer->getCurrency(),
            'status' => $transfer->getStatus()->value,
            'failure_reason' => $transfer->getFailureReason(),
            'created_at' => $transfer->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $transfer->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/accounts/{id}/balance', methods: ['GET'])]
    #[OA\Tag(name: 'Accounts')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))]
    #[OA\Response(
        response: 200,
        description: 'Account balance.',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'account_id', type: 'integer'),
            new OA\Property(property: 'holder_name', type: 'string'),
            new OA\Property(property: 'balance', type: 'integer', description: 'Current balance in minor units.'),
            new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
        ]),
    )]
    #[OA\Response(response: 404, description: 'Account not found.')]
    public function balance(int $id): JsonResponse
    {
        $account = $this->transferService->getAccount($id);

        if ($account === null) {
            return new JsonResponse(['error' => 'Account not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'account_id' => $account->getId(),
            'holder_name' => $account->getHolderName(),
            'balance' => $account->getBalance(),
            'currency' => $account->getCurrency(),
        ]);
    }
}
