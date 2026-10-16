<?php

namespace App\Http\Controllers;

use App\Services\Conversation\ConversationStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class ConversationController extends Controller
{
    public function create(ConversationStore $store): JsonResponse
    {
        return response()->json(
            $store->create(),
            201,
        );
    }

    public function join(
        Request $request,
        ConversationStore $store,
    ): JsonResponse {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:8', 'alpha_num:ascii'],
        ]);

        try {
            return response()->json(
                $store->join($validated['code']),
            );
        } catch (RuntimeException $exception) {
            if (
                in_array(
                    $exception->getMessage(),
                    [
                        'Conversation not found or expired.',
                        'Conversation is full.',
                    ],
                    true,
                )
            ) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 422);
            }

            throw $exception;
        }
    }
}
