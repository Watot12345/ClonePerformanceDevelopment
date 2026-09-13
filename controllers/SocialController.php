<?php
require_once __DIR__ . '/../models/SocialModel.php';

class SocialController
{
    private SocialModel $model;

    public function __construct()
    {
        $this->model = new SocialModel();
    }

    /**
     * Complete Social Recognition Hub State
     */
    public function getSocialOverview(?string $employeeId = null, ?string $sentimentFilterType = null, ?string $sentimentFilterValue = null, ?string $role = null): array
    {
        $recognitions = $this->model->getRecognitions();
        $sentiments = $this->model->getShiftSentiments($sentimentFilterType, $sentimentFilterValue);
        $roster = $this->model->getRoster();

        // Determine if current session or request is for a supervisor
        $isSupervisor = false;
        if (!empty($role)) {
            $r = strtolower(trim($role));
            $isSupervisor = in_array($r, ['supervisor', 'manager', 'hradmin', 'generalmanager', 'depthead', 'director'], true);
        } elseif (!empty($_SESSION['role'])) {
            $r = strtolower(trim($_SESSION['role']));
            $isSupervisor = in_array($r, ['supervisor', 'manager', 'hradmin', 'generalmanager', 'depthead', 'director'], true);
        } elseif (!empty($employeeId)) {
            foreach ($roster as $emp) {
                if (($emp['id'] ?? '') === $employeeId) {
                    $r = strtolower(trim($emp['role'] ?? ''));
                    $isSupervisor = in_array($r, ['supervisor', 'manager', 'hradmin', 'generalmanager', 'depthead', 'director'], true);
                    break;
                }
            }
        }

        // Fetch leaderboard & standings across associates
        $leaderboardData = $this->model->getLeaderboardWithStanding($isSupervisor ? null : $employeeId);
        $allRankings = $leaderboardData['all_rankings'] ?? [];
        $standing = $leaderboardData['standing'] ?? null;

        // In supervisor view: show all associates' ledger transactions
        // In employee view: show strictly their own ledger transactions
        $ledger = $isSupervisor ? $this->model->getLedger(null) : $this->model->getLedger($employeeId);
        
        // In supervisor view: badges reflect team/associates progress (null)
        // In employee view: badges reflect personal verified achievements ($employeeId)
        $badges = $this->model->getMilestoneBadges($isSupervisor ? null : $employeeId);

        // Calculate Gamified Live Metrics from unified ledger
        $totalRecognitions = count($recognitions);
        $totalAssociatesXP = 0;
        $propertyTotalXP = 0;
        try {
            $pdoSc = getSupabaseDb();
            if ($pdoSc) {
                // 1. Associates Total XP (excluding supervisors/management)
                $stmtAssoc = $pdoSc->query("
                    SELECT COALESCE(SUM(xl.points), 0) AS total_xp 
                    FROM public.xp_ledger xl 
                    LEFT JOIN public.employees e ON xl.employee_id = e.id 
                    WHERE LOWER(COALESCE(e.role, '')) NOT IN ('supervisor', 'manager', 'hradmin', 'generalmanager', 'depthead', 'director')
                ");
                $rAssoc = $stmtAssoc ? $stmtAssoc->fetch(PDO::FETCH_ASSOC) : null;
                if ($rAssoc && isset($rAssoc['total_xp'])) {
                    $totalAssociatesXP = (int)$rAssoc['total_xp'];
                }

                // 2. Property total XP across entire hotel / all rows in xp_ledger
                $stmtXp = $pdoSc->query("SELECT COALESCE(SUM(points), 0) AS total_xp FROM public.xp_ledger");
                $rXp = $stmtXp ? $stmtXp->fetch(PDO::FETCH_ASSOC) : null;
                if ($rXp && isset($rXp['total_xp'])) {
                    $propertyTotalXP = (int)$rXp['total_xp'];
                }
            }
        } catch (Throwable $e) {}

        // Fallback: calculate directly from all rows in getLedger(null)
        if ($propertyTotalXP === 0) {
            $allLedgerRows = $this->model->getLedger(null);
            foreach ($allLedgerRows as $alr) {
                $propertyTotalXP += (int)($alr['points'] ?? ($alr['amount'] ?? 0));
            }
        }
        if ($totalAssociatesXP === 0) {
            $totalAssociatesXP = $propertyTotalXP;
        }

        // Compute unlocked badges count (team total and personal)
        $unlockedBadges = count(array_filter($badges, function($b) {
            return !empty($b['isUnlocked']);
        }));
        $myBadgesCount = ($standing && !$isSupervisor) ? count(array_filter($badges, function($b) {
            return !empty($b['isUnlocked']);
        })) : 0;
        $myTotalXp = !$isSupervisor ? (int)($standing['total_xp'] ?? 0) : 0;

        // Hourly / Rush Sentiment Calculations
        $avgScore = 0.0;
        if (!empty($sentiments)) {
            $totalScore = array_reduce($sentiments, function($sum, $s) {
                return $sum + (float)($s['sentiment_score'] ?? 4);
            }, 0);
            $avgScore = round($totalScore / count($sentiments), 1);
        }

        $todaySentiment = $employeeId ? $this->model->getUserTodayShiftSentiment($employeeId) : null;

        return [
            'success' => true,
            'data'    => [
                'is_supervisor_view'  => $isSupervisor,
                'kpis' => [
                    'totalRecognitions'   => $totalRecognitions,
                    'totalXPAwarded'      => $propertyTotalXP,
                    'totalAssociatesXP'   => $totalAssociatesXP,
                    'propertyTotalXP'     => $propertyTotalXP,
                    'myTotalXPAwarded'    => $myTotalXp,
                    'badgesUnlocked'      => $unlockedBadges,
                    'myBadgesUnlocked'    => $myBadgesCount,
                    'averageSentiment'    => $avgScore,
                    'performanceSyncPct'  => $totalRecognitions > 0 ? 100 : 0
                ],
                'recognitions'        => $recognitions,
                'sentiments'          => $sentiments,
                'todaySentiment'      => $todaySentiment,
                'roster'              => $roster,
                'ledger'              => $ledger,
                'badges'              => $badges,
                'champions'           => $leaderboardData['champions'] ?? [],
                'all_employees_xp'    => $isSupervisor ? $allRankings : [],
                'my_standing'         => $isSupervisor ? null : $standing
            ]
        ];
    }

    /**
     * Get Top 5 Gamified XP Champions ranked directly from xp_ledger + personal employee standing
     */
    public function getTop5Champions(?string $employeeId = null): array
    {
        $res = $this->model->getLeaderboardWithStanding($employeeId);
        return [
            'success'  => true,
            'data'     => $res['champions'],
            'standing' => $res['standing'],
            'all'      => $res['all_rankings'] ?? []
        ];
    }

    /**
     * Get Staff Roster for Kudos Multi-Select
     */
    public function getRoster(): array
    {
        return [
            'success' => true,
            'data'    => $this->model->getRoster()
        ];
    }

    /**
     * Get Deterministic Ledger (supervisor sees all or filtered, employee sees theirs only)
     */
    public function getLedger(?string $employeeId = null, ?string $role = null): array
    {
        $isSupervisor = false;
        if (!empty($role)) {
            $r = strtolower(trim($role));
            $isSupervisor = in_array($r, ['supervisor', 'manager', 'hradmin', 'generalmanager', 'depthead', 'director'], true);
        } elseif (!empty($_SESSION['role'])) {
            $r = strtolower(trim($_SESSION['role']));
            $isSupervisor = in_array($r, ['supervisor', 'manager', 'hradmin', 'generalmanager', 'depthead', 'director'], true);
        }

        $targetEmpId = $isSupervisor ? null : $employeeId;
        return [
            'success' => true,
            'data'    => $this->model->getLedger($targetEmpId)
        ];
    }

    /**
     * Get Milestone Badges
     */
    public function getBadges(): array
    {
        return [
            'success' => true,
            'data'    => $this->model->getMilestoneBadges()
        ];
    }

    /**
     * Award Recognition & Record Deterministic XP
     */
    public function giveRecognition(array $payload): array
    {
        $senderType = $payload['senderType'] ?? ($payload['sender_type'] ?? ($_SESSION['role'] ?? 'Supervisor'));
        $defaultSenderName = ($senderType === 'Supervisor') ? ($_SESSION['full_name'] ?? 'Chef Marco Rossi') : ($_SESSION['full_name'] ?? 'Maria Santos');
        $defaultSenderRole = ($senderType === 'Supervisor') ? 'Supervisor' : 'Front Desk Host';
        $defaultSenderId = ($senderType === 'Supervisor') ? ($_SESSION['user_id'] ?? ($_SESSION['employee_id'] ?? 'emp-102')) : ($_SESSION['user_id'] ?? ($_SESSION['employee_id'] ?? 'emp-101'));
        $defaultSenderAvatar = ($senderType === 'Supervisor')
            ? 'https://images.unsplash.com/photo-1577219491135-ce391730fb2c?w=150&auto=format&fit=crop&q=80'
            : 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150&auto=format&fit=crop&q=80';

        $senderName = $payload['senderName'] ?? ($payload['sender_name'] ?? $defaultSenderName);
        if (stripos($senderName, 'Elena Vance') !== false) {
            $senderName = $defaultSenderName;
        }

        $senderRole = $payload['senderRole'] ?? ($payload['sender_role'] ?? $defaultSenderRole);
        if (stripos($senderRole, 'HR Director') !== false) {
            $senderRole = $defaultSenderRole;
        }

        $senderId = $payload['senderId'] ?? ($payload['sender_id'] ?? $defaultSenderId);
        if ($senderId === 'emp-105') {
            $senderId = $defaultSenderId;
        }

        $senderAvatar = $payload['senderAvatar'] ?? ($payload['sender_avatar'] ?? $defaultSenderAvatar);

        $receiverId = $payload['receiverId'] ?? ($payload['receiver_id'] ?? 'emp-101');
        $receiverName = $payload['receiverName'] ?? ($payload['receiver_name'] ?? 'Maria Santos');
        $receiverRole = $payload['receiverRole'] ?? ($payload['receiver_role'] ?? 'Front Desk Host');
        $receiverDept = $payload['receiverDept'] ?? ($payload['receiver_dept'] ?? ($payload['receiverDepartment'] ?? 'Front Office'));
        $receiverAvatar = $payload['receiverAvatar'] ?? ($payload['receiver_avatar'] ?? 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150&auto=format&fit=crop&q=80');

        $categoryKey = $payload['categoryKey'] ?? ($payload['category_key'] ?? 'guest_service');
        $categoryLabel = $payload['categoryLabel'] ?? ($payload['category_label'] ?? 'Great Guest Service');
        $textContent = trim($payload['textContent'] ?? ($payload['text'] ?? ($payload['message'] ?? '')));

        if (empty($textContent)) {
            $textContent = 'Outstanding teamwork and hospitality excellence!';
        }

        // Deterministic Rule Engine for Points:
        // Peer: +50 XP, Supervisor: +100 XP, Executive/GM: +200 XP
        $points = 50;
        if (strcasecmp($senderType, 'Supervisor') === 0) {
            $points = 100;
        } elseif (strcasecmp($senderType, 'Executive') === 0 || strcasecmp($senderType, 'GM') === 0) {
            $points = 200;
        }

        $data = [
            'id'             => !empty($payload['id']) ? trim($payload['id']) : ('post-' . time() . '-' . rand(100, 999)),
            'sender_id'      => $senderId,
            'sender_name'    => $senderName,
            'sender_role'    => $senderRole,
            'sender_type'    => $senderType,
            'sender_avatar'  => $senderAvatar,
            'receiver_id'    => $receiverId,
            'receiver_name'  => $receiverName,
            'receiver_role'  => $receiverRole,
            'receiver_dept'  => $receiverDept,
            'receiver_avatar'=> $receiverAvatar,
            'category_key'   => $categoryKey,
            'category_label' => $categoryLabel,
            'points_awarded' => $points,
            'text_content'   => $textContent,
            'reactions'      => ['clap' => 0, 'heart' => 0, 'star' => 0, 'fire' => 0, 'user_reactions' => []],
            'comments'       => [],
            'created_at'     => date('c')
        ];

        $ok = $this->model->createRecognition($data);
        if ($ok) {
            $this->model->checkAndAwardBadges($receiverId);
        }

        return [
            'success' => $ok,
            'message' => $ok ? 'Recognition awarded & synced to Supabase!' : 'Failed to save recognition.',
            'data'    => $data
        ];
    }

    /**
     * Trigger Automatic LMS XP Grant
     */
    public function triggerLmsQuizPass(array $payload): array
    {
        $recipientId = $payload['employeeId'] ?? 'emp-101';
        $score = (int)($payload['score'] ?? $payload['amount'] ?? 100);
        $lmsPrescribed = $payload['lms_prescribed'] ?? $payload['prescribed_id'] ?? null;
        $quizName = $payload['quizName'] ?? 'Standard Operating Procedure';
        
        if ($score < 80) {
            return [
                'success' => false,
                'message' => 'LMS Quiz score fell below 80% passing threshold. No XP awarded.',
                'data' => [
                    'employeeId' => $recipientId,
                    'amount' => 0
                ]
            ];
        }

        $ok = $this->model->createLmsGrant($recipientId, $score, $quizName, $lmsPrescribed);
        if ($ok) {
            $this->model->checkAndAwardBadges($recipientId);
        }

        return [
            'success' => $ok,
            'message' => $ok ? "LMS XP Grant (+{$score} XP) recorded in ledger!" : 'Failed to record LMS grant.',
            'data' => [
                'employeeId' => $recipientId,
                'amount' => $score
            ]
        ];
    }

    /**
     * Add, toggle, or switch reaction emoji (1 only per user, anti-spam)
     */
    public function addReaction(string $postId, string $reactionType, ?string $userId = null): array
    {
        return $this->model->addReaction($postId, $reactionType, $userId);
    }

    /**
     * Add Comment / Cheer
     */
    public function addComment(string $postId, array $payload): array
    {
        $rawName = $payload['author_name'] ?? ($payload['authorName'] ?? ($_SESSION['full_name'] ?? 'Hospitality Colleague'));
        $authorName = (stripos($rawName, 'Elena Vance') !== false) ? ($_SESSION['full_name'] ?? 'Chef Marco Rossi') : $rawName;

        $rawRole = $payload['author_role'] ?? ($payload['authorRole'] ?? ($_SESSION['role'] ?? 'Team Associate'));
        $authorRole = (stripos($rawRole, 'HR Director') !== false) ? 'Supervisor' : $rawRole;

        $authorAvatar = $payload['author_avatar'] ?? ($payload['authorAvatar'] ?? ($authorRole === 'Supervisor' 
            ? 'https://images.unsplash.com/photo-1577219491135-ce391730fb2c?w=150&auto=format&fit=crop&q=80'
            : 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150&auto=format&fit=crop&q=80'));
        $text = trim($payload['text'] ?? '');

        if (empty($text)) {
            return ['success' => false, 'message' => 'Comment text cannot be empty.'];
        }

        $comment = [
            'author_name'   => $authorName,
            'author_role'   => $authorRole,
            'author_avatar' => $authorAvatar,
            'authorName'    => $authorName,
            'authorRole'    => $authorRole,
            'authorAvatar'  => $authorAvatar,
            'text'          => $text
        ];

        $ok = $this->model->addComment($postId, $comment);
        return [
            'success' => $ok,
            'message' => $ok ? 'Comment posted successfully!' : 'Failed to post comment.',
            'data'    => $comment
        ];
    }

    /**
     * Log Shift Sentiment Pulse
     */
    public function logShiftSentiment(array $payload): array
    {
        $empId = $payload['employeeId'] ?? 'emp-101';
        $empName = $payload['employeeName'] ?? 'Maria Santos';
        $dept = $payload['department'] ?? 'Front Office';
        $score = (int)($payload['sentimentScore'] ?? ($payload['score'] ?? 5));
        $period = $payload['shiftPeriod'] ?? 'Morning Shift';
        $sentimentType = $payload['sentimentType'] ?? ($score >= 4 ? 'Positive' : ($score === 3 ? 'Neutral' : 'Stressful'));
        $note = trim($payload['note'] ?? ($payload['notes'] ?? ''));

        $data = [
            'id'              => 'sent-' . time() . '-' . rand(100, 999),
            'employee_id'     => $empId,
            'employee_name'   => $empName,
            'department'      => $dept,
            'sentiment_score' => $score,
            'shift_period'    => $period,
            'sentiment_type'  => $sentimentType,
            'note'            => $note,
            'created_at'      => date('c')
        ];

        $ok = $this->model->logShiftSentiment($data);
        return [
            'success' => $ok,
            'message' => $ok ? 'Shift sentiment logged successfully to Supabase!' : 'Failed to log sentiment.',
            'data'    => $data
        ];
    }

    /**
     * Get shift sentiments with optional filter
     */
    public function getShiftSentiments(?string $filterType = null, ?string $filterValue = null): array
    {
        return $this->model->getShiftSentiments($filterType, $filterValue);
    }

    /**
     * Check if specific employee has logged shift sentiment today
     */
    public function getUserTodaySentiment(string $employeeId): array
    {
        $today = $this->model->getUserTodayShiftSentiment($employeeId);
        return [
            'success' => true,
            'hasLogged' => $today !== null,
            'data' => $today
        ];
    }
}

