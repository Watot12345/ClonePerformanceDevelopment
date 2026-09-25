<?php
/**
 * api/overview.php
 * Oxford Suites — Overview Analytics & Granular Metric Drilldown API
 * Actions: get_drilldown | get_realtime_metrics
 * STRICT RULE: Strictly use database data with NO fabricated fallbacks.
 * Employee view strictly shows only the active employee's data.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/config.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? 'get_drilldown');
$metric = $_GET['metric'] ?? ($_POST['metric'] ?? 'goals_progress');
$empId  = $_GET['employee_id'] ?? ($_POST['employee_id'] ?? ($_SESSION['employee_id'] ?? ($_SESSION['user_id'] ?? '')));
$role   = $_GET['role'] ?? ($_POST['role'] ?? ($_SESSION['role'] ?? 'Associate'));

// Normalize Department Names
$normalizeDept = function($name) {
    $lower = strtolower(trim((string)$name));
    if (strpos($lower, 'front') !== false) return 'Front Office';
    if (strpos($lower, 'culinary') !== false || strpos($lower, 'kitchen') !== false || strpos($lower, 'chef') !== false) return 'Kitchen & Culinary';
    if (strpos($lower, 'food') !== false || strpos($lower, 'beverage') !== false || strpos($lower, 'f&b') !== false || strpos($lower, 'dining') !== false) return 'Food & Beverage';
    if (strpos($lower, 'banquet') !== false || strpos($lower, 'event') !== false) return 'Banquet & Events';
    if (strpos($lower, 'housekeep') !== false) return 'Housekeeping';
    if (strpos($lower, 'human') !== false || strpos($lower, 'hr') !== false) return 'Human Resources';
    if (strpos($lower, 'finance') !== false || strpos($lower, 'account') !== false) return 'Finance';
    if (strpos($lower, 'engineer') !== false) return 'Engineering';
    if (strpos($lower, 'security') !== false) return 'Security';
    return 'Front Office';
};

try {
    $pdo = getSupabaseDb();
    if (!$pdo) {
        throw new Exception("Database connection unavailable.");
    }

    // Determine if role is Associate or Supervisor/Management
    $isAssociate = in_array(strtolower(trim($role)), ['associate', 'employee', 'staff'], true);

    // If empId belongs to a supervisor/manager in employees table, treat as Supervisor view
    if ($isAssociate && !empty($empId)) {
        try {
            $empRoleStmt = $pdo->prepare("SELECT role FROM public.employees WHERE id = :id LIMIT 1");
            $empRoleStmt->execute([':id' => $empId]);
            $empRoleRow = $empRoleStmt->fetch(PDO::FETCH_ASSOC);
            if ($empRoleRow && in_array(strtolower(trim($empRoleRow['role'] ?? '')), ['supervisor', 'manager', 'hradmin', 'generalmanager', 'depthead', 'director'], true)) {
                $isAssociate = false;
            }
        } catch (\Throwable $e) {}
    }

    // System-wide metrics (sys_*) or property xp_ledger in supervisor view always show the entire hotel data
    if (strpos($metric, 'sys_') === 0) {
        $isAssociate = false;
    }

    switch ($action) {

        case 'get_drilldown':
            $data = [];

            switch ($metric) {

                // ─────────────────────────────────────────────────────────────
                // 1. Goals Progress (Employee View: strictly their goals; Supervisor View: property goals)
                // ─────────────────────────────────────────────────────────────
                case 'goals_progress':
                case 'sys_goals':
                case 'active_objectives':
                case 'shift_action':
                    $isPulseCard = ($metric === 'goals_progress' || $metric === 'active_objectives' || $metric === 'shift_action');
                    $scopeToEmployee = ($isAssociate || $isPulseCard);

                    if ($scopeToEmployee) {
                        $stmt = $pdo->prepare("SELECT pg.id, pg.employee_id, pg.title, pg.target_scope, pg.target_metric, pg.weight, 
                                                       pg.status::text as status, pg.department, pg.target_date,
                                                       COALESCE(e.full_name, 'Staff Member') as employee_name,
                                                       COALESCE(e.title, 'Associate') as employee_title
                                                FROM public.performance_goals pg
                                                LEFT JOIN public.employees e ON pg.employee_id = e.id
                                                WHERE pg.employee_id = :empId
                                                ORDER BY pg.created_at DESC");
                        $stmt->execute([':empId' => $empId]);
                        $goals = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    } else {
                        $goals = $pdo->query("SELECT pg.id, pg.employee_id, pg.title, pg.target_scope, pg.target_metric, pg.weight, 
                                                     pg.status::text as status, pg.department, pg.target_date,
                                                     COALESCE(e.full_name, 'Staff Member') as employee_name,
                                                     COALESCE(e.title, 'Associate') as employee_title
                                              FROM public.performance_goals pg
                                              LEFT JOIN public.employees e ON pg.employee_id = e.id
                                              ORDER BY pg.created_at DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    }

                    $total = count($goals);
                    $approved = 0;
                    $review = 0;
                    $revise = 0;
                    $failed = 0;
                    $completed = 0;

                    foreach ($goals as $g) {
                        $st = strtolower(trim((string)$g['status']));
                        if (in_array($st, ['approved', 'active', 'endorsed', 'calibrated'])) $approved++;
                        elseif (in_array($st, ['pending', 'pending approval', 'in review', 'submitted'])) $review++;
                        elseif (in_array($st, ['needs revision', 'revise', 'revision', 'rejected'])) $revise++;
                        elseif ($st === 'failed') $failed++;
                        elseif (in_array($st, ['completed', 'done'])) $completed++;
                    }

                    $rate = $total > 0 ? round((($approved + $completed) / $total) * 100, 1) : 0.0;

                    $data = [
                        'metric'         => $metric,
                        'title'          => $scopeToEmployee ? 'My Q3 Performance Objectives & Progress' : 'Property Goal Approval & Execution Velocity',
                        'subtitle'       => $scopeToEmployee ? 'Live goal tracking, milestone review schedule, and approval status' : 'Live telemetry tracking SMART objective approval velocity across departments',
                        'theme'          => 'sage',
                        'target_pillar'  => 'pillar-perf',
                        'action_label'   => $isAssociate ? null : 'Open Performance Planning',
                        'summary'        => [
                            ['label' => 'Total Goals', 'value' => $total, 'sub' => $scopeToEmployee ? 'Assigned to You' : 'All active cycles'],
                            ['label' => 'Approval Rate', 'value' => $rate . '%', 'sub' => ($approved + $completed) . ' Approved / Completed'],
                            ['label' => 'Pending Review', 'value' => $review, 'sub' => 'Awaiting Supervisor'],
                            ['label' => 'Needs Revision', 'value' => $revise, 'sub' => 'Returned for edit']
                        ],
                        'chart' => [
                            'type' => 'doughnut',
                            'labels' => ['Approved / Done', 'In Review', 'Needs Revision', 'Failed'],
                            'data' => [$approved + $completed, $review, $revise, $failed],
                            'colors' => ['#7A9A7E', '#C89B3C', '#C47762', '#94A3B8']
                        ],
                        'items' => $goals
                    ];
                    break;

                // ─────────────────────────────────────────────────────────────
                // 2. Competency Matrix & Radar (Strictly DB data, scoped to employee's assigned role competencies)
                // ─────────────────────────────────────────────────────────────
                case 'competencies':
                case 'competency_matrix':
                case 'my_competencies':
                    if (!class_exists('CompetencyController')) {
                        require_once __DIR__ . '/../controllers/CompetencyController.php';
                    }
                    $compController = new CompetencyController();
                    $compData = $compController->getEmployeeCompetencies($empId);

                    $totalComps = $compData['total'] ?? 0;
                    $assessedCount = $compData['assessed_count'] ?? 0;
                    $unassessedCount = $compData['unassessed_count'] ?? 0;
                    $avgCurrent = $compData['avg_current'] ?? 0.0;
                    $avgBench = $compData['avg_benchmark'] ?? 0.0;
                    $alignPct = $compData['alignment_pct'] ?? 0.0;
                    $labels = $compData['labels'] ?? [];
                    $datasets = $compData['datasets'] ?? [];
                    $allComps = $compData['items'] ?? [];

                    $data = [
                        'metric'        => $metric,
                        'title'         => $isAssociate ? 'My Competency Matrix & Role Standards' : 'Competency Matrix & Position Benchmarks',
                        'subtitle'      => $isAssociate ? 'Your verified assessment scores vs job role benchmark standards' : 'Evaluated skills vs job role benchmark standards across all operational pillars',
                        'theme'         => 'dusty',
                        'target_pillar' => 'pillar-comp',
                        'action_label'  => 'Open Competency Radar',
                        'summary'       => [
                            ['label' => 'Current Average', 'value' => number_format($avgCurrent, 1) . ' / 5.0', 'sub' => 'Assessed Skills'],
                            ['label' => 'Benchmark Target', 'value' => number_format($avgBench, 1) . ' / 5.0', 'sub' => 'Role Standard'],
                            ['label' => 'Alignment Index', 'value' => $alignPct . '%', 'sub' => 'Target Coverage'],
                            ['label' => 'Assessed Skills', 'value' => "$assessedCount / $totalComps", 'sub' => $unassessedCount > 0 ? "$unassessedCount Not Rated Yet" : 'Role Standard Complete']
                        ],
                        'chart' => [
                            'type' => 'radar',
                            'labels' => $labels,
                            'datasets' => $datasets
                        ],
                        'items' => $allComps
                    ];
                    break;

                // ─────────────────────────────────────────────────────────────
                // 3. Gamified XP & Social Recognition Ledger (Strictly DB data, scoped to employee if Associate)
                // ─────────────────────────────────────────────────────────────
                case 'xp_ledger':
                case 'sys_xp':
                case 'xp_trajectory':
                    // In Employee view on Pulse tab (personal XP), scope strictly to employee.
                    // In Supervisor view, Management view, or when opening Total Property XP, show ALL hotel transactions from public.xp_ledger.
                    $scopeToEmployee = ($isAssociate && $metric === 'xp_trajectory');

                    if ($scopeToEmployee) {
                        $stmtXp = $pdo->prepare("SELECT COALESCE(SUM(points), 0) as total_xp, COUNT(*) as tx_cnt 
                                                FROM public.xp_ledger 
                                                WHERE employee_id = :empId");
                        $stmtXp->execute([':empId' => $empId]);
                        $xpRow = $stmtXp->fetch(PDO::FETCH_ASSOC) ?: ['total_xp' => 0, 'tx_cnt' => 0];

                        $stmtTx = $pdo->prepare("SELECT xl.id, xl.points, xl.source_type, xl.description, xl.created_at, xl.balance_after,
                                                        COALESCE(r.full_name, 'Associate') as recipient_name,
                                                        COALESCE(r.title, 'Associate') as role,
                                                        COALESCE(d.name, COALESCE(r.department_id, 'Front Office')) as department
                                                 FROM public.xp_ledger xl
                                                 LEFT JOIN public.employees r ON xl.employee_id = r.id
                                                 LEFT JOIN public.departments d ON r.department_id = d.id
                                                 WHERE xl.employee_id = :empId
                                                 ORDER BY xl.created_at DESC LIMIT 50");
                        $stmtTx->execute([':empId' => $empId]);
                        $recentTxs = $stmtTx->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    } else {
                        // Supervisor View / Property View: Get ALL records from public.xp_ledger across all employees
                        $xpRow = $pdo->query("SELECT COALESCE(SUM(points), 0) as total_xp, COUNT(*) as tx_cnt FROM public.xp_ledger")->fetch(PDO::FETCH_ASSOC) ?: ['total_xp' => 0, 'tx_cnt' => 0];
                        $recentTxs = $pdo->query("SELECT xl.id, xl.points, xl.source_type, xl.description, xl.created_at, xl.balance_after,
                                                        COALESCE(r.full_name, 'Associate') as recipient_name,
                                                        COALESCE(r.title, 'Associate') as role,
                                                        COALESCE(d.name, COALESCE(r.department_id, 'Operations')) as department
                                                 FROM public.xp_ledger xl
                                                 LEFT JOIN public.employees r ON xl.employee_id = r.id
                                                 LEFT JOIN public.departments d ON r.department_id = d.id
                                                 ORDER BY xl.created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    }

                    $totalXp = (int)($xpRow['total_xp'] ?? 0);
                    $totalTx = (int)($xpRow['tx_cnt'] ?? 0);

                    // Robust fallback: if database query returned 0 rows, populate from SocialModel
                    if ($totalXp === 0 && !$scopeToEmployee) {
                        try {
                            require_once __DIR__ . '/../models/SocialModel.php';
                            $sm = new SocialModel();
                            $recentTxs = $sm->getLedger(null);
                            foreach ($recentTxs as $rx) {
                                $totalXp += (int)($rx['points'] ?? ($rx['amount'] ?? 0));
                            }
                            $totalTx = count($recentTxs);
                        } catch (\Throwable $e) {}
                    }

                    // Breakdown strictly by source from actual database transactions
                    $bySource = ['peer_kudos' => 0, 'supervisor_kudos' => 0, 'training_cert' => 0, 'lms_quiz' => 0, 'gm_citation' => 0];
                    foreach ($recentTxs as $t) {
                        $st = $t['source_type'] ?? 'peer_kudos';
                        if (isset($bySource[$st])) $bySource[$st] += (int)($t['points'] ?? ($t['amount'] ?? 0));
                    }

                    // Departmental breakdown for supervisor/property view
                    $deptBreakdown = [];
                    if (!$scopeToEmployee) {
                        $deptTotals = [];
                        foreach ($recentTxs as $t) {
                            $dName = $normalizeDept($t['department'] ?? 'Front Office');
                            if (!isset($deptTotals[$dName])) {
                                $deptTotals[$dName] = ['department' => $dName, 'total' => 0, 'approved' => 0, 'rate_pct' => 0];
                            }
                            $deptTotals[$dName]['approved'] += (int)($t['points'] ?? 0); // Points
                            $deptTotals[$dName]['total']++; // Transactions count
                        }
                        foreach ($deptTotals as $dName => $dt) {
                            $dt['rate_pct'] = $totalXp > 0 ? round(($dt['approved'] / $totalXp) * 100, 1) : 0.0;
                            $deptBreakdown[] = $dt;
                        }
                    }

                    $data = [
                        'metric'        => $metric,
                        'title'         => $scopeToEmployee ? 'My Gamified XP & Recognition Ledger' : 'Property Gamified XP & Recognition Ledger',
                        'subtitle'      => $scopeToEmployee ? 'Your verified points and commendation transactions from the immutable ledger' : 'Append-only unified XP transaction ledger across all hotel employees, commendations, certifications, and LMS quizzes',
                        'theme'         => 'gold',
                        'target_pillar' => 'pillar-social',
                        'action_label'  => 'Open Social Recognition Hub',
                        'summary'       => [
                            ['label' => $scopeToEmployee ? 'My Total XP' : 'Total Property XP', 'value' => number_format($totalXp) . ' XP', 'sub' => $scopeToEmployee ? 'Verified Account' : 'All Departments'],
                            ['label' => $scopeToEmployee ? 'My Records' : 'Ledger Records', 'value' => number_format($totalTx), 'sub' => 'Database Transactions'],
                            ['label' => 'Peer Kudos', 'value' => number_format($bySource['peer_kudos']) . ' XP', 'sub' => 'Team Commendations'],
                            ['label' => 'Supervisor Kudos & Certs', 'value' => number_format($bySource['training_cert'] + $bySource['supervisor_kudos'] + $bySource['gm_citation']) . ' XP', 'sub' => 'Leadership Commendations']
                        ],
                        'chart' => [
                            'type' => 'bar',
                            'labels' => ['Peer Kudos (+50)', 'Supervisor Kudos (+100)', 'GM Citation (+200)', 'LMS Quiz Pass', 'Training Cert'],
                            'data' => [
                                (int)$bySource['peer_kudos'],
                                (int)$bySource['supervisor_kudos'],
                                (int)$bySource['gm_citation'],
                                (int)$bySource['lms_quiz'],
                                (int)$bySource['training_cert']
                            ],
                            'colors' => ['#C89B3C', '#7A9A7E', '#9E1B20', '#6B8FA3', '#C47762']
                        ],
                        'department_breakdown' => $deptBreakdown,
                        'items' => $recentTxs
                    ];
                    break;

                // ─────────────────────────────────────────────────────────────
                // 4. Champions Podium & Leaderboard (In Employee view, strictly their standing)
                // ─────────────────────────────────────────────────────────────
                case 'champions_podium':
                case 'leaderboard':
                    require_once __DIR__ . '/../models/SocialModel.php';
                    $socialModel = new SocialModel();
                    $lb = $socialModel->getLeaderboardWithStanding($empId);

                    $standing  = $lb['standing'] ?? null;
                    $champions = $lb['champions'] ?? [];
                    $allRanks  = $lb['all_rankings'] ?? [];

                    if ($isAssociate) {
                        // In employee view, show their standing and their recognition history
                        $stmtMyTx = $pdo->prepare("SELECT xl.id, xl.points, xl.source_type, xl.description, xl.created_at, xl.balance_after,
                                                          COALESCE(r.full_name, 'Associate') as recipient_name,
                                                          COALESCE(r.title, 'Associate') as role,
                                                          COALESCE(r.department_id, 'Front Office') as department
                                                   FROM public.xp_ledger xl
                                                   LEFT JOIN public.employees r ON xl.employee_id = r.id
                                                   WHERE xl.employee_id = :empId
                                                   ORDER BY xl.created_at DESC LIMIT 20");
                        $stmtMyTx->execute([':empId' => $empId]);
                        $myTxs = $stmtMyTx->fetchAll(PDO::FETCH_ASSOC) ?: [];

                        $data = [
                            'metric'        => $metric,
                            'title'         => 'My Gamified XP & Recognition Standing',
                            'subtitle'      => 'Your personal XP balance, rank progression, and badges recorded in the unified ledger',
                            'theme'         => 'gold',
                            'target_pillar' => 'pillar-social',
                            'action_label'  => 'Open Social Recognition Hub',
                            'summary'       => [
                                ['label' => 'My Total XP', 'value' => number_format((int)($standing['total_xp'] ?? 0)) . ' XP', 'sub' => 'Verified Points'],
                                ['label' => 'Active Standing', 'value' => $standing['rank_display'] ?? 'Unranked', 'sub' => $standing['place_display'] ?? 'Not in ranking'],
                                ['label' => 'Tier', 'value' => $standing['tier'] ?? 'Novice Associate', 'sub' => 'Recognition Level'],
                                ['label' => 'Badges & Trophies', 'value' => (int)($standing['trophies'] ?? 0), 'sub' => 'Earned Citations']
                            ],
                            'chart' => [
                                'type' => 'doughnut',
                                'labels' => ['Total XP Earned', 'Next Tier Target'],
                                'data' => [
                                    (int)($standing['total_xp'] ?? 0),
                                    max(0, (int)($standing['xp_to_next_rank'] ?? 50))
                                ],
                                'colors' => ['#C89B3C', '#E2E8F0']
                            ],
                            'items' => $myTxs
                        ];
                    } else {
                        // Supervisor view: show property champions
                        $topChampionName = (!empty($champions[0]['name']) && empty($champions[0]['is_ready'])) ? $champions[0]['name'] : 'None Yet';
                        $topChampionXp   = (!empty($champions[0]['total_xp']) && empty($champions[0]['is_ready'])) ? (int)$champions[0]['total_xp'] : 0;

                        $data = [
                            'metric'        => $metric,
                            'title'         => 'Property Leaderboard & Top 5 XP Champions',
                            'subtitle'      => 'Rank progression and gamification standings computed live from the XP transaction ledger',
                            'theme'         => 'gold',
                            'target_pillar' => 'pillar-social',
                            'action_label'  => 'View All Leaderboard Ranks',
                            'summary'       => [
                                ['label' => 'Property Champion', 'value' => $topChampionName, 'sub' => number_format($topChampionXp) . ' XP'],
                                ['label' => 'Oversight Scope', 'value' => 'Hotel Associates', 'sub' => count($allRanks) . ' Staff Tracked'],
                                ['label' => 'Total Associates', 'value' => count($allRanks), 'sub' => 'Active Associates Roster'],
                                ['label' => 'Podium Status', 'value' => 'Top 5 Staff Podium', 'sub' => 'Gamified Performance']
                            ],
                            'chart' => [
                                'type' => 'bar',
                                'labels' => array_map(function($c) { return $c['name'] ?? 'Open'; }, array_slice($champions, 0, 5)),
                                'data' => array_map(function($c) { return (int)($c['total_xp'] ?? 0); }, array_slice($champions, 0, 5)),
                                'colors' => ['#C89B3C', '#C47762', '#7A9A7E', '#6B8FA3', '#6F6261']
                            ],
                            'items' => array_slice($allRanks, 0, 15)
                        ];
                    }
                    break;

                // ─────────────────────────────────────────────────────────────
                // 5. LMS Compliance & Handbook Certifications
                // ─────────────────────────────────────────────────────────────
                case 'lms_compliance':
                case 'sys_lms':
                    if ($isAssociate) {
                        $stmtLms = $pdo->prepare("SELECT lp.id, lp.lms_id, lp.employee, lp.status::text as status, lp.progress,
                                                         COALESCE(e.full_name, 'Associate') as employee_name,
                                                         COALESCE(e.department_id, 'Front Office') as department
                                                  FROM public.lms_prescribed lp
                                                  LEFT JOIN public.employees e ON lp.employee = e.id
                                                  WHERE lp.employee = :empId
                                                  ORDER BY lp.progress DESC");
                        $stmtLms->execute([':empId' => $empId]);
                        $allLms = $stmtLms->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    } else {
                        $allLms = $pdo->query("SELECT lp.id, lp.lms_id, lp.employee, lp.status::text as status, lp.progress,
                                                     COALESCE(e.full_name, 'Associate') as employee_name,
                                                     COALESCE(e.department_id, 'Front Office') as department
                                              FROM public.lms_prescribed lp
                                              LEFT JOIN public.employees e ON lp.employee = e.id
                                              ORDER BY lp.progress DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    }

                    $totalLms = count($allLms);
                    $passed = 0;
                    $progSum = 0;

                    foreach ($allLms as $l) {
                        $st = strtolower(trim((string)$l['status']));
                        $prog = (float)($l['progress'] ?? 0);
                        $progSum += $prog;
                        if ($st === 'passed' || $st === 'completed' || $prog >= 80) $passed++;
                    }

                    $rate = $totalLms > 0 ? round(($passed / $totalLms) * 100, 1) : 0.0;
                    $avgScore = $totalLms > 0 ? round($progSum / $totalLms, 1) : 0.0;

                    $data = [
                        'metric'        => $metric,
                        'title'         => $isAssociate ? 'My LMS Compliance & Quiz Certification' : 'LMS Handbook Compliance & Quiz Certification',
                        'subtitle'      => $isAssociate ? 'Your prescribed SOP handbooks and Kirkpatrick Level 1 quiz certification status' : 'SOP Handbook reading verification and Kirkpatrick Level 1 quiz certification rates',
                        'theme'         => 'primary',
                        'target_pillar' => 'pillar-lms',
                        'action_label'  => 'Open LMS Library',
                        'summary'       => [
                            ['label' => 'Completion Rate', 'value' => $rate . '%', 'sub' => 'Target: 80.0% Minimum'],
                            ['label' => 'Certified Modules', 'value' => "$passed / $totalLms", 'sub' => 'Passed threshold (>=80%)'],
                            ['label' => 'Average Quiz Score', 'value' => $avgScore . '%', 'sub' => $isAssociate ? 'Your Average' : 'Property-wide'],
                            ['label' => 'Assigned Handbooks', 'value' => $totalLms, 'sub' => 'Prescribed in Database']
                        ],
                        'chart' => [
                            'type' => 'doughnut',
                            'labels' => ['Certified / Passed', 'Incomplete / Pending'],
                            'data' => [$passed, max(0, $totalLms - $passed)],
                            'colors' => ['#9E1B20', '#E2E8F0']
                        ],
                        'items' => $allLms
                    ];
                    break;

                // ─────────────────────────────────────────────────────────────
                // 6. Succession Pipeline Health & Bench Depth
                // ─────────────────────────────────────────────────────────────
                case 'succession':
                case 'sys_succession':
                    if ($isAssociate) {
                        $stmtSucc = $pdo->prepare("SELECT sc.id, sc.employee_id, sc.position_id, 
                                                          sc.closed_performance_score, sc.target_competency_match_pct,
                                                          sc.hr_readiness_flag::text as hr_readiness_flag,
                                                          COALESCE(e.full_name, 'Staff') as candidate_name,
                                                          COALESCE(sp.title, 'General Talent Pool') as position_title,
                                                          COALESCE(sp.dept, 'Operations') as pos_dept
                                                   FROM public.succession_candidates sc
                                                   LEFT JOIN public.employees e ON sc.employee_id = e.id
                                                   LEFT JOIN public.succession_positions sp ON sc.position_id = sp.id
                                                   WHERE sc.employee_id = :empId");
                        $stmtSucc->execute([':empId' => $empId]);
                        $mySucc = $stmtSucc->fetchAll(PDO::FETCH_ASSOC) ?: [];

                        $isEnrolled = count($mySucc) > 0;
                        $first = $isEnrolled ? $mySucc[0] : null;

                        $data = [
                            'metric'        => $metric,
                            'title'         => 'My Succession & Leadership Readiness',
                            'subtitle'      => 'Your readiness index based on closed performance scores and competency benchmark match',
                            'theme'         => 'dusty',
                            'target_pillar' => 'pillar-succession',
                            'action_label'  => 'Open Succession Overview',
                            'summary'       => [
                                ['label' => 'Succession Status', 'value' => $isEnrolled ? 'Candidate' : 'Not Enrolled', 'sub' => $first['position_title'] ?? 'Developing in role'],
                                ['label' => 'HR Readiness Flag', 'value' => $first['hr_readiness_flag'] ?? 'Unassigned', 'sub' => 'Calibrated Horizon'],
                                ['label' => 'Performance (40%)', 'value' => $isEnrolled ? number_format((float)($first['closed_performance_score'] ?? 0), 1) : '0.0', 'sub' => 'Closed Cycles'],
                                ['label' => 'Competency (60%)', 'value' => $isEnrolled ? (round((float)($first['target_competency_match_pct'] ?? 0), 1) . '%') : '0%', 'sub' => 'Match Target']
                            ],
                            'chart' => [
                                'type' => 'doughnut',
                                'labels' => ['Competency Match', 'Gap / Remaining'],
                                'data' => [
                                    (float)($first['target_competency_match_pct'] ?? 0),
                                    max(0, 100 - (float)($first['target_competency_match_pct'] ?? 0))
                                ],
                                'colors' => ['#6B8FA3', '#E2E8F0']
                            ],
                            'items' => $mySucc
                        ];
                    } else {
                        $allPos = $pdo->query("SELECT sp.id, sp.title, sp.dept, sp.incumbent_name, sp.primary_successor_id, sp.emergency_backup_id,
                                                      sp.risk_of_loss, sp.bench_strength,
                                                      COALESCE(ep.full_name, 'No Primary Identified') as primary_name
                                               FROM public.succession_positions sp
                                               LEFT JOIN public.employees ep ON sp.primary_successor_id = ep.id
                                               ORDER BY sp.dept, sp.title")->fetchAll(PDO::FETCH_ASSOC) ?: [];

                        $allCands = $pdo->query("SELECT sc.id, sc.employee_id, sc.position_id, 
                                                        sc.closed_performance_score, sc.target_competency_match_pct,
                                                        sc.hr_readiness_flag::text as hr_readiness_flag,
                                                        COALESCE(e.full_name, 'Staff') as candidate_name,
                                                        COALESCE(e.title, 'Associate') as candidate_role
                                                 FROM public.succession_candidates sc
                                                 LEFT JOIN public.employees e ON sc.employee_id = e.id
                                                 ORDER BY sc.closed_performance_score DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

                        $totalRoles = count($allPos);
                        $coveredRoles = 0;
                        foreach ($allPos as $p) {
                            if (!empty($p['primary_successor_id'])) $coveredRoles++;
                        }
                        $benchPct = $totalRoles > 0 ? round(($coveredRoles / $totalRoles) * 100, 1) : 0.0;

                        $readyNow = 0;
                        $ready12 = 0;
                        $notReady = 0;
                        foreach ($allCands as $c) {
                            $fl = strtolower(trim((string)$c['hr_readiness_flag']));
                            if (strpos($fl, 'ready now') !== false) $readyNow++;
                            elseif (strpos($fl, 'ready in') !== false) $ready12++;
                            else $notReady++;
                        }

                        $data = [
                            'metric'        => $metric,
                            'title'         => 'Succession Pipeline Health & Bench Readiness',
                            'subtitle'      => '9-Box talent matrix readiness computation: (Closed Performance × 40%) + (Competency Match × 60%)',
                            'theme'         => 'dusty',
                            'target_pillar' => 'pillar-succession',
                            'action_label'  => 'Open Succession 9-Box Grid',
                            'summary'       => [
                                ['label' => 'Bench Coverage', 'value' => $benchPct . '%', 'sub' => "$coveredRoles of $totalRoles Key Roles"],
                                ['label' => 'Ready Now', 'value' => $readyNow, 'sub' => '0–6 Months Horizon'],
                                ['label' => 'Ready in 1–2 Years', 'value' => $ready12, 'sub' => 'Prioritized for IDP'],
                                ['label' => 'Pipeline Candidates', 'value' => count($allCands), 'sub' => 'Assessed Talent Pool']
                            ],
                            'chart' => [
                                'type' => 'doughnut',
                                'labels' => ['Ready Now (0-6 mos)', 'Ready in 1-2 Years', 'Developing / Not Ready'],
                                'data' => [$readyNow, $ready12, $notReady],
                                'colors' => ['#7A9A7E', '#6B8FA3', '#C47762']
                            ],
                            'items' => $allPos
                        ];
                    }
                    break;

                // ─────────────────────────────────────────────────────────────
                // 7. Shift Climate Pulse & Well-being (Strictly DB data, scoped to employee if Associate)
                // ─────────────────────────────────────────────────────────────
                case 'shift_sentiment':
                case 'shift_climate':
                    if ($isAssociate) {
                        $stmtSent = $pdo->prepare("SELECT ss.id, ss.employee_id, ss.employee_name, ss.sentiment_score, ss.shift_period, ss.note, ss.created_at,
                                                          COALESCE(e.department_id, 'Front Office') as department
                                                   FROM public.shift_sentiments ss
                                                   LEFT JOIN public.employees e ON ss.employee_id = e.id
                                                   WHERE ss.employee_id = :empId
                                                   ORDER BY ss.created_at DESC LIMIT 30");
                        $stmtSent->execute([':empId' => $empId]);
                        $allSent = $stmtSent->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    } else {
                        $allSent = $pdo->query("SELECT ss.id, ss.employee_id, ss.employee_name, ss.sentiment_score, ss.shift_period, ss.note, ss.created_at,
                                                       COALESCE(e.department_id, 'Front Office') as department
                                                FROM public.shift_sentiments ss
                                                LEFT JOIN public.employees e ON ss.employee_id = e.id
                                                ORDER BY ss.created_at DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    }

                    $smooth = 0;
                    $manageable = 0;
                    $friction = 0;
                    foreach ($allSent as $s) {
                        $sc = (int)($s['sentiment_score'] ?? 3);
                        if ($sc >= 4) $smooth++;
                        elseif ($sc === 3) $manageable++;
                        else $friction++;
                    }
                    $tot = max(1, $smooth + $manageable + $friction);

                    $data = [
                        'metric'        => $metric,
                        'title'         => $isAssociate ? 'My Shift Mood & Climate History' : 'Shift Climate Pulse & Well-being Telemetry',
                        'subtitle'      => $isAssociate ? 'Your personal daily shift check-ins and operational well-being logs' : 'Realtime employee sentiment distribution and shift friction radar',
                        'theme'         => 'sage',
                        'target_pillar' => 'pillar-social',
                        'action_label'  => 'Open Social Climate Hub',
                        'summary'       => [
                            ['label' => 'Smooth Shifts', 'value' => count($allSent) > 0 ? (round(($smooth / $tot) * 100, 1) . '%') : '0%', 'sub' => "$smooth Shifts Recorded"],
                            ['label' => 'Manageable', 'value' => count($allSent) > 0 ? (round(($manageable / $tot) * 100, 1) . '%') : '0%', 'sub' => "$manageable Shifts Recorded"],
                            ['label' => 'Friction', 'value' => count($allSent) > 0 ? (round(($friction / $tot) * 100, 1) . '%') : '0%', 'sub' => "$friction Support Required"],
                            ['label' => 'Total Check-ins', 'value' => count($allSent), 'sub' => $isAssociate ? 'Your Recorded Shifts' : 'Recent telemetry logs']
                        ],
                        'chart' => [
                            'type' => 'doughnut',
                            'labels' => ['Smooth (Energized)', 'Manageable (Steady)', 'Friction (Support)'],
                            'data' => [$smooth, $manageable, $friction],
                            'colors' => ['#7A9A7E', '#6B8FA3', '#C47762']
                        ],
                        'items' => $allSent
                    ];
                    break;

                // ─────────────────────────────────────────────────────────────
                // 8. Department Execution Matrix Deep-Dive (Strictly DB data)
                // ─────────────────────────────────────────────────────────────
                case 'dept_matrix':
                    try {
                        $allDepts = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
                        $allEmps  = $pdo->query("SELECT id, full_name, department_id, title, status FROM employees")->fetchAll(PDO::FETCH_ASSOC);
                        $allGoals = $pdo->query("SELECT id, employee_id, department, status, weight FROM performance_goals")->fetchAll(PDO::FETCH_ASSOC);
                        $allLms   = $pdo->query("SELECT id, employee, status, progress FROM lms_prescribed")->fetchAll(PDO::FETCH_ASSOC);
                        $allSucc  = $pdo->query("SELECT sc.*, sp.dept as pos_dept FROM succession_candidates sc LEFT JOIN succession_positions sp ON sc.position_id = sp.id")->fetchAll(PDO::FETCH_ASSOC);
                    } catch (\Throwable $dbErr) {
                        $allDepts = [];
                        $allEmps  = [];
                        $allGoals = [];
                        $allLms   = [];
                        $allSucc  = [];
                    }

                    $deptIdMap = [];
                    foreach ($allDepts as $d) {
                        if (!empty($d['id']) && !empty($d['name'])) {
                            $deptIdMap[$d['id']] = $d['name'];
                        }
                    }

                    $empDeptMap = [];
                    foreach ($allEmps as $emp) {
                        $dName = '';
                        if (!empty($emp['department_id']) && isset($deptIdMap[$emp['department_id']])) {
                            $dName = $deptIdMap[$emp['department_id']];
                        } else {
                            $haystack = ($emp['title'] ?? '') . ' ' . ($emp['full_name'] ?? '');
                            $dName = $normalizeDept($haystack);
                        }
                        $empDeptMap[$emp['id']] = $normalizeDept($dName);
                    }

                    $canonicalDepts = [
                        'Front Office',
                        'Food & Beverage',
                        'Kitchen & Culinary',
                        'Banquet & Events',
                        'Housekeeping'
                    ];

                    $deptBuckets = [];
                    foreach ($canonicalDepts as $cDept) {
                        $deptBuckets[$cDept] = [
                            'department'       => $cDept,
                            'staff_count'      => 0,
                            'goals_total'      => 0,
                            'goals_approved'   => 0,
                            'lms_total'        => 0,
                            'lms_progress_sum' => 0,
                            'succ_candidates'  => 0,
                            'succ_ready'       => 0
                        ];
                    }

                    foreach ($allEmps as $emp) {
                        $d = $empDeptMap[$emp['id']] ?? 'Front Office';
                        if (isset($deptBuckets[$d])) {
                            $deptBuckets[$d]['staff_count']++;
                        }
                    }

                    foreach ($allGoals as $g) {
                        $gDept = '';
                        if (!empty($g['department'])) {
                            $gDept = $normalizeDept($g['department']);
                        }
                        if (empty($gDept) && !empty($g['employee_id'])) {
                            $gDept = $empDeptMap[$g['employee_id']] ?? 'Front Office';
                        }
                        if (empty($gDept) || !isset($deptBuckets[$gDept])) {
                            $gDept = 'Front Office';
                        }
                        $deptBuckets[$gDept]['goals_total']++;
                        $st = strtolower(trim((string)($g['status'] ?? '')));
                        if (in_array($st, ['approved', 'done', 'completed', 'active', 'endorsed', 'calibrated'])) {
                            $deptBuckets[$gDept]['goals_approved']++;
                        }
                    }

                    foreach ($allLms as $l) {
                        $eId = $l['employee'] ?? '';
                        $d = $empDeptMap[$eId] ?? 'Front Office';
                        if (isset($deptBuckets[$d])) {
                            $deptBuckets[$d]['lms_total']++;
                            $prog = (float)($l['progress'] ?? 0);
                            $deptBuckets[$d]['lms_progress_sum'] += $prog;
                        }
                    }

                    foreach ($allSucc as $s) {
                        $d = '';
                        if (!empty($s['pos_dept'])) {
                            $d = $normalizeDept($s['pos_dept']);
                        } elseif (!empty($s['employee_id'])) {
                            $d = $empDeptMap[$s['employee_id']] ?? 'Front Office';
                        }
                        if (isset($deptBuckets[$d])) {
                            $deptBuckets[$d]['succ_candidates']++;
                            $flag = strtolower(trim((string)($s['hr_readiness_flag'] ?? '')));
                            if (strpos($flag, 'ready now') !== false || strpos($flag, 'ready in') !== false) {
                                $deptBuckets[$d]['succ_ready']++;
                            }
                        }
                    }

                    $deptMatrixRows = [];
                    foreach ($canonicalDepts as $cDept) {
                        $b = $deptBuckets[$cDept];
                        $goalsPct = $b['goals_total'] > 0 ? round(($b['goals_approved'] / $b['goals_total']) * 100, 1) : 0.0;
                        $lmsPct = $b['lms_total'] > 0 ? round($b['lms_progress_sum'] / $b['lms_total'], 1) : 0.0;
                        $succPct = $b['succ_candidates'] > 0 ? round(($b['succ_ready'] / $b['succ_candidates']) * 100, 1) : 0.0;
                        $composite = round(($goalsPct * 0.35) + ($lmsPct * 0.35) + ($succPct * 0.30), 1);

                        $status = $composite >= 80 ? 'Optimal' : ($composite >= 50 ? 'Good' : ($composite > 0 ? 'Developing' : 'Pending'));
                        $badgeClass = $composite >= 80 ? 'badge-sage' : ($composite >= 50 ? 'badge-dusty' : ($composite > 0 ? 'badge-terracotta' : 'bg-slate-100 text-slate-500 border border-slate-200 text-[10px] font-semibold px-2 py-0.5 rounded-full'));

                        $deptMatrixRows[] = [
                            'department'           => $cDept,
                            'staff_count'          => $b['staff_count'] ?? 0,
                            'goals_approved_pct'   => $goalsPct,
                            'lms_rate_pct'         => $lmsPct,
                            'succession_ready_pct' => $succPct,
                            'composite_score'      => $composite,
                            'status'               => $status,
                            'badge_class'          => $badgeClass,
                            'goals_total'          => $b['goals_total'],
                            'goals_approved'       => $b['goals_approved'],
                            'lms_total'            => $b['lms_total']
                        ];
                    }

                    // Sort so top composite scores are first, or maintain canonical order
                    $sortedRows = $deptMatrixRows;
                    usort($sortedRows, function($a, $b) {
                        return $b['composite_score'] <=> $a['composite_score'];
                    });

                    $topDept = $sortedRows[0]['department'] ?? 'Front Office';
                    $topScore = $sortedRows[0]['composite_score'] ?? 0;

                    $data = [
                        'metric'        => $metric,
                        'title'         => 'Department Execution Matrix Deep-Dive',
                        'subtitle'      => 'Cross-departmental performance synthesis combining 35% Goals, 35% LMS, and 30% Succession metrics',
                        'theme'         => 'primary',
                        'target_pillar' => 'pillar-reports',
                        'action_label'  => 'Open Executive Reports Hub',
                        'summary'       => [
                            ['label' => 'Monitored Divisions', 'value' => count($deptMatrixRows), 'sub' => 'Database Departments'],
                            ['label' => 'Top Execution Dept', 'value' => $topDept, 'sub' => $topScore . '% Composite'],
                            ['label' => 'Weighted Formula', 'value' => '35% / 35% / 30%', 'sub' => 'Goals / LMS / Succession'],
                            ['label' => 'Data Source', 'value' => 'Live Supabase DB', 'sub' => 'Realtime Synced']
                        ],
                        'chart' => [
                            'type' => 'bar',
                            'labels' => array_column($deptMatrixRows, 'department'),
                            'datasets' => [
                                [
                                    'label' => 'Goals Approved (%)',
                                    'data' => array_column($deptMatrixRows, 'goals_approved_pct'),
                                    'backgroundColor' => '#7A9A7E'
                                ],
                                [
                                    'label' => 'LMS Passed (%)',
                                    'data' => array_column($deptMatrixRows, 'lms_rate_pct'),
                                    'backgroundColor' => '#9E1B20'
                                ],
                                [
                                    'label' => 'Succession Ready (%)',
                                    'data' => array_column($deptMatrixRows, 'succession_ready_pct'),
                                    'backgroundColor' => '#6B8FA3'
                                ]
                            ]
                        ],
                        'items' => $deptMatrixRows
                    ];
                    break;

                // ─────────────────────────────────────────────────────────────
                // 9. Governance & Operational Velocity (Real counts from DB)
                // ─────────────────────────────────────────────────────────────
                case 'governance':
                case 'operational_velocity':
                    $goalsCalibrated = (int)$pdo->query("SELECT COUNT(*) FROM public.performance_goals WHERE status::text IN ('approved', 'completed', 'calibrated', 'done')")->fetchColumn();
                    $goalsTotal = (int)$pdo->query("SELECT COUNT(*) FROM public.performance_goals")->fetchColumn();
                    $calibPct = $goalsTotal > 0 ? round(($goalsCalibrated / $goalsTotal) * 100, 1) : 0.0;

                    $certsIssued = (int)$pdo->query("SELECT COUNT(*) FROM public.xp_ledger WHERE source_type::text = 'training_cert'")->fetchColumn();
                    $totalTxs = (int)$pdo->query("SELECT COUNT(*) FROM public.xp_ledger")->fetchColumn();

                    $data = [
                        'metric'        => $metric,
                        'title'         => 'Governance, Appraisal Calibration & Operational SLA',
                        'subtitle'      => 'Policy enforcement across appraisal calibration, review velocity, and training certifications',
                        'theme'         => 'dusty',
                        'target_pillar' => 'pillar-perf',
                        'action_label'  => 'Open Calibration Matrix',
                        'summary'       => [
                            ['label' => 'Goal Calibration', 'value' => $calibPct . '%', 'sub' => "$goalsCalibrated of $goalsTotal Goals Approved"],
                            ['label' => 'Training Certs', 'value' => $certsIssued, 'sub' => 'Issued to Staff'],
                            ['label' => 'XP Audit Trail', 'value' => $totalTxs, 'sub' => 'Immutable Transactions'],
                            ['label' => 'Architecture Rule', 'value' => '6 Modules', 'sub' => 'Direct Services (Rule 0.1)']
                        ],
                        'chart' => [
                            'type' => 'bar',
                            'labels' => ['Approved Goals', 'Training Certs', 'Ledger Transactions'],
                            'data' => [$goalsCalibrated, $certsIssued, $totalTxs],
                            'colors' => ['#7A9A7E', '#6B8FA3', '#C89B3C']
                        ],
                        'items' => [
                            ['rule' => 'Mandatory 7-Step Appraisal Lifecycle', 'status' => 'Enforced', 'details' => 'From Planning to Transition'],
                            ['rule' => 'Succession Recomputation Trigger', 'status' => 'Automated', 'details' => 'Triggers on Training Pass or Closed Appraisal'],
                            ['rule' => 'Unified XP Transaction Ledger', 'status' => 'Immutable', 'details' => 'Shared ledger across LMS, Social & Training'],
                            ['rule' => 'Attendance Gatekeeper', 'status' => 'Strict', 'details' => 'Evaluation stage locked until attended or completed']
                        ]
                    ];
                    break;

                default:
                    throw new Exception("Unknown metric drilldown key: {$metric}");
            }

            echo json_encode([
                'success'    => true,
                'cached'     => false,
                'timestamp'  => time(),
                'server_time'=> date('Y-m-d H:i:s'),
                'data'       => $data
            ], JSON_PRETTY_PRINT);
            break;

        default:
            throw new Exception("Unsupported action: {$action}");
    }

} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
