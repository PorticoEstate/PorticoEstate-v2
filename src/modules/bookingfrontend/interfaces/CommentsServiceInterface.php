<?php

namespace App\modules\bookingfrontend\interfaces;

/**
 * Interface for comment services
 * Currently focused on application comments, can be extended for other entity types
 */
interface CommentsServiceInterface
{
    /**
     * Get comments for a specific entity
     *
     * @param int $entityId Entity ID (application, event, etc.)
     * @param array $types Comment types to filter by
     * @return array Array of comment objects
     */
    public function getEntityComments(int $entityId, array $types = ['comment']): array;

    /**
     * Add a comment to an entity
     *
     * @param int $entityId Entity ID
     * @param string $comment Comment text
     * @param string $type Comment type
     * @param string|null $author Optional author name
     * @return array The created comment
     */
    public function addComment(int $entityId, string $comment, string $type = 'comment', ?string $author = null): array;


    /**
     * Get comment statistics for an entity
     *
     * @param int $entityId Entity ID
     * @return array Comment statistics
     */
    public function getCommentStats(int $entityId): array;
}