<?php

declare(strict_types=1);

namespace Modulento\Core\Review;

/** A direct account rating as templates show it. */
final class AccountRatingView
{
    /** @return array{id: int, author: string, rating: int, body: string, locale: string, created_at: string, reply: ?string, status: string} */
    public static function of(array $rating): array
    {
        return [
            'id' => (int) $rating['id'],
            // Empty when the rater has no display name or deleted the
            // account; the template then says "an account".
            'author' => $rating['rater_name'],
            'rating' => (int) $rating['rating'],
            'body' => $rating['body'],
            'locale' => $rating['locale'],
            'created_at' => $rating['created_at'],
            'reply' => $rating['reply'],
            'status' => $rating['status'],
        ];
    }

    /** @param array<int, array> $ratings @return array<int, array> */
    public static function all(array $ratings): array
    {
        return array_map([self::class, 'of'], $ratings);
    }
}
