<?php

require_once __DIR__ . '/../models/PerformanceGoalModel.php';
require_once __DIR__ . '/../models/TrainingNeedModel.php';

/**
 * GoalTrainingCascadeService
 *
 * Bidirectional status-cascade between performance_goals and training_needs.
 * Called from controllers — never directly from API routes.
 *
 * Direction A (goal → need): onGoalFailed()
 *   When a performance goal fails, create a training need for that employee.
 *
 * Direction B (need → goal): onTrainingNeedStatusChanged()
 *   When a training need status changes, propagate to the linked goal.
 */
class GoalTrainingCascadeService
{
    private PerformanceGoalModel $goalModel;
    private TrainingNeedModel $needModel;

    /**
     * Maximum retry_count before step 1 stops re-triggering.
     * Matches markFailed() which sets retry_count = 4 as terminal.
     */
    const RETRY_CAP = 4;

    public function __construct()
    {
        $this->goalModel = new PerformanceGoalModel();
        $this->needModel = new TrainingNeedModel();
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Direction A: performance_goals → training_needs
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Step 1 — Goal status set to 'Failed'.
     *
     * Creates a training_needs row for this employee if:
     *   - retry_count < RETRY_CAP
     *   - no open (non-terminal) training need already exists for this goal
     *
     * Sets performance_goals.needs_training = true.
     *
     * @return array ['training_need_created' => bool, 'need_id' => string|null, 'capped' => bool]
     */
    public function onGoalFailed(string $goalId): array
    {
        // 1. Fetch the goal and guard on retry cap
        $goal = $this->goalModel->find($goalId);
        if (!$goal) {
            return ['training_need_created' => false, 'need_id' => null, 'capped' => false];
        }

        $retryCount = isset($goal['retry_count']) ? (int)$goal['retry_count'] : 0;
        if ($retryCount >= self::RETRY_CAP) {
            return ['training_need_created' => false, 'need_id' => null, 'capped' => true];
        }

        // 2. Idempotency check — skip if an open need already exists for this goal
        $openNeed = $this->findOpenNeedForGoal($goalId);
        if ($openNeed) {
            // Need already exists — just ensure needs_training is set
            $this->goalModel->setNeedsTraining($goalId, true);
            return ['training_need_created' => false, 'need_id' => $openNeed['id'] ?? null, 'capped' => false];
        }

        // 3. Resolve employee info for the new training need
        $empId = $goal['employee_id'] ?? '';
        $empName = 'Associate';
        $empRole = 'Hotel Staff';
        $empDept = $goal['department'] ?? ($goal['dept'] ?? 'Operations');

        if (!empty($empId)) {
            $empRes = supabaseRequest('employees?id=eq.' . urlencode($empId), 'GET', null, true);
            $empData = !empty($empRes['data'][0]) ? $empRes['data'][0] : null;
            if (!$empData) {
                $userRes = supabaseRequest('users?id=eq.' . urlencode($empId), 'GET', null, true);
                $empData = !empty($userRes['data'][0]) ? $userRes['data'][0] : null;
            }
            if ($empData) {
                $empName = $empData['full_name'] ?? ($empData['name'] ?? $empName);
                $empRole = $empData['title'] ?? ($empData['role'] ?? $empRole);
                $empDept = $empData['department'] ?? ($empData['dept'] ?? $empDept);
            }
        }

        // 4. Create training need
        $needData = [
            'title'             => 'Remediation: ' . ($goal['title'] ?? 'Performance Goal'),
            'source_type'       => 'competency_gap',
            'source_label'      => 'Performance Goal Failed',
            'category'          => $goal['category'] ?? 'Performance Gap',
            'dept'              => $empDept,
            'employee_id'       => $empId,
            'associate_name'    => $empName,
            'associate_role'    => $empRole,
            'target_competency' => $goal['competency_area'] ?? ($goal['target_competency'] ?? 'Performance Standard'),
            'competency_key'    => $goal['competency_key'] ?? 'performance_remediation',
            'current_score'     => 0.00,
            'required_score'    => 4.00,
            'gap'               => -4.00,
            'urgency'           => 'High',
            'status'            => 'Identified',
            'target_goal_id'    => $goalId,
            'date_identified'   => date('M d, Y'),
            'notes'             => "Auto-created by cascade: Goal \"{$goal['title']}\" failed (retry #{$retryCount}).",
            'created_at'        => date('c'),
            'updated_at'        => date('c')
        ];

        $created = $this->needModel->createNeed($needData);
        $needId = $created['id'] ?? null;

        // 5. Set needs_training flag on the goal
        $this->goalModel->setNeedsTraining($goalId, true);

        return ['training_need_created' => true, 'need_id' => $needId, 'capped' => false];
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Direction B: training_needs → performance_goals
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Step 2 & 3 — Training need status changed.
     *
     * Propagates status to the linked performance goal:
     *   'Program Linked' / 'Scheduled'       → in_training = true
     *   'Passed' / 'Resolved' / 'Completed'  → in_training = false, needs_training = false, status = 'Done'
     *   'Failed'                              → in_training = false, retry_count++, maybe re-trigger onGoalFailed
     *
     * If the training need has no target_goal_id, this is a no-op (non-performance-linked need).
     */
    public function onTrainingNeedStatusChanged(string $needId, string $newStatus): void
    {
        // 1. Fetch the training need to get the linked goal ID
        $need = $this->needModel->find($needId);
        if (!$need) {
            return;
        }

        $goalId = $need['target_goal_id'] ?? ($need['targetGoalId'] ?? null);
        if (empty($goalId)) {
            return; // Not a performance-linked need — nothing to cascade
        }

        // 2. Step 2: training is in progress
        if (in_array($newStatus, ['Program Linked', 'Scheduled'], true)) {
            $this->goalModel->setInTraining($goalId, true);
            return;
        }

        // 3. Step 3a: training resolved successfully
        if (in_array($newStatus, ['Passed', 'Resolved', 'Completed'], true)) {
            $this->goalModel->setInTraining($goalId, false);
            $this->goalModel->setNeedsTraining($goalId, false);
            $this->goalModel->updateStatus($goalId, 'Done');
            $this->goalModel->updateFinalRating($goalId, 4.00);

            // Also synchronize and upgrade linked performance evaluation if exists
            $goal = $this->goalModel->find($goalId);
            $empId = $goal['employee_id'] ?? ($need['employee_id'] ?? ($need['employeeId'] ?? null));
            if (!empty($empId)) {
                $evalRes = supabaseRequest('performance_evaluations?employee_id=eq.' . urlencode($empId), 'GET', null, true);
                $evals = (isset($evalRes['data']) && is_array($evalRes['data'])) ? $evalRes['data'] : [];
                foreach ($evals as $ev) {
                    $evId = $ev['id'] ?? null;
                    if ($evId) {
                        supabaseRequest('performance_evaluations?id=eq.' . urlencode($evId), 'PATCH', [
                            'new_calibrated_score' => 4.00,
                            'calibrated_score'     => 4.00,
                            'final_rating'         => 4.00,
                            'supervisor_rating'    => 4.00,
                            'tier_label'           => 'Master Tier (Passed & Certified)',
                            'status'               => 'Calibrated',
                            'updated_at'           => date('c')
                        ], true);
                    }
                }
            }
            return;
        }

        // 4. Step 3b: training failed
        if ($newStatus === 'Failed') {
            $this->goalModel->setInTraining($goalId, false);
            $this->goalModel->incrementRetryCount($goalId);

            // Re-fetch goal to get updated retry_count
            $goal = $this->goalModel->find($goalId);
            $retryCount = isset($goal['retry_count']) ? (int)$goal['retry_count'] : 0;

            if ($retryCount < self::RETRY_CAP) {
                // Re-trigger: create a new training need for the next attempt
                $this->onGoalFailed($goalId);
            }
            // If retry_count >= RETRY_CAP, goal stays Failed — no re-trigger
            return;
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Utility
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Find the latest open (non-terminal) training need linked to a goal.
     *
     * Terminal statuses: 'Passed', 'Failed', 'Resolved', 'Completed'
     * This list MUST match the exclusion list in onGoalFailed's idempotency check.
     *
     * @return array|null The open training need row, or null if none exists.
     */
    public function findOpenNeedForGoal(string $goalId): ?array
    {
        return $this->needModel->findOpenNeedByGoalId($goalId);
    }
}
