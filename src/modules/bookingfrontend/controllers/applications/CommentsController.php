<?php

namespace App\modules\bookingfrontend\controllers\applications;

use App\modules\bookingfrontend\helpers\ApplicationHelper;
use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\bookingfrontend\services\applications\ApplicationCommentsService;
use App\modules\bookingfrontend\services\applications\ApplicationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Exception;

class CommentsController
{
    private ApplicationCommentsService $commentsService;
    private ApplicationService $applicationService;
    private ApplicationHelper $applicationHelper;

    public function __construct()
    {
        $this->commentsService = new ApplicationCommentsService();
        $this->applicationService = new ApplicationService();
        $this->applicationHelper = new ApplicationHelper();
    }

    /**
     * Get comments for an application
     * GET /api/applications/{id}/comments
     */
    public function getApplicationComments(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $applicationId = (int) $args['id'];
            
            // Verify application exists and user has access
            $application = $this->applicationService->getApplicationById($applicationId);
            if (!$application) {
                return ResponseHelper::sendErrorResponse([
                    'error' => 'Application not found'
                ], 404, $response);
            }

            // Check if user can access this application
            if (!$this->applicationHelper->canViewApplication($application, $request)) {
                return ResponseHelper::sendErrorResponse([
                    'error' => 'Unauthorized to view this application'
                ], 403, $response);
            }

            // Get query parameters
            $queryParams = $request->getQueryParams();
            $types = isset($queryParams['types']) ? explode(',', $queryParams['types']) : ['comment'];
            
            // Get comments
            $comments = $this->commentsService->getApplicationComments($applicationId, $types);
            
            // Get comment statistics
            $stats = $this->commentsService->getCommentStats($applicationId);

            return ResponseHelper::sendJSONResponse([
                'comments' => $comments,
                'stats' => $stats
            ], 200, $response);

        } catch (Exception $e) {
            error_log("Error getting application comments: " . $e->getMessage());
            return ResponseHelper::sendErrorResponse([
                'error' => 'Failed to retrieve comments'
            ], 500, $response);
        }
    }

    /**
     * Add a comment to an application
     * POST /api/applications/{id}/comments
     */
    public function addApplicationComment(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $applicationId = (int) $args['id'];
            
            // Verify application exists and user has access
            $application = $this->applicationService->getApplicationById($applicationId);
            if (!$application) {
                return ResponseHelper::sendErrorResponse([
                    'error' => 'Application not found'
                ], 404, $response);
            }

            // Check if user can add comments to this application
            if (!$this->applicationHelper->canAddComments($application, $request)) {
                return ResponseHelper::sendErrorResponse([
                    'error' => 'Unauthorized to add comments to this application'
                ], 403, $response);
            }

            // Get request body
            $body = $request->getBody()->getContents();
            $data = json_decode($body, true);
            
            if (!$data) {
                return ResponseHelper::sendErrorResponse([
                    'error' => 'Invalid JSON data'
                ], 400, $response);
            }
            
            if (!isset($data['comment']) || empty(trim($data['comment']))) {
                return ResponseHelper::sendErrorResponse([
                    'error' => 'Comment text is required'
                ], 400, $response);
            }

            // Validate comment text
            $comment = trim($data['comment']);
            if (strlen($comment) === 0) {
                return ResponseHelper::sendErrorResponse([
                    'error' => 'Comment cannot be empty'
                ], 400, $response);
            }

            if (strlen($comment) > 10000) {
                return ResponseHelper::sendErrorResponse([
                    'error' => 'Comment is too long (maximum 10000 characters)'
                ], 400, $response);
            }

            // Get comment type (default to 'comment')
            $type = isset($data['type']) && in_array($data['type'], ['comment', 'ownership']) 
                ? $data['type'] 
                : 'comment';

            // Add the comment
            $createdComment = $this->commentsService->addComment($applicationId, $comment, $type);

            return ResponseHelper::sendJSONResponse([
                'comment' => $createdComment,
                'message' => 'Comment added successfully'
            ], 201, $response);

        } catch (Exception $e) {
            error_log("Error adding application comment: " . $e->getMessage());
            return ResponseHelper::sendErrorResponse([
                'error' => 'Failed to add comment'
            ], 500, $response);
        }
    }

    /**
     * Update application status with comment
     * PUT /api/applications/{id}/status
     */
    public function updateApplicationStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $applicationId = (int) $args['id'];
            
            // Verify application exists and user has access
            $application = $this->applicationService->getApplicationById($applicationId);
            if (!$application) {
                return ResponseHelper::sendErrorResponse([
                    'error' => 'Application not found'
                ], 404, $response);
            }

            // Check if user can update status of this application
            if (!$this->applicationHelper->canUpdateStatus($application, $request)) {
                return ResponseHelper::sendErrorResponse([
                    'error' => 'Unauthorized to update status of this application'
                ], 403, $response);
            }

            // Get request body
            $data = json_decode($request->getBody()->getContents(), true);
            
            if (!$data || empty($data['status'])) {
                return ResponseHelper::sendErrorResponse([
                    'error' => 'Status is required'
                ], 400, $response);
            }

            // Validate status
            $allowedStatuses = ['NEW', 'PENDING', 'ACCEPTED', 'REJECTED', 'CANCELLED'];
            $newStatus = strtoupper($data['status']);
            
            if (!in_array($newStatus, $allowedStatuses)) {
                return ResponseHelper::sendErrorResponse([
                    'error' => 'Invalid status. Allowed values: ' . implode(', ', $allowedStatuses)
                ], 400, $response);
            }

            // Get optional additional comment
            $additionalComment = isset($data['comment']) ? trim($data['comment']) : null;
            if ($additionalComment && strlen($additionalComment) > 10000) {
                return ResponseHelper::sendErrorResponse([
                    'error' => 'Comment is too long (maximum 10000 characters)'
                ], 400, $response);
            }

            // Update status and add comments
            $createdComments = $this->commentsService->addStatusChangeComment(
                $applicationId, 
                $newStatus, 
                $additionalComment
            );

            return ResponseHelper::sendJSONResponse([
                'comments' => $createdComments,
                'status' => $newStatus,
                'message' => 'Application status updated successfully'
            ], 200, $response);

        } catch (Exception $e) {
            error_log("Error updating application status: " . $e->getMessage());
            return ResponseHelper::sendErrorResponse([
                'error' => 'Failed to update application status'
            ], 500, $response);
        }
    }


    /**
     * Get comment statistics for an application
     * GET /api/applications/{id}/comments/stats
     */
    public function getApplicationCommentStats(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $applicationId = (int) $args['id'];
            
            // Verify application exists and user has access
            $application = $this->applicationService->getApplicationById($applicationId);
            if (!$application) {
                return ResponseHelper::sendErrorResponse([
                    'error' => 'Application not found'
                ], 404, $response);
            }

            // Check if user can access this application
            if (!$this->applicationHelper->canViewApplication($application, $request)) {
                return ResponseHelper::sendErrorResponse([
                    'error' => 'Unauthorized to view this application'
                ], 403, $response);
            }

            // Get comment statistics
            $stats = $this->commentsService->getCommentStats($applicationId);

            return ResponseHelper::sendJSONResponse($stats, 200, $response);

        } catch (Exception $e) {
            error_log("Error getting application comment stats: " . $e->getMessage());
            return ResponseHelper::sendErrorResponse([
                'error' => 'Failed to retrieve comment statistics'
            ], 500, $response);
        }
    }
}