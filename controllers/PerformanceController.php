<?php

require_once __DIR__ . '/../models/PerformanceGoalModel.php';
require_once __DIR__ . '/../models/PerformanceMonitoringModel.php';
require_once __DIR__ . '/../models/PerformanceTaskModel.php';
require_once __DIR__ . '/../models/PerformanceEvaluationModel.php';
require_once __DIR__ . '/../models/AuthModel.php';
require_once __DIR__ . '/../models/NotificationModel.php';
require_once __DIR__ . '/../models/PerformanceDevelopmentPlanModel.php';
require_once __DIR__ . '/../services/GoalTrainingCascadeService.php';

class PerformanceController
{
    private PerformanceGoalModel $goalModel;
    private PerformanceMonitoringModel $monitoringModel;
    private PerformanceTaskModel $taskModel;
    private PerformanceEvaluationModel $evaluationModel;
    private AuthModel $authModel;
    private NotificationModel $notificationModel;
    private PerformanceDevelopmentPlanModel $devPlanModel;

    public function __construct()
    {
        $this->goalModel = new PerformanceGoalModel();
        $this->monitoringModel = new PerformanceMonitoringModel();
        $this->taskModel = new PerformanceTaskModel();
        $this->evaluationModel = new PerformanceEvaluationModel();
        $this->authModel = new AuthModel();
        $this->notificationModel = new NotificationModel();
        $this->devPlanModel = new PerformanceDevelopmentPlanModel();
    }

    /**
     * Helper to attach dynamic task stats & progress to goals (Optimized 1-query batch)
     */
    private function enrichGoalsWithTasks(array $goals, array $filters = []): array
    {
        if (empty($goals)) {
            return [];
        }

        // 1. Fetch relevant tasks with filter if available to minimize network payload
        $taskFilters = [];
        if (!empty($filters['employee_id'])) {
            $taskFilters['employee_id'] = $filters['employee_id'];
        }
        $allTasks = $this->taskModel->all($taskFilters);

        // 2. Index tasks by goal_id
        $tasksByGoal = [];
        foreach ($allTasks as $t) {
            $gid = (string)($t['goal_id'] ?? '');
            if ($gid !== '') {
                $tasksByGoal[$gid][] = $t;
            }
        }

        // 3. Map aggregated stats in-memory
        foreach ($goals as &$g) {
            $goalId = (string)($g['id'] ?? '');
            $tasks = $tasksByGoal[$goalId] ?? [];
            $totalTasks = count($tasks);
            $completedTasks = 0;
            $generalTasks = [];
            $specificTasks = [];

            foreach ($tasks as $t) {
                if (($t['status'] ?? '') === 'completed') {
                    $completedTasks++;
                }
                if (($t['task_type'] ?? '') === 'general') {
                    $generalTasks[] = $t;
                } else {
                    $specificTasks[] = $t;
                }
            }

            $taskProgress = $totalTasks > 0 ? (int)round(($completedTasks / $totalTasks) * 100) : 0;

            $g['tasks'] = $tasks;
            $g['general_tasks'] = $generalTasks;
            $g['specific_tasks'] = $specificTasks;
            $g['total_tasks'] = $totalTasks;
            $g['completed_tasks'] = $completedTasks;
            $g['task_progress'] = $taskProgress;
            $g['progress'] = $taskProgress; // Dynamic Goal Progress strictly based on completed tasks
        }
        return $goals;
    }

    /**
     * Get list of goals with optional filters (status, employee_id, department)
     */
    public function getGoals(array $payload): array
    {
        $filters = [];
        if (!empty($payload['employee_id'])) {
            $filters['employee_id'] = $payload['employee_id'];
        }
        if (!empty($payload['department'])) {
            $filters['department'] = $payload['department'];
        }
        if (!empty($payload['status'])) {
            $filters['status'] = $payload['status'];
        }

        $goals = $this->goalModel->getGoals($filters);
        $enrichedGoals = $this->enrichGoalsWithTasks($goals, $filters);

        return [
            'success' => true,
            'data'    => $enrichedGoals,
            'count'   => count($enrichedGoals),
            'message' => 'Performance goals retrieved successfully.'
        ];
    }

    /**
     * Create a new performance objective & insert it into the database
     */
    public function createGoal(array $payload): array
    {
        // 1. Validation
        $title = trim($payload['title'] ?? '');
        $department = trim($payload['department'] ?? '');
        $targetMetric = trim($payload['target_metric'] ?? $payload['kpi'] ?? '');
        $targetDate = trim($payload['target_date'] ?? '');

        if (empty($title)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'Validation error: Objective / Goal Title is required.'
            ];
        }

