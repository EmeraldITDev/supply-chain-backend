<?php

namespace App\Services\Logistics;

use App\Models\Logistics\Trip;
use App\Models\Logistics\TripComment;
use App\Models\User;

class TripCommentService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listForTrip(Trip $trip): array
    {
        return TripComment::query()
            ->where('trip_id', $trip->id)
            ->with('author:id,name,email')
            ->orderBy('created_at')
            ->get()
            ->map(fn (TripComment $comment) => $this->present($comment))
            ->values()
            ->all();
    }

    public function add(Trip $trip, User $user, string $body): TripComment
    {
        $comment = TripComment::create([
            'trip_id' => $trip->id,
            'user_id' => $user->id,
            'body' => $body,
        ]);

        $comment->load('author:id,name,email');

        return $comment;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(TripComment $comment): array
    {
        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'createdAt' => $comment->created_at?->toIso8601String(),
            'created_at' => $comment->created_at?->toIso8601String(),
            'createdBy' => $comment->author ? [
                'id' => $comment->author->id,
                'name' => $comment->author->name,
                'email' => $comment->author->email,
            ] : null,
            'author' => $comment->author ? [
                'id' => $comment->author->id,
                'name' => $comment->author->name,
                'email' => $comment->author->email,
            ] : null,
        ];
    }
}
