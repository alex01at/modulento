<?php

declare(strict_types=1);

namespace Modulento\Core\Review;

/** A review as templates show it. */
final class ReviewView
{
    /** @return array{id: int, author: string, rating: int, body: string, locale: string, created_at: string, reply: ?string, replied_at: ?string, status: string} */
    public static function of(array $review): array
    {
        return [
            'id' => (int) $review['id'],
            // Empty when the author has no display name or deleted the
            // account; the template then says "a buyer".
            'author' => $review['author_name'],
            'rating' => (int) $review['rating'],
            'body' => $review['body'],
            'locale' => $review['locale'],
            'created_at' => $review['created_at'],
            'reply' => $review['reply'],
            'replied_at' => $review['replied_at'],
            'status' => $review['status'],
        ];
    }

    /** @param array<int, array> $reviews @return array<int, array> */
    public static function all(array $reviews): array
    {
        return array_map([self::class, 'of'], $reviews);
    }
}