        if (empty($targetMetric)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'Validation error: Target / Success Metric is required.'
            ];
        }

        // 2. Prepare Data & Resolve Real Author from users table
        $employeeId = trim($payload['employee_id'] ?? ($_SESSION['employee_id'] ?? ($_SESSION['user_id'] ?? '')));
        if (empty($employeeId)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'Validation error: Employee ID is required to create a performance objective.'
            ];
        }
        $user = $this->authModel->find($employeeId) ?: $this->authModel->findByEmployeeCode($employeeId);

        if ($user) {
            $authorName = $user['full_name'];
            $role = $user['role'];
            $employeeId = $user['id'];
            if (empty($department)) {
                $department = $user['department'] ?? 'Front Office & Guest Experience';
            }
        } else {
            $authorName = $payload['author_name'] ?? 'Staff Member';
            $role = $payload['role'] ?? 'Associate';
        }

        // Check if employee already has an active (non-completed and non-failed) goal.
        // If the previous goal set by the employee has status 'Failed', 'Completed', or 'Done', allow employee to create another goal.
        $existingGoals = $this->goalModel->getGoalsByEmployee($employeeId);
        $activeGoals = array_filter($existingGoals, function($g) {
            $status = strtolower(trim($g['status'] ?? ''));
            return $status !== 'completed' && $status !== 'done' && $status !== 'failed';
        });

        if (!empty($activeGoals)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => "Employees can have only 1 active in-progress goal. Complete, finish, or conclude existing active goals before setting a new one."
            ];
        }

        $targetScope = $payload['target_scope'] ?? 'single';

        $data = [
            'employee_id'   => $employeeId,
            'role'          => $role,
            'target_scope'  => $targetScope,
            'title'         => $title,
            'department'    => $department ?: 'Front Office & Guest Experience',
            'target_date'   => $targetDate ?: date('Y-m-d', strtotime('+30 days')),
            'target_metric' => $targetMetric,
            'weight'        => $payload['weight'] ?? 'Medium Priority (20% Weight)',
            'evidence'      => $payload['evidence'] ?? null,
            'status'        => $payload['status'] ?? 'Pending Approval',
            'supervisor_id' => $payload['supervisor_id'] ?? null,
            'supervisor_notes' => $payload['supervisor_notes'] ?? null
        ];

        $created = $this->goalModel->createGoal($data);

        if (!empty($created['error'])) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'Database error: ' . ($created['error'] ?? 'Failed to save goal to database')
            ];
        }

        // Auto-assign general tasks matrix checklist to newly set goal
        if (!empty($created['id'])) {
            try {
                $goalEmpId = $created['employee_id'] ?? $employeeId;
                $this->taskModel->assignGeneralTasksToGoal($created['id'], $goalEmpId, $data['target_date']);
            } catch (\Throwable $e) {
                error_log('Task assignment error: ' . $e->getMessage());
            }
        }

        // 3. Dynamic Notification based on real author role
        try {
            $goalId = !empty($created['id']) && is_numeric($created['id']) ? (int)$created['id'] : null;
            if (strcasecmp($role, 'Supervisor') === 0 || strcasecmp($role, 'Manager') === 0) {
                // If Supervisor created a goal, notify the assigned associate
                $targetAssociateId = !empty($employeeId) ? $employeeId : ($created['employee_id'] ?? null);
                $this->notificationModel->createNotification([
                    'recipient_role' => 'Associate',
                    'user_id'        => $targetAssociateId,
                    'type'           => 'goal_created',
                    'title'          => 'New Department Objective Set 📋',
                    'message'        => "Supervisor {$authorName} established new target \"{$title}\" ({$data['department']}).",
                    'related_id'     => $created['id'] ?? null,
                    'goal_id'        => $goalId
                ]);
            } else {
                // If Associate created a goal, notify department Supervisors (role-broadcast)
                $this->notificationModel->createNotification([
                    'recipient_role' => 'Supervisor',
                    'user_id'        => null,
                    'type'           => 'goal_created',
                    'title'          => 'New Performance Objective Submitted',
                    'message'        => "Associate {$authorName} submitted a new performance target: \"{$title}\" ({$data['department']}). Awaiting supervisor calibration.",
                    'related_id'     => $created['id'] ?? null,
                    'goal_id'        => $goalId
                ]);
            }
        } catch (\Throwable $e) {
            error_log('Notification creation error: ' . $e->getMessage());
        }

        return [
            'success' => true,
            'data'    => $created,
            'message' => "Performance objective \"{$title}\" successfully set and saved to database."
        ];
    }

    /**
     * Update status of a goal (Approve, Needs Revision, Completed)
     */
    public function updateGoalStatus(array $payload): array
    {
        $id = $payload['id'] ?? null;
        $status = $payload['status'] ?? '';
        $notes = $payload['supervisor_notes'] ?? null;

        if (empty($id) || empty($status)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'Validation error: Goal ID and new status are required.'
            ];
        }

        $updated = $this->goalModel->updateStatus((string)$id, $status, $notes);

        if (!$updated) {
            return [
                'success' => false,
                'data'    => null,
                'message' => "Goal with ID {$id} not found."
            ];
        }

        // When approved, verify that general tasks are assigned
        if (strcasecmp($status, 'Approved') === 0 && !empty($updated['employee_id'])) {
            try {
                $this->taskModel->assignGeneralTasksToGoal(
                    $id,
                    $updated['employee_id'],
                    $updated['target_date'] ?? date('Y-m-d', strtotime('+30 days'))
                );
            } catch (\Throwable $e) {
                error_log('Task assignment error on approval: ' . $e->getMessage());
            }
        }

        // Cascade: when goal fails, create a training need (if under retry cap)
        if (strcasecmp($status, 'Failed') === 0) {
            try {
                $cascadeService = new GoalTrainingCascadeService();
                $cascadeService->onGoalFailed((string)$id);
            } catch (\Throwable $e) {
                error_log('Cascade error on goal failure: ' . $e->getMessage());
            }
        }

        // Look up owner dynamically from users table
        $ownerId = $updated['employee_id'] ?? '';
        $owner = !empty($ownerId) ? ($this->authModel->find($ownerId) ?: $this->authModel->findByEmployeeCode($ownerId)) : null;
        $ownerName = $owner['full_name'] ?? 'Associate';

        // Create Notification for Associate when approved
        if (strcasecmp($status, 'Approved') === 0) {
            try {
                $this->notificationModel->createNotification([
                    'recipient_role' => $owner['role'] ?? 'Associate',
                    'user_id'        => $ownerId,
                    'type'           => 'goal_approved',
                    'title'          => 'Objective Approved & Tasks Assigned! ',
                    'message'        => "Performance objective \"{$updated['title']}\" for {$ownerName} was approved. Task checklist is active.",
                    'related_id'     => $id,
                    'goal_id'        => is_numeric($id) ? (int)$id : null
                ]);
            } catch (\Throwable $e) {
                error_log('Notification error: ' . $e->getMessage());
            }
        }

        return [
            'success' => true,
            'data'    => $updated,
            'message' => "Goal status successfully updated to '{$status}'."
        ];
    }

    /**
     * Revise / Update full goal details in database
     */
    public function updateGoal(array $payload): array
    {
        $id = $payload['id'] ?? null;
        if (empty($id)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'Goal ID is required to perform update/revision.'
            ];
        }

        $updated = $this->goalModel->updateGoal((string)$id, $payload);

        if (!$updated) {
            return [
                'success' => false,
                'data'    => null,
                'message' => "Failed to update goal with ID '{$id}' in database."
            ];
        }

        // Look up owner dynamically from users table
        $ownerId = $updated['employee_id'] ?? '';
        $owner = !empty($ownerId) ? ($this->authModel->find($ownerId) ?: $this->authModel->findByEmployeeCode($ownerId)) : null;

        // Create Notification for Associate when goal is revised
        try {
            $notesMsg = !empty($updated['supervisor_notes']) ? " Note: \"{$updated['supervisor_notes']}\"" : "";
            $this->notificationModel->createNotification([
                'recipient_role' => $owner['role'] ?? 'Associate',
                'user_id'        => $ownerId,
                'type'           => 'goal_revised',
                'title'          => 'Performance Objective Revised ✍️',
                'message'        => "Your performance objective \"{$updated['title']}\" was revised. Target Metric: {$updated['target_metric']}.{$notesMsg}",
                'related_id'     => $id,
                'goal_id'        => is_numeric($id) ? (int)$id : null
            ]);
        } catch (\Throwable $e) {
            error_log('Notification error: ' . $e->getMessage());
        }

        return [
            'success' => true,
            'data'    => $updated,
            'message' => 'Performance goal objectives successfully revised and updated in database.'
        ];
    }

    /**
     * Get aggregate Planning Stage Data (Goals + Summary KPIs)
     */
    public function getPlanningData(array $payload = []): array
    {
        $goals = $this->goalModel->getGoals();
        $enrichedGoals = $this->enrichGoalsWithTasks($goals);
        $generalTasks = $this->taskModel->getGeneralTasks();

        $activeCount = count($enrichedGoals);
        $approvedCount = 0;
        $pendingCount = 0;

        foreach ($enrichedGoals as $g) {
            if (($g['status'] ?? '') === 'Approved') {
                $approvedCount++;
            } else {
                $pendingCount++;
            }
        }

        $draftSummaries = $this->devPlanModel->getAllDraftSummaries();

        return [
            'success' => true,
            'data'    => [
                'goals'          => $enrichedGoals,
                'general_tasks'  => $generalTasks,
                'draft_plans'    => $draftSummaries,
                'employees'      => $this->authModel->all(),
                'total_goals'    => $activeCount,
                'approved_count' => $approvedCount,
                'pending_count'  => $pendingCount,
                'calibration'    => $activeCount > 0 ? round(($approvedCount / $activeCount) * 100) . '%' : '100%'
            ],
            'message' => 'Performance planning data loaded.'
        ];
    }

    // =========================================================================
    // General Tasks CRUD (Supervisor Matrix)
    // =========================================================================

    public function getGeneralTasks(array $payload = []): array
    {
        $tasks = $this->taskModel->getGeneralTasks();
        return [
            'success' => true,
            'data'    => $tasks,
            'count'   => count($tasks),
            'message' => 'General tasks matrix retrieved successfully.'
        ];
    }

    public function createGeneralTask(array $payload): array
    {
        $title = trim($payload['title'] ?? '');
        if (empty($title)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'Validation error: Task title is required.'
            ];
        }

        $created = $this->taskModel->createGeneralTask($payload);

        // Optionally distribute to all active goals
        $goals = $this->goalModel->getGoals();
        foreach ($goals as $g) {
            $goalId = $g['id'] ?? null;
            $empId = $g['employee_id'] ?? null;
            $gDate = $g['target_date'] ?? date('Y-m-d', strtotime('+30 days'));
            if ($goalId && $empId) {
                $this->taskModel->assignGeneralTasksToGoal($goalId, $empId, $gDate);
            }
        }

        return [
            'success' => true,
            'data'    => $created,
            'message' => "General task \"{$title}\" created and added to the employee checklist matrix."
        ];
    }

    public function updateGeneralTask(array $payload): array
    {
        $id = $payload['id'] ?? null;
        if (empty($id)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'Task ID is required for update.'
            ];
        }

        $updated = $this->taskModel->updateGeneralTask((string)$id, $payload);
        if (!$updated) {
            return [
                'success' => false,
                'data'    => null,
                'message' => "General task with ID {$id} not found."
            ];
        }

        return [
            'success' => true,
            'data'    => $updated,
            'message' => 'General task updated successfully.'
        ];
    }

    public function deleteGeneralTask(array $payload): array
    {
        $id = $payload['id'] ?? null;
        if (empty($id)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'Task ID is required for deletion.'
            ];
        }

        $deleted = $this->taskModel->deleteGeneralTask((string)$id);
        return [
            'success' => $deleted,
            'message' => $deleted ? 'General task removed from matrix.' : 'Failed to delete general task.'
        ];
    }

    // =========================================================================
    // Concrete Goal Tasks (General + Specific Checklists & Completion)
    // =========================================================================

    public function getGoalTasks(array $payload): array
    {
        $goalId = $payload['goal_id'] ?? $payload['id'] ?? null;
        $employeeId = $payload['employee_id'] ?? null;

        if ($goalId) {
            $tasks = $this->taskModel->getTasksForGoal($goalId);
        } elseif ($employeeId) {
            $tasks = $this->taskModel->getTasksForEmployee($employeeId);
        } else {
            $tasks = $this->taskModel->all();
        }

        return [
            'success' => true,
            'data'    => $tasks,
            'count'   => count($tasks),
            'message' => 'Goal tasks retrieved successfully.'
        ];
    }

    public function createSpecificTask(array $payload = []): array
    {
        $goalId = $payload['goal_id'] ?? null;
        $empId = trim($payload['employee_id'] ?? ($_SESSION['employee_id'] ?? ($_SESSION['user_id'] ?? '')));
        $tasksList = $payload['tasks'] ?? [];

        if (empty($goalId)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'Validation error: Goal ID is required.'
            ];
        }

        $goal = $this->goalModel->find((string)$goalId);
        if ($goal) {
            $gst = strtolower(trim($goal['status'] ?? ''));
            if ($gst === 'done' || $gst === 'completed' || $gst === 'failed') {
                return [
                    'success' => false,
                    'data'    => null,
                    'message' => "Cannot add task: Objective #{$goalId} is already marked as '{$goal['status']}'."
                ];
            }
        }
        $goalTargetDate = $goal['target_date'] ?? null;

        // If multiple tasks submitted as an array
        if (!empty($tasksList) && is_array($tasksList)) {
            $createdTasks = [];
            foreach ($tasksList as $taskItem) {
                $title = trim($taskItem['title'] ?? '');
                if (empty($title)) continue;
                $taskData = [
                    'goal_id'     => $goalId,
                    'employee_id' => $empId,
                    'title'       => $title,
                    'target_date' => $taskItem['target_date'] ?? null,
                    'description' => $taskItem['description'] ?? ''
                ];
                $createdTasks[] = $this->taskModel->createSpecificTask($taskData, $goalTargetDate);
            }

            if (empty($createdTasks)) {
                return [
                    'success' => false,
                    'data'    => null,
                    'message' => 'Validation error: Please provide at least one valid task title.'
                ];
            }

            // Notification
            try {
                $count = count($createdTasks);
                $this->notificationModel->createNotification([
                    'recipient_role' => 'Associate',
                    'user_id'        => $empId,
                    'type'           => 'task_assigned',
                    'title'          => "Specific Action Tasks Assigned ({$count}) 📋",
                    'message'        => "Supervisor assigned {$count} new action tasks to your performance objective.",
                    'related_id'     => $createdTasks[0]['id'] ?? null,
                    'goal_id'        => is_numeric($goalId) ? (int)$goalId : null
                ]);
            } catch (\Throwable $e) {
                error_log('Notification error: ' . $e->getMessage());
            }

            return [
                'success' => true,
                'data'    => $createdTasks,
                'message' => count($createdTasks) . " specific tasks successfully assigned to Goal #{$goalId}."
            ];
        }

        // Single task fallback
        $title = trim($payload['title'] ?? '');
        if (empty($title)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'Validation error: Task Title is required.'
            ];
        }

        $created = $this->taskModel->createSpecificTask($payload, $goalTargetDate);

        // Notify employee of specific task assigned by supervisor
        try {
            $this->notificationModel->createNotification([
                'recipient_role' => 'Associate',
                'user_id'        => $empId,
                'type'           => 'task_assigned',
                'title'          => 'Specific Goal Task Assigned 📋',
                'message'        => "Supervisor assigned specific task: \"{$title}\" (Due: {$created['target_date']}).",
                'related_id'     => $created['id'] ?? null,
                'goal_id'        => is_numeric($goalId) ? (int)$goalId : null
            ]);
        } catch (\Throwable $e) {
            error_log('Notification error: ' . $e->getMessage());
        }

        return [
            'success' => true,
            'data'    => $created,
            'message' => "Specific task \"{$title}\" created and assigned to Goal #{$goalId}."
        ];
    }

    /**
     * Complete a task with Employee Learnings, Operational Feedback, and Timestamp
     */
    public function completeTask(array $payload): array
    {
        $taskId = $payload['id'] ?? $payload['task_id'] ?? null;
        $learnings = trim($payload['employee_learnings'] ?? $payload['learnings'] ?? '');
        $feedback = trim($payload['employee_feedback'] ?? $payload['feedback'] ?? '');
        $completedAt = $payload['completed_at'] ?? date('c');

        if (empty($taskId)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'Task ID is required to mark task complete.'
            ];
        }

        // Enforce 100% LMS progress check if this task is an LMS module
        $existingTask = $this->taskModel->find($taskId);
        if ($existingTask) {
            if (!empty($existingTask['goal_id'])) {
                $parentGoal = $this->goalModel->find((string)$existingTask['goal_id']);
                if ($parentGoal) {
                    $pst = strtolower(trim($parentGoal['status'] ?? ''));
                    if ($pst === 'done' || $pst === 'completed' || $pst === 'failed') {
                        return [
                            'success' => false,
                            'data'    => null,
                            'message' => "Cannot complete task: Associated objective is already marked as '{$parentGoal['status']}'."
                        ];
                    }
                }
            }
            $desc = $existingTask['description'] ?? '';
            $title = $existingTask['title'] ?? '';
            $presId = $existingTask['prescribed_lms_id'] ?? null;
            $lmsId = null;
            if (preg_match('/\[LMS:([^\]]+)\]/', $desc, $matches)) {
                $lmsId = trim($matches[1]);
            } elseif (preg_match('/\[LMS:([^\]]+)\]/', $title, $matches)) {
                $lmsId = trim($matches[1]);
            }

            if (!empty($presId) || !empty($lmsId)) {
                $empId = $existingTask['employee_id'] ?? ($_SESSION['employee_id'] ?? ($_SESSION['user_id'] ?? ''));
                $checkPres = null;
                if (!empty($presId)) {
                    $checkPres = supabaseRequest('lms_prescribed?id=eq.' . urlencode($presId), 'GET', null, true);
                }
                if (empty($checkPres['data']) && !empty($lmsId)) {
                    $checkPres = supabaseRequest('lms_prescribed?employee=eq.' . urlencode($empId) . '&lms_id=eq.' . urlencode($lmsId), 'GET', null, true);
                }
                $presList = is_array($checkPres['data'] ?? null) ? $checkPres['data'] : [];
                $pres = !empty($presList) ? $presList[0] : null;

                $progress = $pres ? (int)($pres['progress'] ?? 0) : 0;
                $status = strtolower($pres['status'] ?? '');
                $isCompleted = ($progress >= 100) || in_array($status, ['passed', 'completed', 'cert']);

                if (!$isCompleted) {
                    return [
                        'success' => false,
                        'data'    => null,
                        'message' => "LMS Progress Requirement: You must achieve 100% progress in the prescribed LMS Handbook (\"{$title}\") before completing this task! (Current progress: {$progress}%)"
                    ];
                }
            }
        }

        $updatedTask = $this->taskModel->completeTask($taskId, $learnings, $feedback, $completedAt);
        if (!$updatedTask) {
            return [
                'success' => false,
                'data'    => null,
                'message' => "Task with ID {$taskId} not found."
            ];
        }

        // Calculate new goal progress
        $goalId = $updatedTask['goal_id'] ?? null;
        $newProgress = $goalId ? $this->taskModel->calculateGoalProgress($goalId) : 100;

        // Auto-log to performance_monitoring stream
        try {
            $this->monitoringModel->logMilestone([
                'goal_id'             => $goalId,
                'employee_id'         => $updatedTask['employee_id'] ?? ($_SESSION['employee_id'] ?? ($_SESSION['user_id'] ?? '')),
                'milestone_title'     => 'Task Completed: ' . $updatedTask['title'],
                'actual_metric'       => "Goal Progress: {$newProgress}%",
                'progress'            => $newProgress,
                'accomplishments'     => $learnings ?: 'Completed assigned operational checklist.',
                'challenges'          => null,
                'feedback'            => $feedback,
                'supporting_evidence' => 'Task Checklist Verified'
            ]);
        } catch (\Throwable $e) {
            error_log('Auto log milestone error: ' . $e->getMessage());
        }

        // Notify supervisor
        try {
            $this->notificationModel->createNotification([
                'recipient_role' => 'Supervisor',
                'user_id'        => null,
                'type'           => 'task_completed',
                'title'          => 'Task Completed with Feedback ✨',
                'message'        => "Employee completed \"{$updatedTask['title']}\" and submitted learnings & feedback.",
                'related_id'     => $taskId,
                'goal_id'        => is_numeric($goalId) ? (int)$goalId : null
            ]);
        } catch (\Throwable $e) {
            error_log('Notification error: ' . $e->getMessage());
        }

        return [
            'success'       => true,
            'data'          => $updatedTask,
            'goal_progress' => $newProgress,
            'message'       => "Task completed successfully! Learnings and feedback logged at {$completedAt}."
        ];
    }

    /**
     * Add supervisor coaching feedback & recorded accomplishment
     */
    public function addSupervisorTaskFeedback(array $payload): array
    {
        $taskId = $payload['id'] ?? $payload['task_id'] ?? null;
        $accomplishment = $payload['supervisor_accomplishment'] ?? $payload['accomplishments'] ?? null;
        $coachingFeedback = $payload['supervisor_feedback'] ?? $payload['coaching_feedback'] ?? null;

        if (empty($taskId)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'Task ID is required.'
            ];
        }

        $updated = $this->taskModel->addSupervisorFeedback($taskId, $accomplishment, $coachingFeedback);
        if (!$updated) {
            return [
                'success' => false,
                'data'    => null,
                'message' => "Task with ID {$taskId} not found."
            ];
        }

        return [
            'success' => true,
            'data'    => $updated,
            'message' => 'Supervisor coaching feedback and accomplishments recorded successfully.'
        ];
    }

    /**
     * Delete a task from a goal
     */
    public function deleteTask(array $payload): array
    {
        $taskId = $payload['id'] ?? $payload['task_id'] ?? null;
        if (empty($taskId)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'Task ID is required to delete task.'
            ];
        }

        $deleted = $this->taskModel->deleteTask((string)$taskId);
        return [
            'success' => $deleted,
            'data'    => ['id' => $taskId],
            'message' => $deleted ? 'Task successfully deleted.' : 'Failed to delete task.'
        ];
    }

    /**
     * Reset a task back to pending so employee can re-do it
     */
    public function resetTask(array $payload): array
    {
        $taskId = $payload['id'] ?? $payload['task_id'] ?? null;
        if (empty($taskId)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'Task ID is required to reset task.'
            ];
        }

        $updated = $this->taskModel->resetTask((string)$taskId);
        if (!$updated) {
            return [
                'success' => false,
                'data'    => null,
                'message' => "Task with ID {$taskId} not found."
            ];
        }

        return [
            'success' => true,
            'data'    => $updated,
            'message' => 'Task reset to pending. Employee can now re-execute.'
        ];
    }

    /**
     * Log a shift milestone & actual KPI progress for a goal (Stage 3 Monitoring)
     */
    public function logMilestone(array $payload): array
    {
        $id = $payload['id'] ?? $payload['goal_id'] ?? null;
        $milestoneTitle = trim($payload['milestone_title'] ?? $payload['title'] ?? '');
        $actualMetric = trim($payload['actual_metric'] ?? '');
        $progress = isset($payload['progress']) ? (int)$payload['progress'] : 85;
        $accomplishments = trim($payload['accomplishments'] ?? '');
        $challenges = trim($payload['challenges'] ?? '');
        $feedback = trim($payload['feedback'] ?? '');
        $supportingEvidence = trim($payload['supporting_evidence'] ?? $payload['evidence'] ?? '');
        $notes = trim($payload['notes'] ?? $payload['supervisor_notes'] ?? '');
        $empId = trim($payload['employee_id'] ?? ($_SESSION['employee_id'] ?? ($_SESSION['user_id'] ?? '')));

        if (empty($id)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'Goal ID is required to log a milestone.'
            ];
        }

        $existing = $this->goalModel->find((string)$id);
        $evidenceUpdate = $actualMetric ? "Milestone: {$milestoneTitle} | Actual: {$actualMetric}" : $milestoneTitle;
        if (!empty($accomplishments)) {
            $evidenceUpdate .= " | Accomplishment: {$accomplishments}";
        }
        if (!empty($supportingEvidence)) {
            $evidenceUpdate .= " | Evidence: {$supportingEvidence}";
        }
        if (!empty($existing['evidence'])) {
            $evidenceUpdate = $existing['evidence'] . "\n• " . $evidenceUpdate;
        }

        $updateData = [
            'evidence' => $evidenceUpdate
        ];
        if (!empty($notes) || !empty($feedback)) {
            $updateData['supervisor_notes'] = $notes ?: $feedback;
        }
        // Note: Goal status stays 'Approved' throughout Monitoring (Stage 3) and Appraisal (Stage 4).
        // It is formally marked 'Completed' only at Stage 7 cycle finalization.

        $updated = $this->goalModel->update((string)$id, $updateData);

        // Insert milestone record into performance_monitoring table with foreign keys
        try {
            $this->monitoringModel->logMilestone([
                'goal_id'             => $id,
                'employee_id'         => $empId,
                'milestone_title'     => $milestoneTitle,
                'actual_metric'       => $actualMetric,
                'progress'            => $progress,
                'accomplishments'     => $accomplishments,
                'challenges'          => $challenges,
                'feedback'            => $feedback,
                'supporting_evidence' => $supportingEvidence,
                'supervisor_notes'    => $notes
            ]);
        } catch (\Throwable $e) {
            error_log('Error logging to performance_monitoring: ' . $e->getMessage());
        }

        // Send real-time notification to Associate & Supervisor
        try {
            $this->notificationModel->createNotification([
                'recipient_role' => 'Associate',
                'user_id'        => $empId,
                'type'           => 'milestone_logged',
                'title'          => 'Shift Milestone Logged 🎯',
                'message'        => "Milestone \"{$milestoneTitle}\" recorded with metric \"{$actualMetric}\" ({$progress}% Target Progress).",
                'related_id'     => $id,
                'goal_id'        => is_numeric($id) ? (int)$id : null
            ]);
        } catch (\Throwable $e) {
            error_log('Milestone notification error: ' . $e->getMessage());
        }

        return [
            'success' => true,
            'data'    => $updated,
            'message' => "Milestone successfully recorded for Goal #{$id}."
        ];
    }

    /**
     * Get dynamic Monitoring Stage Data strictly built from approved performance_goals, tasks & monitoring
     */
    public function getMonitoringData(array $payload = []): array
    {
        $allGoals = $this->goalModel->getGoals();
        $enrichedGoals = $this->enrichGoalsWithTasks($allGoals);
        $allLogs = $this->monitoringModel->getMonitoringLogs();
        $allTasks = $this->taskModel->all();
        $users = $this->authModel->all();

        // Filter active approved and in-progress goals for Phase 3-7 Monitoring and Evaluation
        $approvedGoals = array_values(array_filter($enrichedGoals, function ($g) {
            $st = strtolower(trim($g['status'] ?? ''));
            return in_array($st, ['approved', 'in progress', 'in_progress', 'completed', 'done']);
        }));

        // Map users by id and employee_code
        $userMap = [];
        foreach ($users as $u) {
            if (!empty($u['id'])) $userMap[strtolower($u['id'])] = $u;
            if (!empty($u['employee_code'])) $userMap[strtolower($u['employee_code'])] = $u;
        }

        // Group approved goals strictly by employee_id from performance_goals table
        $empGoalsMap = [];
        foreach ($approvedGoals as $g) {
            $eId = strtolower(trim($g['employee_id'] ?? ''));
            if (empty($eId)) continue;
            if (!isset($empGoalsMap[$eId])) {
                $empGoalsMap[$eId] = [];
            }
            $empGoalsMap[$eId][] = $g;
        }

        // Build roster dynamically ONLY for employees with approved performance goals
        $roster = [];
        foreach ($empGoalsMap as $eId => $goals) {
            $user = $userMap[$eId] ?? null;
            $name = $user['full_name'] ?? ucfirst($eId);
            $pos = $user['title'] ?? ($goals[0]['department'] ?? 'Associate');
            $dept = $goals[0]['department'] ?? ($user['department'] ?? 'Hotel Operations');
            
            // Calculate dynamic progress directly from task completion ratios of approved goals
            $totalGoalProgress = 0;
            $approvedCount = count($goals);

            foreach ($goals as $g) {
                $totalGoalProgress += ($g['task_progress'] ?? 0);
            }

            $overallProgress = count($goals) > 0 ? (int)round($totalGoalProgress / count($goals)) : 0;
            $statusStr = $overallProgress >= 90 ? 'Exceeding' : ($overallProgress >= 70 ? 'On Track' : 'Needs Support');

            $empLogs = array_values(array_filter($allLogs, fn($l) => strtolower(trim($l['employee_id'] ?? '')) === $eId));
            $empTasks = array_values(array_filter($allTasks, fn($t) => strtolower(trim($t['employee_id'] ?? '')) === $eId));

            $primaryGoalId = !empty($goals[0]['id']) ? (int)$goals[0]['id'] : null;
            $evalRecord = $this->evaluationModel->getEvaluationByEmployee($eId, $primaryGoalId);
            $evalStatus = $evalRecord['status'] ?? 'Pending';
            $selfRating = isset($evalRecord['self_evaluation']) && $evalRecord['self_evaluation'] !== null && (float)$evalRecord['self_evaluation'] > 0
                ? (float)$evalRecord['self_evaluation']
                : (isset($evalRecord['self_rating']) && $evalRecord['self_rating'] !== null ? (float)$evalRecord['self_rating'] : 0.0);
            $mgrRating = isset($evalRecord['supervisor_rating']) && $evalRecord['supervisor_rating'] !== null && (float)$evalRecord['supervisor_rating'] > 0 ? (float)$evalRecord['supervisor_rating'] : 0.0;
            $tierLabel = $evalRecord['tier_label'] ?? ($mgrRating >= 4.5 ? 'Master Tier' : ($mgrRating >= 3.0 ? 'Proficient' : 'Pending Evaluation'));

            $roster[] = [
                    'id'                 => $eId,
                    'name'               => $name,
                    'position'           => $pos,
                    'department'         => $dept,
                    'avatar'             => strtoupper(substr($name, 0, 2)),
                    'avatarBg'           => $eId === 'emp-102' ? 'bg-amber-600' : 'bg-primary',
                    'attendance'         => ['present' => 22, 'absent' => 1, 'total' => 23, 'percentage' => '95.6%'],
                    'shift'              => 'Morning',
                    'attendanceStatus'   => 'Present',
                    'monitoringProgress' => $overallProgress,
                    'monitoringStatus'   => $statusStr,
                    'selfRating'         => $selfRating,
                    'supervisorRating'   => $mgrRating,
                    'evaluationStatus'   => $evalStatus,
                    'tierLabel'          => $tierLabel,
                    'reviewStatus'       => $evalStatus === 'Calibrated' ? 'Calibrated' : ($mgrRating > 0 ? 'Pending Calibration' : 'Pending Evaluation'),
                    'goals'              => $goals,
                    'logs'               => $empLogs,
                    'tasks'              => $empTasks,
                    'evaluationRecord'   => $evalRecord
            ];
        }

        return [
            'success' => true,
            'data'    => [
                'roster'       => $roster,
                'logs'         => $allLogs,
                'total_goals'  => count($approvedGoals),
                'total_logs'   => count($allLogs),
                'total_tasks'  => count($allTasks),
                'count'        => count($roster)
            ],
            'message' => 'Dynamic monitoring stage data retrieved successfully.'
        ];
    }

    // =========================================================================
    // 10. EVALUATION & MULTI-FACTOR APPRAISAL (DATABASE DRIVEN)
    // =========================================================================

    /**
     * Get all performance evaluations from database
     */
    public function getEvaluations(array $payload = []): array
    {
        $evaluations = $this->evaluationModel->getEvaluations($payload);
        return [
            'success' => true,
            'data'    => [
                'evaluations' => $evaluations,
                'total'       => count($evaluations)
            ],
            'message' => 'Evaluations retrieved successfully from database.'
        ];
    }

    /**
     * Get single employee evaluation with approved goals & criteria
     */
    public function getEvaluation(array $payload): array
    {
        $empId = trim($payload['employee_id'] ?? ($payload['id'] ?? ($_SESSION['employee_id'] ?? ($_SESSION['user_id'] ?? ''))));
        $goalId = !empty($payload['goal_id']) ? (int)$payload['goal_id'] : null;
        $evaluation = !empty($empId) ? $this->evaluationModel->getEvaluationByEmployee($empId, $goalId) : null;

        // Fetch employee's approved goals to construct criteria
        $allGoals = !empty($empId) ? $this->enrichGoalsWithTasks($this->goalModel->getGoalsByEmployee($empId)) : [];
        $approvedGoals = array_values(array_filter($allGoals, function ($g) {
            $st = strtolower(trim($g['status'] ?? ''));
            return in_array($st, ['approved', 'completed']);
        }));

        return [
            'success' => true,
            'data'    => [
                'evaluation' => $evaluation,
                'goals'      => $approvedGoals
            ],
            'message' => "Evaluation for {$empId} retrieved successfully."
        ];
    }

    /**
     * Submit supervisor appraisal scoring & save to database
     */
    public function submitAppraisal(array $payload): array
    {
        $empId = trim($payload['employee_id'] ?? ($_SESSION['employee_id'] ?? ($_SESSION['user_id'] ?? '')));
        $saved = $this->evaluationModel->saveSupervisorAppraisal($payload);

        // Create notification for employee
        $score = $saved['supervisor_rating'] ?? 4.60;
        if (!empty($empId)) {
            $this->notificationModel->create([
                'id' => 'notif-' . bin2hex(random_bytes(4)),
                'user_id' => $empId,
                'type' => 'performance',
                'title' => 'Performance Appraisal Endorsed',
                'message' => "Your supervisor has submitted and endorsed your formal performance appraisal with an overall score of {$score} / 5.0 ({$saved['tier_label']}).",
                'is_read' => false,
                'created_at' => date('c')
            ]);
        }

        return [
            'success' => true,
            'data'    => $saved,
            'message' => "Formal appraisal successfully saved to database with score {$score}."
        ];
    }

    /**
     * Generate AI Appraisal Recommendations for un-evaluated employees
     * Analyzes:
     * - Supervisor Coaching & Notes
     * - Employee Feedback
     * - Employee Learnings & Reflections
     * - Logged Shift Milestones & KPI Records
     */
    public function generateAppraisalRecommendations(array $payload = []): array
    {
        $allGoals = $this->goalModel->getGoals();
        $enrichedGoals = $this->enrichGoalsWithTasks($allGoals);
        $allLogs = $this->monitoringModel->getMonitoringLogs();
        $allEvaluations = $this->evaluationModel->all();
        $users = $this->authModel->all();

        // Build map of evaluated employee + goal pairs
        $evaluatedGoalMap = [];
        foreach ($allEvaluations as $ev) {
            $eId = strtolower(trim($ev['employee_id'] ?? ''));
            $gId = !empty($ev['goal_id']) ? (string)$ev['goal_id'] : 'primary';
            $supRating = isset($ev['supervisor_rating']) ? (float)$ev['supervisor_rating'] : 0.0;
            if ($supRating > 0) {
                $evaluatedGoalMap[$eId . '_' . $gId] = true;
                $evaluatedGoalMap[$eId] = true;
            }
        }

        // Build user map
        $userMap = [];
        foreach ($users as $u) {
            if (!empty($u['id'])) $userMap[strtolower($u['id'])] = $u;
            if (!empty($u['employee_code'])) $userMap[strtolower($u['employee_code'])] = $u;
        }

        $targetEmpIds = !empty($payload['employee_ids']) ? (array)$payload['employee_ids'] : null;
        if ($targetEmpIds) {
            $targetEmpIds = array_map(fn($id) => strtolower(trim((string)$id)), $targetEmpIds);
        }

        $recommendations = [];

        foreach ($enrichedGoals as $goal) {
            $eId = strtolower(trim($goal['employee_id'] ?? ''));
            $gId = (string)($goal['id'] ?? '');
            $gStatus = strtolower(trim($goal['status'] ?? ''));

            if (empty($eId) || !in_array($gStatus, ['approved', 'in progress', 'in_progress', 'completed', 'done'])) {
                continue;
            }

            if ($targetEmpIds && !in_array($eId, $targetEmpIds)) {
                continue;
            }

            // Check if already evaluated
            $isAlreadyEvaluated = isset($evaluatedGoalMap[$eId . '_' . $gId]) || isset($evaluatedGoalMap[$eId]);
            if ($isAlreadyEvaluated && empty($payload['include_evaluated'])) {
                continue;
            }

            $user = $userMap[$eId] ?? null;
            $name = $user['full_name'] ?? ($user['name'] ?? ucfirst($eId));
            $pos = $user['title'] ?? ($goal['department'] ?? 'Associate');
            $dept = $goal['department'] ?? ($user['department'] ?? 'Hotel Operations');

            $tasks = $goal['tasks'] ?? [];
            $completedTasks = array_values(array_filter($tasks, fn($t) => ($t['status'] ?? '') === 'completed'));
            $totalTasks = count($tasks);

            // 1. Extract Supervisor Coaching & Notes
            $supervisorNotesList = [];
            if (!empty($goal['supervisor_notes'])) {
                $supervisorNotesList[] = $goal['supervisor_notes'];
            }
            foreach ($tasks as $t) {
                if (!empty($t['supervisor_feedback'])) $supervisorNotesList[] = $t['supervisor_feedback'];
                if (!empty($t['supervisor_accomplishment'])) $supervisorNotesList[] = $t['supervisor_accomplishment'];
            }

            // 2. Extract Employee Feedback
            $employeeFeedbackList = [];
            if (!empty($goal['feedback'])) $employeeFeedbackList[] = $goal['feedback'];
            foreach ($tasks as $t) {
                if (!empty($t['employee_feedback'])) $employeeFeedbackList[] = $t['employee_feedback'];
            }

            // 3. Extract Employee Learnings & Reflections
            $employeeLearningsList = [];
            foreach ($tasks as $t) {
                if (!empty($t['employee_learnings'])) $employeeLearningsList[] = $t['employee_learnings'];
            }

            // 4. Extract Logged Shift Milestones & KPI Records
            $empLogs = array_values(array_filter($allLogs, function ($l) use ($eId, $gId) {
                $matchEmp = strtolower(trim($l['employee_id'] ?? '')) === $eId;
                if (!$matchEmp) return false;
                if (!empty($l['goal_id'])) return (string)$l['goal_id'] === $gId;
                return true;
            }));

            $milestoneTexts = [];
            foreach ($empLogs as $l) {
                if (!empty($l['milestone_title'])) $milestoneTexts[] = $l['milestone_title'];
                if (!empty($l['actual_metric'])) $milestoneTexts[] = $l['actual_metric'];
                if (!empty($l['notes'])) $milestoneTexts[] = $l['notes'];
            }

            // Perform in-depth Substance & Sentiment Analysis
            $supAnalysis = $this->analyzeTextSubstanceAndSentiment($supervisorNotesList);
            $learnAnalysis = $this->analyzeTextSubstanceAndSentiment($employeeLearningsList);
            $fbAnalysis = $this->analyzeTextSubstanceAndSentiment($employeeFeedbackList);
            $msAnalysis = $this->analyzeTextSubstanceAndSentiment($milestoneTexts);

            // Calculate Task Ratio
            $taskRatio = $totalTasks > 0 ? (count($completedTasks) / $totalTasks) : 1.0;

            // Detect any placeholder inputs or negative feedback
            $hasPlaceholders = $learnAnalysis['is_placeholder'] || $supAnalysis['is_placeholder'] || $msAnalysis['is_placeholder'];
            $isNegative = ($supAnalysis['sentiment_score'] < 0) || ($learnAnalysis['sentiment_score'] < 0);

            $placeholderWarnings = [];
            if ($learnAnalysis['is_placeholder']) {
                $placeholderWarnings[] = "reflections ('" . implode(', ', $learnAnalysis['flagged_samples']) . "')";
            }
            if ($supAnalysis['is_placeholder']) {
                $placeholderWarnings[] = "supervisor notes ('" . implode(', ', $supAnalysis['flagged_samples']) . "')";
            }
            if ($msAnalysis['is_placeholder']) {
                $placeholderWarnings[] = "shift milestones ('" . implode(', ', $msAnalysis['flagged_samples']) . "')";
            }

            // Scoring Engine:
            // If criteria contain gibberish/placeholders or negative sentiment, rating MUST be strictly below 3.0 (< 3.00)
            if ($hasPlaceholders || $isNegative) {
                // Starts at 1.80 (Needs Improvement baseline)
                $calculatedScore = 1.80;
                // Task completion contributes up to +0.80 for 100% completed tasks
                $calculatedScore += ($taskRatio * 0.80);

                // If negative sentiment, penalize further
                if ($isNegative) {
                    $calculatedScore += min(0, ($supAnalysis['sentiment_score'] + $learnAnalysis['sentiment_score']) * 0.40);
                }

                // If multiple placeholders present, apply small penalty
                if (count($placeholderWarnings) >= 2) {
                    $calculatedScore -= 0.15;
                }

                // Strict ceiling: Guarantee score is below 3.0 (max 2.85)
                $recommendedScore = min(2.85, max(1.20, round($calculatedScore, 2)));
                $tierLabel = 'Below Benchmark (Needs Calibration)';
            } else {
                // Legitimate, authentic substance and positive/constructive evidence
                $calculatedScore = 3.20; // Proficient starting baseline
                $calculatedScore += ($taskRatio * 0.50); // up to 3.70
                $calculatedScore += ($supAnalysis['quality_score'] * 0.45); // up to 4.15
                $calculatedScore += ($learnAnalysis['quality_score'] * 0.35); // up to 4.50
                $calculatedScore += ($msAnalysis['quality_score'] * 0.35); // up to 4.85
                $calculatedScore += max(0, $supAnalysis['sentiment_score'] * 0.15); // bonus for stellar praise

                $recommendedScore = min(5.00, max(3.00, round($calculatedScore, 2)));

                if ($recommendedScore >= 4.50) $tierLabel = 'Master Tier';
                elseif ($recommendedScore >= 3.75) $tierLabel = 'Advanced Tier';
                else $tierLabel = 'Proficient';
            }

            // Synthesize AI Endorsement & Audit Notes
            if (!empty($placeholderWarnings)) {
                $aiNotes = "AI Appraisal Review: Associate {$name} completed task checklist (" . count($completedTasks) . "/{$totalTasks}). However, " . implode(' and ', $placeholderWarnings) . " contain gibberish/placeholder text. Due to lack of qualitative floor evidence, a rating below 3.0 ({$recommendedScore}/5.0 · {$tierLabel}) is assigned. Supervisor floor calibration required.";
            } elseif ($isNegative) {
                $aiNotes = "AI Appraisal Review: Critical performance/compliance concerns were flagged in supervisor notes or feedback. A below-benchmark rating of {$recommendedScore}/5.0 ({$tierLabel}) is assigned pending formal remediation/coaching.";
            } else {
                $evidenceSnippets = [];
                if (!empty($supAnalysis['clean_snippets'][0])) $evidenceSnippets[] = "Supervisor notes: \"{$supAnalysis['clean_snippets'][0]}\"";
                if (!empty($learnAnalysis['clean_snippets'][0])) $evidenceSnippets[] = "Associate reflections: \"{$learnAnalysis['clean_snippets'][0]}\"";
                if (!empty($msAnalysis['clean_snippets'][0])) $evidenceSnippets[] = "Shift milestone: \"{$msAnalysis['clean_snippets'][0]}\"";

                $aiNotes = "AI Appraisal Recommendation: Associate {$name} demonstrated solid operational performance in \"{$goal['title']}\". ";
                if (!empty($evidenceSnippets)) {
                    $aiNotes .= implode('. ', $evidenceSnippets) . '. ';
                }
                $aiNotes .= "Recommended for {$tierLabel} rating ({$recommendedScore}/5.0) based on verified KPI deliverables and completed checklist matrix.";
            }

            // Generate criteria breakdown with realistic sub-ratings
            $criteriaList = [
                [
                    'title'     => 'Operational Excellence & Protocol Adherence',
                    'metric'    => $goal['target_metric'] ?: '100% SOP Compliance',
                    'weight'    => 40,
                    'rating'    => min(5.0, max(1.0, round($recommendedScore + 0.1, 1))),
                    'rationale' => !empty($learnAnalysis['clean_snippets'][0]) 
                        ? 'Demonstrated strong operational diligence: "' . substr($learnAnalysis['clean_snippets'][0], 0, 90) . '..."'
                        : ($learnAnalysis['is_placeholder'] 
                            ? 'Checklist verified. Reflections contain minimal text (' . ($learnAnalysis['flagged_samples'][0] ?? 'n/a') . ').'
                            : 'Verified shift checklist completion and operational compliance.')
                ],
                [
                    'title'     => 'Shift Execution, KPI Delivery & Diligence',
                    'metric'    => count($empLogs) > 0 ? ($empLogs[0]['actual_metric'] ?? 'Target >= 95% Deliverable') : 'Shift KPI Deliverables Met',
                    'weight'    => 30,
                    'rating'    => min(5.0, max(1.0, round($recommendedScore, 1))),
                    'rationale' => count($empLogs) > 0 
                        ? (!$msAnalysis['is_placeholder']
                            ? 'Logged ' . count($empLogs) . ' shift milestone(s) with verified deliverables (' . ($empLogs[0]['milestone_title'] ?? 'KPI Delivery') . ').'
                            : 'Logged shift milestone contains minimal metric placeholder (' . ($msAnalysis['flagged_samples'][0] ?? 'n/a') . ').')
                        : 'Achieved checklist execution within scheduled shift timeline.'
                ],
                [
                    'title'     => 'Teamwork, Conflict De-escalation & Mentorship',
                    'metric'    => 'Zero Unresolved Escalations / Positive Peer Collaboration',
                    'weight'    => 30,
                    'rating'    => min(5.0, max(1.0, round($recommendedScore - 0.1, 1))),
                    'rationale' => !empty($supAnalysis['clean_snippets'][0])
                        ? 'Supervisor coaching recorded: "' . substr($supAnalysis['clean_snippets'][0], 0, 90) . '..."'
                        : ($supAnalysis['is_placeholder']
                            ? 'Supervisor coaching note contains brief placeholder text (' . ($supAnalysis['flagged_samples'][0] ?? 'n/a') . ').'
                            : 'Maintained proactive guest engagement and positive floor coordination.')
                ]
            ];

            $recommendations[] = [
                'employee_id'         => $eId,
                'employee_name'       => $name,
                'employee_position'   => $pos,
                'employee_department' => $dept,
                'avatar'              => strtoupper(substr($name, 0, 2)),
                'goal_id'             => (int)$gId,
                'goal_title'          => $goal['title'],
                'target_metric'       => $goal['target_metric'] ?? 'Standard KPI',
                'recommended_score'   => $recommendedScore,
                'tier_label'          => $tierLabel,
                'supervisor_notes'    => $aiNotes,
                'criteria_scores'     => $criteriaList,
                'evidence_summary'    => [
                    'supervisor_notes_count'   => count($supervisorNotesList),
                    'employee_feedback_count'  => count($employeeFeedbackList),
                    'employee_learnings_count' => count($employeeLearningsList),
                    'milestones_count'         => count($empLogs),
                    'completed_tasks_count'    => count($completedTasks),
                    'total_tasks_count'        => $totalTasks,
                    'sample_supervisor_note'   => $supervisorNotesList[0] ?? null,
                    'sample_learning'          => $employeeLearningsList[0] ?? null,
                    'sample_milestone'         => count($empLogs) > 0 ? $empLogs[0]['milestone_title'] : null,
                    'has_placeholders'         => !empty($placeholderWarnings)
                ]
            ];
        }

        return [
            'success' => true,
            'data'    => [
                'recommendations' => $recommendations,
                'count'           => count($recommendations)
            ],
            'message' => count($recommendations) . ' AI appraisal recommendations generated based on shift evidence.'
        ];
    }

    /**
     * Analyzes text substance, length, placeholder patterns, and sentiment.
     */
    private function analyzeTextSubstanceAndSentiment(array $texts): array
    {
        if (empty($texts)) {
            return [
                'count'           => 0,
                'substance_level' => 'empty',
                'quality_score'   => 0.0,
                'sentiment_score' => 0.0,
                'is_placeholder'  => false,
                'clean_snippets'  => [],
                'flagged_samples' => []
            ];
        }

        $placeholderPatterns = [
            '/^[a-z0-9\s]{1,4}$/i',                       // 1 to 4 characters like "a", "asd", "d", "test"
            '/^(.)\1{2,}$/i',                             // repeating chars like "aaa", "...", "zzz"
            '/^(na|n\/a|none|nil|null|test|testing|asd|asdf|zsdas|abc|xyz|sample|placeholder|todo)$/i'
        ];

        $positiveKeywords = [
            'excellent', 'outstanding', 'exceeded', 'surpassed', 'mastered', 'stellar', 'exceptional',
            'diligent', 'proactive', 'resolved', 'improved', 'commendable', 'flawless', 'exemplary',
            'efficient', 'punctual', 'compliance', 'initiative', 'thorough', 'smooth',
            'great', 'good', 'satisfied', 'success', 'collaborative', 'mentor', 'leadership',
            'zero escalations', 'zero complaints', 'no complaints', 'zero incidents', 'resolved issues'
        ];

        $negativeKeywords = [
            'delayed', 'missed', 'failed', 'complaint', 'escalation',
            'struggled', 'needs improvement', 'incident', 'poor', 'absent',
            'incomplete', 'violation', 'dispute', 'warning'
        ];

        $validTexts = [];
        $flaggedSamples = [];
        $totalWords = 0;
        $sentimentSum = 0;

        foreach ($texts as $raw) {
            $trimmed = trim((string)$raw);
            if ($trimmed === '') continue;

            $isPlaceholder = false;
            foreach ($placeholderPatterns as $pat) {
                if (preg_match($pat, $trimmed)) {
                    $isPlaceholder = true;
                    break;
                }
            }

            $words = preg_split('/\s+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
            $wordCount = count($words);

            if ($isPlaceholder || ($wordCount <= 1 && strlen($trimmed) <= 4)) {
                $flaggedSamples[] = $trimmed;
            } else {
                $validTexts[] = $trimmed;
                $totalWords += $wordCount;

                $lower = strtolower($trimmed);
                foreach ($positiveKeywords as $kw) {
                    if (strpos($lower, $kw) !== false) $sentimentSum += 0.25;
                }
                foreach ($negativeKeywords as $kw) {
                    // Check if prefixed with zero or no or resolved
                    if (preg_match('/(zero|no|resolved|prevented)\s+(guest\s+|floor\s+|client\s+)?' . preg_quote($kw, '/') . '/i', $lower)) {
                        $sentimentSum += 0.25; // Turning into positive achievement
                    } elseif (strpos($lower, $kw) !== false) {
                        $sentimentSum -= 0.30;
                    }
                }
            }
        }

        $allCount = count($texts);
        $validCount = count($validTexts);
        $placeholderCount = count($flaggedSamples);

        if ($validCount === 0 && $placeholderCount > 0) {
            return [
                'count'           => $allCount,
                'valid_count'     => 0,
                'substance_level' => 'placeholder',
                'quality_score'   => 0.05,
                'sentiment_score' => 0.0,
                'is_placeholder'  => true,
                'clean_snippets'  => [],
                'flagged_samples' => $flaggedSamples
            ];
        }

        if ($validCount === 0) {
            return [
                'count'           => 0,
                'valid_count'     => 0,
                'substance_level' => 'empty',
                'quality_score'   => 0.0,
                'sentiment_score' => 0.0,
                'is_placeholder'  => false,
                'clean_snippets'  => [],
                'flagged_samples' => []
            ];
        }

        $avgWords = $totalWords / max(1, $validCount);
        $substanceLevel = 'minimal';
        $qualityScore = 0.35;

        if ($avgWords >= 20 || $totalWords >= 30) {
            $substanceLevel = 'comprehensive';
            $qualityScore = 1.0;
        } elseif ($avgWords >= 10 || $totalWords >= 15) {
            $substanceLevel = 'moderate';
            $qualityScore = 0.75;
        } elseif ($avgWords >= 4) {
            $substanceLevel = 'minimal';
            $qualityScore = 0.45;
        }

        $clampedSentiment = max(-1.0, min(1.0, $sentimentSum));

        return [
            'count'           => $allCount,
            'valid_count'     => $validCount,
            'substance_level' => $substanceLevel,
            'quality_score'   => $qualityScore,
            'sentiment_score' => $clampedSentiment,
            'is_placeholder'  => false,
            'clean_snippets'  => $validTexts,
            'flagged_samples' => $flaggedSamples
        ];
    }

    /**
     * Submit self-assessment ratings
     */
    public function submitSelfAssessment(array $payload): array
    {
        $goalId = $payload['goal_id'] ?? null;
        if (!empty($goalId)) {
            $goal = $this->goalModel->find((string)$goalId);
            if ($goal) {
                $st = strtolower(trim($goal['status'] ?? ''));
                if ($st === 'done' || $st === 'completed' || $st === 'failed') {
                    return [
                        'success' => false,
                        'data'    => null,
                        'message' => "Cannot submit self evaluation: Objective is already marked as '{$goal['status']}'."
                    ];
                }
            }
        }

        $empId = trim($payload['employee_id'] ?? ($_SESSION['employee_id'] ?? ($_SESSION['user_id'] ?? '')));
        $saved = $this->evaluationModel->saveSelfAssessment($payload);

        return [
            'success' => true,
            'data'    => $saved,
            'message' => "Self-assessment successfully recorded in database."
        ];
    }

    /**
     * Calibrate 1-on-1 performance review
     */
    public function calibrateEvaluation(array $payload): array
    {
        $empId = trim($payload['employee_id'] ?? ($_SESSION['employee_id'] ?? ($_SESSION['user_id'] ?? '')));
        $saved = $this->evaluationModel->calibrateEvaluation($payload);

        $calibratedScore = isset($payload['calibrated_score']) && $payload['calibrated_score'] !== ''
            ? (float)$payload['calibrated_score']
            : (isset($payload['new_calibrated_score']) && $payload['new_calibrated_score'] !== ''
                ? (float)$payload['new_calibrated_score']
                : (float)($saved['calibrated_score'] ?? 0.0));

        // Insert & Update final_rating on performance_goals in Supabase
        if ($calibratedScore > 0) {
            $goalId = isset($payload['goal_id']) ? (int)$payload['goal_id'] : (isset($saved['goal_id']) ? (int)$saved['goal_id'] : null);
            $this->goalModel->setEmployeeGoalsFinalRating($empId, $calibratedScore, $goalId);
        }

        // If calibrated score is below 3.0 after 2nd attempt (retry_count >= 1), automatically flag needs_training = true
        if ($calibratedScore > 0 && $calibratedScore < 3.0) {
            $goals = $this->goalModel->getGoalsByEmployee($empId);
            $maxRetry = 0;
            foreach ($goals as $g) {
                $r = isset($g['retry_count']) ? (int)$g['retry_count'] : 0;
                if ($r > $maxRetry) $maxRetry = $r;
            }
            if ($maxRetry >= 1) {
                $this->goalModel->setEmployeeGoalsNeedsTraining($empId, true);
            }
        }

        return [
            'success' => true,
            'data'    => $saved,
            'message' => "1-on-1 performance calibration successfully recorded and goal final rating updated."
        ];
    }

    /**
     * Set needs_training boolean for a goal or for all goals of an employee
     */
    public function setNeedsTraining(array $payload): array
    {
        $goalId = $payload['goal_id'] ?? $payload['id'] ?? null;
        $empId = $payload['employee_id'] ?? null;
        $needsTraining = isset($payload['needs_training']) ? (bool)$payload['needs_training'] : true;

        if (!empty($goalId)) {
            $updated = $this->goalModel->setNeedsTraining($goalId, $needsTraining);
            return [
                'success' => true,
                'data'    => $updated,
                'message' => "Goal needs_training flag updated."
            ];
        }

        if (!empty($empId)) {
            $updated = $this->goalModel->setEmployeeGoalsNeedsTraining($empId, $needsTraining);
            return [
                'success' => true,
                'data'    => $updated,
                'message' => "Employee active goals needs_training updated."
            ];
        }

        return [
            'success' => false,
            'data'    => null,
            'message' => "Goal ID or Employee ID required to update needs_training."
        ];
    }

    /**
     * Increment retry_count for a goal or for all goals of an employee in database
     */
    public function incrementRetryCount(array $payload): array
    {
        $goalId = $payload['goal_id'] ?? $payload['id'] ?? null;
        $empId = $payload['employee_id'] ?? null;
        $increment = isset($payload['increment']) ? (int)$payload['increment'] : 1;

        if (!empty($goalId)) {
            $updated = $this->goalModel->incrementRetryCount($goalId, $increment);
            return [
                'success' => true,
                'data'    => $updated,
                'message' => "Goal retry count incremented."
            ];
        }

        if (!empty($empId)) {
            $updated = $this->goalModel->incrementEmployeeGoalsRetryCount($empId, $increment);
            return [
                'success' => true,
                'data'    => $updated,
                'message' => "Employee active goals retry count incremented."
            ];
        }

        return [
            'success' => false,
            'data'    => null,
            'message' => "Goal ID or Employee ID required to increment retry count."
        ];
    }

    /**
     * Execute retry / remediation plan: increment retry_count and update needs_training
     */
    public function retryPlan(array $payload): array
    {
        $empId = trim($payload['employee_id'] ?? ($_SESSION['employee_id'] ?? ($_SESSION['user_id'] ?? '')));
        if (empty($empId)) {
            return ['success' => false, 'message' => 'Employee ID is required to retry plan.'];
        }
        $goals = $this->goalModel->getGoalsByEmployee($empId);

        $maxRetry = 0;
        foreach ($goals as $g) {
            $r = isset($g['retry_count']) ? (int)$g['retry_count'] : 0;
            if ($r > $maxRetry) $maxRetry = $r;
        }

        // Increment retry_count on all goals in Supabase
        $updatedGoals = $this->goalModel->incrementEmployeeGoalsRetryCount($empId, 1);
        $newRetryCount = $maxRetry + 1;
        $needsTraining = ($newRetryCount >= 3 && $newRetryCount < 4);

        if ($needsTraining) {
            $this->goalModel->setEmployeeGoalsNeedsTraining($empId, true);
        }

        return [
            'success' => true,
            'needs_formal_training' => $needsTraining,
            'needs_training' => $needsTraining,
            'retry_count' => $newRetryCount,
            'data'    => $updatedGoals,
            'message' => $needsTraining
                ? "Plan retried (Retry count updated to {$newRetryCount}). Associate is flagged for Needs Training (True)."
                : "Plan retried (Retry count updated to {$newRetryCount} in database). Tasks prepared for re-monitoring."
        ];
    }

    /**
     * Get all active training programs from training_programs
     */
    public function getTrainingPrograms(array $payload = []): array
    {
        $res = supabaseRequest('training_programs', 'GET', null, true);
        $programs = ($res['status'] === 200 && is_array($res['data'])) ? $res['data'] : [];
        return [
            'success' => true,
            'data'    => $programs
        ];
    }

    /**
     * Get training needs from training_needs
     */
    public function getTrainingNeeds(array $payload = []): array
    {
        $res = supabaseRequest('training_needs', 'GET', null, true);
        $needs = ($res['status'] === 200 && is_array($res['data'])) ? $res['data'] : [];
        return [
            'success' => true,
            'data'    => $needs
        ];
    }

    /**
     * Assign Formal Curriculum / Program to Employee from Stage 7 IDP Remediation
     * Inserts into training_needs with target_goal_id and employee_id, and sets needs_training = true
     */
    public function assignFormalCurriculum(array $payload): array
    {
        $programId = $payload['program_id'] ?? $payload['programId'] ?? null;
        $empId = trim($payload['employee_id'] ?? ($payload['employeeId'] ?? ($_SESSION['employee_id'] ?? ($_SESSION['user_id'] ?? ''))));
        $goalId = $payload['goal_id'] ?? $payload['target_goal_id'] ?? null;

        if (empty($programId)) {
            return ['success' => false, 'message' => 'Program ID is required.'];
        }

        // 1. Fetch training program details
        $progRes = supabaseRequest('training_programs?id=eq.' . urlencode($programId), 'GET', null, true);
        $program = (!empty($progRes['data']) && is_array($progRes['data'])) ? $progRes['data'][0] : null;
        if (!$program) {
            return ['success' => false, 'message' => 'Training Program not found.'];
        }

        // 2. Fetch employee details
        $emp = $this->authModel->find($empId) ?: $this->authModel->findByEmployeeCode($empId);
        if (!$emp) {
            return ['success' => false, 'message' => 'Employee not found.'];
        }
        $empName = $emp['full_name'] ?? $emp['name'] ?? 'Unknown Associate';
        $dept = $emp['department'] ?? $emp['dept'] ?? 'General';
        $empRole = $emp['role'] ?? $emp['position'] ?? 'Associate';

        // 3. Resolve active goal if goalId is missing
        if (empty($goalId)) {
            $goals = $this->goalModel->getGoalsByEmployee($empId);
            if (!empty($goals)) {
                $goalId = (string)$goals[0]['id'];
            }
        }

        // 4. Create record in training_needs
        $passingScore = isset($program['passing_score']) ? (float)$program['passing_score'] : 80.0;
        $targetBenchmark = round($passingScore / 20.0, 2); // e.g. 80% => 4.00 / 5.0

        $needPayload = [
            'title'               => 'Formal Training: ' . ($program['title'] ?? 'Performance IDP Curriculum'),
            'source_type'         => 'competency_gap',
            'source_label'        => 'Stage 7 Performance IDP Remediation',
            'category'            => $program['category'] ?? 'Performance Gap',
            'dept'                => $dept,
            'employee_id'         => $empId,
            'associate_name'      => $empName,
            'associate_role'      => $empRole,
            'associate_avatar'    => $emp['avatar_url'] ?? null,
            'target_competency'   => $program['target_competency'] ?? 'Performance Calibration Standard',
            'competency_key'      => $program['competency_key'] ?? 'performance_remediation',
            'current_score'       => 0.00,
            'required_score'      => $targetBenchmark,
            'gap'                 => (0 - $targetBenchmark),
            'urgency'             => 'High',
            'status'              => 'In Training',
            'linked_program_id'   => $program['id'],
            'target_goal_id'      => $goalId ? (string)$goalId : null,
            'date_identified'     => date('M d, Y'),
            'notes'               => "Enrolled from Stage 7 IDP Remediation for Goal ID: {$goalId}. Benchmark score: {$targetBenchmark} / 5.0.",
            'created_at'          => date('c'),
            'updated_at'          => date('c')
        ];

        // Attempt 1: with target_goal_id
        $insertRes = supabaseRequest('training_needs', 'POST', $needPayload, true);

        // Attempt 2: fallback without target_goal_id if FK constraint on target_goal_id restricts
        if ($insertRes['status'] !== 200 && $insertRes['status'] !== 201) {
            $needPayload['target_goal_id'] = null;
            $insertRes = supabaseRequest('training_needs', 'POST', $needPayload, true);
        }

        $insertedRecord = (!empty($insertRes['data']) && is_array($insertRes['data'])) ? $insertRes['data'][0] : $needPayload;

        // 5. Update goal needs_training flag to true, in_training to true, and set retry_count = 3 in performance_goals
        if (!empty($goalId)) {
            $this->goalModel->setNeedsTraining($goalId, true);
            $this->goalModel->setInTraining($goalId, true);
            $this->goalModel->setGoalRetryCount($goalId, 3);
        } else {
            $this->goalModel->setEmployeeGoalsNeedsTraining($empId, true);
            $this->goalModel->setEmployeeGoalsInTraining($empId, true);
            $this->goalModel->setEmployeeGoalsRetryCount($empId, 3);
        }

        return [
            'success' => true,
            'message' => "Formal Training Program '{$program['title']}' assigned to {$empName} in training_needs.",
            'data'    => $insertedRecord
        ];
    }

    /**
     * Continue to Final 1-on-1 Evaluation (Sets retry_count to 4 in database)
     */
    public function continueToFinal1on1Evaluation(array $payload): array
    {
        $goalId = $payload['goal_id'] ?? $payload['id'] ?? null;
        $empId = trim($payload['employee_id'] ?? ($_SESSION['employee_id'] ?? ($_SESSION['user_id'] ?? '')));

        if (!empty($goalId)) {
            $updated = $this->goalModel->setGoalRetryCount($goalId, 4);
        } elseif (!empty($empId)) {
            $updated = $this->goalModel->setEmployeeGoalsRetryCount($empId, 4);
        } else {
            $updated = [];
        }

        return [
            'success' => true,
            'data'    => $updated,
            'retry_count' => 4,
            'message' => "Initiated Final 1-on-1 Evaluation (Retry count set to 4). Phases 3-6 locked."
        ];
    }

    /**
     * Mark Performance Goal as Failed
     */
    public function markGoalFailed(array $payload): array
    {
        $goalId = $payload['goal_id'] ?? $payload['id'] ?? null;
        $empId = $payload['employee_id'] ?? null;

        if (!empty($goalId)) {
            $updated = $this->goalModel->markFailed((string)$goalId);

            // Cascade: markFailed sets retry_count=4 (terminal), so onGoalFailed
            // will hit the cap guard and be a no-op — but we call it for consistency
            // so the cascade service is the single source of truth for this logic.
            try {
                $cascadeService = new GoalTrainingCascadeService();
                $cascadeService->onGoalFailed((string)$goalId);
            } catch (\Throwable $e) {
                error_log('Cascade error on markGoalFailed: ' . $e->getMessage());
            }
        } elseif (!empty($empId)) {
            $updated = $this->goalModel->markEmployeeGoalsFailed($empId);

            // Cascade each failed goal individually
            try {
                $cascadeService = new GoalTrainingCascadeService();
                $failedGoals = is_array($updated) ? $updated : [];
                foreach ($failedGoals as $g) {
                    if (!empty($g['id'])) {
                        $cascadeService->onGoalFailed((string)$g['id']);
                    }
                }
            } catch (\Throwable $e) {
                error_log('Cascade error on markEmployeeGoalsFailed: ' . $e->getMessage());
            }
        } else {
            return [
                'success' => false,
                'data'    => null,
                'message' => "Goal ID or Employee ID is required to mark as failed."
            ];
        }

        return [
            'success' => true,
            'data'    => $updated,
            'message' => "Performance goal permanently marked as Failed."
        ];
    }

    /**
     * Delete a single performance goal
     */
    public function deleteGoal(array $payload): array
    {
        $id = $payload['id'] ?? $payload['goal_id'] ?? null;
        if (empty($id)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'Validation error: Goal ID is required for deletion.'
            ];
        }

        // Fast-path: if status was supplied by caller and is non-pending, reject immediately
        if (isset($payload['status'])) {
            $st = strtolower(trim((string)$payload['status']));
            if ($st !== 'pending approval' && $st !== 'pending' && $st !== 'draft' && !empty($st)) {
                return [
                    'success' => false,
                    'data'    => null,
                    'message' => "Cannot delete objective #{$id}: Only pending objectives can be deleted. Current status is '{$payload['status']}'."
                ];
            }
        } else {
            // Fallback check if status not provided in request
            $existing = $this->goalModel->findById((string)$id);
            if ($existing) {
                $st = strtolower(trim($existing['status'] ?? ''));
                if ($st !== 'pending approval' && $st !== 'pending' && $st !== 'draft' && !empty($st)) {
                    return [
                        'success' => false,
                        'data'    => null,
                        'message' => "Cannot delete objective #{$id}: Only pending objectives can be deleted. Current status is '{$existing['status']}'."
                    ];
                }
            }
        }

        $deleted = $this->goalModel->deleteGoal((string)$id);
        return [
            'success' => $deleted,
            'data'    => ['id' => $id],
            'message' => $deleted ? "Performance objective #{$id} deleted successfully." : "Failed to delete goal #{$id}."
        ];
    }

    /**
     * Bulk delete multiple performance goals
     */
    public function bulkDeleteGoals(array $payload): array
    {
        $ids = $payload['ids'] ?? [];
        if (empty($ids) || !is_array($ids)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'Validation error: List of Goal IDs is required for bulk deletion.'
            ];
        }

        $validIds = array_values(array_filter(array_map('trim', $ids)));
        if (empty($validIds)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'Validation error: No valid IDs provided for bulk deletion.'
            ];
        }

        $deleted = $this->goalModel->bulkDeleteGoals($validIds);
        $count = count($validIds);
        return [
            'success' => $deleted,
            'count'   => $count,
            'message' => "{$count} performance objectives deleted successfully."
        ];
    }

    /**
     * Get list of supervisors/leaders from users table for goal assignment
     */
    public function getSupervisors(array $payload = []): array
    {
        $users = $this->authModel->all();
        $supervisors = array_values(array_filter($users, function ($u) {
            $role = strtolower($u['role'] ?? '');
            $roleKey = strtolower($u['role_key'] ?? '');
            return in_array($role, ['supervisor', 'manager', 'lead', 'hradmin', 'generalmanager', 'depthead', 'director']) ||
                   in_array($roleKey, ['manager', 'hr', 'executive', 'lead']);
        }));

        // If filtered list is empty, fallback to returning all active users
        if (empty($supervisors)) {
            $supervisors = $users;
        }

        return [
            'success' => true,
            'data'    => $supervisors,
            'count'   => count($supervisors),
            'message' => 'Supervisors retrieved successfully from database.'
        ];
    }

    /**
     * Award Performance XP to xp_ledger based on rating
     * 3.0-3.9: 5 pts, 4.0-4.9: 8 pts, 5.0: 20 pts
     */
    public function awardPerformanceXP(array $payload): array
    {
        $employeeId = trim($payload['employee_id'] ?? ($_SESSION['employee_id'] ?? ($_SESSION['user_id'] ?? '')));
        $evalId = $payload['performance_eval_id'] ?? $payload['eval_id'] ?? null;
        $rating = isset($payload['rating']) ? (float)$payload['rating'] : 4.5;

        // Deterministic XP calculation:
        // 5 points if 3-3.9 rating, 8 points if 4-4.9, 20 pts if 5.0
        if ($rating >= 5.0) {
            $points = 20;
        } elseif ($rating >= 4.0) {
            $points = 8;
        } elseif ($rating >= 3.0) {
            $points = 5;
        } else {
            $points = 0;
        }

        if (isset($payload['points']) && is_numeric($payload['points'])) {
            $points = (int)$payload['points'];
        }

        // Get employee current balance
        $user = $this->authModel->find($employeeId) ?: $this->authModel->findByEmployeeCode($employeeId);
        $currentXP = isset($user['total_xp']) ? (int)$user['total_xp'] : 450;
        $balanceAfter = $currentXP + $points;

        $ledgerEntry = [
            'id'                  => 'xp-' . substr(bin2hex(random_bytes(6)), 0, 10),
            'employee_id'         => $employeeId,
            'source_type'         => 'performance',
            'performance_eval_id' => $evalId,
            'points'              => $points,
            'balance_after'       => $balanceAfter,
            'description'         => $payload['description'] ?? "Performance appraisal recognition kudos (+{$points} XP)",
            'lms_prescribed'      => $payload['lms_prescribed'] ?? $payload['prescribed_lms_id'] ?? null,
            'created_at'          => date('c')
        ];

        // Insert into xp_ledger in Supabase
        $res = supabaseRequest('xp_ledger', 'POST', $ledgerEntry, true);

        // Update user's total_xp
        if ($user && isset($user['id'])) {
            $this->authModel->update($user['id'], [
                'total_xp' => $balanceAfter
            ]);
        }

        // Mark active goals for the employee to 'Done' and attach exp_id in performance_goals
        $goalId = $payload['goal_id'] ?? null;
        $updatedGoals = [];
        try {
            if (!empty($goalId)) {
                $up = $this->goalModel->markDone($goalId, $ledgerEntry['id']);
                if ($up) $updatedGoals[] = $up;
            } else {
                $updatedGoals = $this->goalModel->markEmployeeGoalsDone($employeeId, $ledgerEntry['id']);
            }
        } catch (\Throwable $e) {
            error_log('Error marking goals done during awardPerformanceXP: ' . $e->getMessage());
        }

        return [
            'success'        => true,
            'data'           => $ledgerEntry,
            'exp_id'         => $ledgerEntry['id'],
            'goal_id'        => $goalId,
            'goals_updated'  => $updatedGoals,
            'points_awarded' => $points,
            'balance_after'  => $balanceAfter,
            'message'        => "Awarded +{$points} XP to {$employeeId} and set performance goal to Done with exp_id attached."
        ];
    }

    /**
     * Revert Goal Kudos / XP transaction and reset performance goal status back to Approved
     */
    public function revertGoalKudos(array $payload): array
    {
        $employeeId = $payload['employee_id'] ?? null;
        $goalId = $payload['goal_id'] ?? $payload['id'] ?? null;
        $expId = $payload['exp_id'] ?? null;

        $targetGoal = null;
        if (!empty($goalId)) {
            $targetGoal = $this->goalModel->find((string)$goalId);
        }
        if (!$targetGoal && !empty($employeeId)) {
            $empGoals = $this->goalModel->getGoalsByEmployee($employeeId);
            foreach ($empGoals as $g) {
                if (!empty($g['exp_id']) || ($g['status'] ?? '') === 'Done') {
                    $targetGoal = $g;
                    break;
                }
            }
        }

        if (!$targetGoal && !empty($employeeId)) {
            $empGoals = $this->goalModel->getGoalsByEmployee($employeeId);
            if (!empty($empGoals[0])) {
                $targetGoal = $empGoals[0];
            }
        }

        if (!$targetGoal) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'No active goal found to revert.'
            ];
        }

        $targetExpId = $expId ?: ($targetGoal['exp_id'] ?? null);
        $pointsDeducted = 0;

        if (!empty($targetExpId)) {
            // Fetch the xp_ledger row to know exact points
            $ledgerRes = supabaseRequest('xp_ledger?id=eq.' . urlencode($targetExpId), 'GET', null, true);
            if ($ledgerRes['status'] === 200 && is_array($ledgerRes['data']) && !empty($ledgerRes['data'][0])) {
                $ledgerRow = $ledgerRes['data'][0];
                $pointsDeducted = (int)($ledgerRow['points'] ?? 0);
            }

            // Delete from xp_ledger
            supabaseRequest('xp_ledger?id=eq.' . urlencode($targetExpId), 'DELETE', null, true);

            // Deduct points from user total_xp
            $empCode = $targetGoal['employee_id'] ?? $employeeId;
            if ($empCode) {
                $user = $this->authModel->find($empCode) ?: $this->authModel->findByEmployeeCode($empCode);
                if ($user && isset($user['id'])) {
                    $currentTotal = (int)($user['total_xp'] ?? 0);
                    $newTotal = max(0, $currentTotal - $pointsDeducted);
                    $this->authModel->update($user['id'], ['total_xp' => $newTotal]);
                }
            }
        }

        // Reset goal status to Approved and clear exp_id
        $updated = $this->goalModel->revertGoalKudos($targetGoal['id']);

        return [
            'success'         => true,
            'data'            => $updated,
            'points_deducted' => $pointsDeducted,
            'message'         => "Successfully reverted kudos and reset performance goal #{$targetGoal['id']} back to Approved."
        ];
    }

    /**
     * Mark performance goal as completed (transitions cycle & frees employee to set new goal)
     */
    public function markGoalCompleted(array $payload): array
    {
        $id = $payload['id'] ?? $payload['goal_id'] ?? null;
        $empId = $payload['employee_id'] ?? null;

        if (empty($id) && !empty($empId)) {
            $empGoals = $this->goalModel->getGoalsByEmployee($empId);
            foreach ($empGoals as $g) {
                $st = strtolower(trim($g['status'] ?? ''));
                if ($st === 'approved' || $st === 'done') {
                    $id = $g['id'];
                    break;
                }
            }
            if (empty($id) && !empty($empGoals[0]['id'])) {
                $id = $empGoals[0]['id'];
            }
        }

        if (empty($id)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'Goal ID is required to mark as completed.'
            ];
        }

        $updated = $this->goalModel->updateStatus((string)$id, 'Completed', 'Performance cycle finished and archived.');

        return [
            'success' => true,
            'data'    => $updated,
            'message' => "Performance goal #{$id} successfully marked as Completed! Next cycle is now ready."
        ];
    }

    // =========================================================================
    // PERFORMANCE DEVELOPMENT PLAN — Phase 6 (Draft) & Phase 7 (Deploy)
    // =========================================================================

    /**
     * Get all draft plan items for an employee
     */
    public function getDevelopmentPlans(array $payload): array
    {
        $empId = $payload['employee_id'] ?? null;
        if (empty($empId)) {
            return ['success' => false, 'data' => null, 'message' => 'employee_id is required.'];
        }

        $status = $payload['status'] ?? null;
        $summary = $this->devPlanModel->getDraftSummary($empId);

        return ['success' => true, 'data' => $summary, 'message' => 'Draft plan items retrieved.'];
    }

    /**
     * Add a draft action task to the development plan (Phase 6)
     */
    public function addDraftTask(array $payload): array
    {
        if (empty($payload['employee_id'])) {
            return ['success' => false, 'data' => null, 'message' => 'employee_id is required.'];
        }
        if (empty($payload['title'])) {
            return ['success' => false, 'data' => null, 'message' => 'title is required.'];
        }

        $item = $this->devPlanModel->addDraftTask($payload);
        return ['success' => true, 'data' => $item, 'message' => 'Draft task added to development plan.'];
    }

    /**
     * Add a draft LMS book prescription to the development plan (Phase 6)
     */
    public function addDraftBook(array $payload): array
    {
        if (empty($payload['employee_id'])) {
            return ['success' => false, 'data' => null, 'message' => 'employee_id is required.'];
        }
        if (empty($payload['lms_document_id'])) {
            return ['success' => false, 'data' => null, 'message' => 'lms_document_id is required.'];
        }

        $item = $this->devPlanModel->addDraftBook($payload);
        return ['success' => true, 'data' => $item, 'message' => 'Draft LMS book added to development plan.'];
    }

    /**
     * Remove a single draft item by ID (Phase 6)
     */
    public function removeDraftItem(array $payload): array
    {
        $id = $payload['id'] ?? null;
        if (empty($id)) {
            return ['success' => false, 'data' => null, 'message' => 'id is required.'];
        }

        $deleted = $this->devPlanModel->removeDraftItem((string)$id);
        return [
            'success' => $deleted,
            'data'    => null,
            'message' => $deleted ? 'Draft item removed.' : 'Failed to remove draft item.'
        ];
    }

    /**
     * Discard all draft items for an employee (Phase 6 — Discard Plan)
     */
    public function discardDraftPlan(array $payload): array
    {
        $empId = $payload['employee_id'] ?? null;
        if (empty($empId)) {
            return ['success' => false, 'data' => null, 'message' => 'employee_id is required.'];
        }

        $count = $this->devPlanModel->discardAllDrafts($empId);
        return [
            'success' => true,
            'data'    => ['discarded_count' => $count],
            'message' => "Discarded {$count} draft item(s) for employee {$empId}."
        ];
    }

    /**
     * Deploy all Draft items for an employee (Phase 7):
     * Copies tasks → performance_tasks, books → lms_prescribed, marks items Committed.
     */
    public function deployDevelopmentPlan(array $payload): array
    {
        $empId  = $payload['employee_id'] ?? null;
        $goalId = !empty($payload['goal_id']) ? (int)$payload['goal_id'] : null;

        if (empty($empId)) {
            return ['success' => false, 'data' => null, 'message' => 'employee_id is required.'];
        }

        $result = $this->devPlanModel->deployPlan($empId, $goalId);
        return [
            'success' => $result['success'],
            'data'    => $result,
            'message' => $result['message']
        ];
    }
}
