<?php
// Load cached overview metrics & staff gamification standings (ultra-fast disk cache)
$cacheFile = __DIR__ . '/../cache/overview_metrics.json';
$cached = null;
if (file_exists($cacheFile)) {
    $cached = @json_decode(file_get_contents($cacheFile), true);
}

$overviewChampions   = $cached['overviewChampions'] ?? null;
$overviewStandingMap = $cached['overviewStandingMap'] ?? [];
$overviewAllRankings = $cached['overviewAllRankings'] ?? [];

if (!$overviewChampions || empty($overviewStandingMap)) {
    require_once __DIR__ . '/../models/SocialModel.php';
    $socialModelOverview = new SocialModel();
    $lbOverview = $socialModelOverview->getLeaderboardWithStanding();
    $overviewChampions   = $lbOverview['champions'];
    $overviewStandingMap = $lbOverview['standing_map'] ?? [];
    $overviewAllRankings = $lbOverview['all_rankings'] ?? [];
}

$activeEmpId = $_SESSION['employee_id'] ?? ($_SESSION['user_id'] ?? '');
$activeStanding = null;

if (!empty($activeEmpId)) {
    $activeStanding = $overviewStandingMap[$activeEmpId]
        ?? ($overviewStandingMap[strtolower(trim($activeEmpId))] ?? null);
}

// If not found in cache map (e.g. new user or updated profile), query live from database
if (!$activeStanding && !empty($activeEmpId)) {
    require_once __DIR__ . '/../models/SocialModel.php';
    $socialModelOverview = new SocialModel();
    $liveData = $socialModelOverview->getLeaderboardWithStanding($activeEmpId);
    $activeStanding = $liveData['standing'] ?? null;
}

// Fallback dynamically sourced from the active session (no hardcoded person)
if (!$activeStanding) {
    $activeStanding = [
        'employee_id'      => $activeEmpId ?: 'emp-current',
        'name'             => $_SESSION['full_name'] ?? ($_SESSION['name'] ?? 'Associate'),
        'role'             => $_SESSION['role'] ?? 'Associate',
        'department'       => $_SESSION['department'] ?? 'Operations',
        'avatar'           => $_SESSION['avatar'] ?? '',
        'total_xp'         => 0,
        'trophies'         => 0,
        'is_ranked'        => false,
        'rank'             => null,
        'place_number'     => null,
        'place_display'    => 'Not in ranking',
        'rank_display'     => 'Not in ranking',
        'rank_badge'       => '—',
        'tier'             => 'Novice Associate',
        'in_top_5'         => false,
        'total_associates' => count($overviewAllRankings) ?: 1,
        'xp_to_top_5'      => 50,
        'xp_to_next_rank'  => 50,
        'percentile'       => 0
    ];
}

global $isAssociate;
if (!isset($isAssociate)) {
    $role = $_SESSION['role'] ?? 'Associate';
    $isAssociate = in_array(strtolower(trim($role)), ['associate', 'employee', 'staff']);
}
$pulseTabClass = $isAssociate ? 'active' : '';
$systemTabClass = $isAssociate ? '' : 'active';
?>
<!-- ======================================================== -->
<div id="panel-dashboard" class="pillar-panel active space-y-6">

                            <!-- Top Sub-Navigation Pills (Overview Hub Sub-tabs) -->
                            <div
                                class="subnav-track flex items-center justify-between gap-2 p-1.5 overflow-x-auto custom-scrollbar">
                                <div class="flex items-center space-x-1.5 flex-nowrap">
                                    <button onclick="switchSubTab('dashboard', 'pulse')"
                                        class="subnav-pill subnav-dashboard <?= $pulseTabClass ?> whitespace-nowrap <?= !$isAssociate ? 'hidden' : '' ?>" data-sub="pulse">
                                        <i class="fas fa-user-clock mr-1.5 text-primary"></i>
                                        <span>1. Shift Focus &amp; My Pulse</span>
                                    </button>
                                    <button onclick="switchSubTab('dashboard', 'system')"
                                        class="subnav-pill subnav-dashboard <?= $systemTabClass ?> whitespace-nowrap <?= $isAssociate ? 'hidden' : '' ?>" data-sub="system">
                                        <i class="fas fa-chart-line mr-1.5 text-dusty-dark"></i>
                                        <span>2. System &amp; Property Analytics</span>
                                    </button>
                                </div>
                            </div>

                            <!-- SUB-TAB 1: INDIVIDUAL SHIFT FOCUS & MY PULSE -->
                            <div id="sub-dashboard-pulse" class="sub-panel-dashboard <?= $pulseTabClass ?> space-y-6">

                                <!-- 1. Focused "Today's Shift Action" Card -->
                                <div onclick="openOverviewDrilldown('shift_action')"
                                    class="card-hero p-6 relative overflow-hidden bg-white cursor-pointer hover:shadow-lg hover:border-slate-300 transition-all duration-200 group" title="Click to view Shift Milestones & Calibration Details">
                                    <div class="absolute top-4 right-4 text-slate-300 group-hover:text-primary transition-colors text-xs pointer-events-none hidden sm:flex items-center space-x-1 font-semibold">
                                        <span>Shift Telemetry</span>
                                        <i class="fas fa-arrow-up-right-from-square text-[10px]"></i>
                                    </div>
                                    <div
                                        class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-4">
                                        <div class="space-y-1.5">
                                            <div
                                                class="inline-flex items-center space-x-2 px-3 py-1 rounded-full bg-brand-canvas text-slate-700 text-[11px] font-semibold border border-brand-border">
                                                <span
                                                    class="w-1.5 h-1.5 rounded-full bg-sage-dark animate-pulse"></span>
                                                <span>Active Shift Telemetry · <?= htmlspecialchars($activeStanding['department'] ?? 'Front Office') ?></span>
                                            </div>
                                            <h2 id="hero-greeting-text"
                                                class="font-heading font-bold text-2xl sm:text-3xl text-slate-900">
                                                Welcome back, <?= htmlspecialchars($activeStanding['name'] ?? 'Associate') ?></h2>
                                            <p id="hero-greeting-subtext" class="text-xs text-slate-500">Personal Performance &amp; Development Pulse · <?= htmlspecialchars($activeStanding['role'] ?? 'Associate') ?></p>
                                        </div>
                                        <div class="flex items-center gap-2.5 flex-wrap">
                                            <button onclick="event.stopPropagation(); openModal('modal-create-goal')"
                                                class="btn-primary px-4 py-2.5 text-xs font-bold flex items-center space-x-2">
                                                <i class="fas fa-plus text-xs"></i>
                                                <span>Set New Goal</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- 3 Database-Driven KPI Metric Cards with Loading State Overlays -->
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

                                    <!-- Card 1: Q3 Goals Progress -->
                                    <div onclick="openOverviewDrilldown('goals_progress')" class="card-clean p-5 space-y-3 relative overflow-hidden cursor-pointer hover:shadow-md hover:border-sage/40 transition-all duration-200 group" title="Click to view Goals Breakdown & Progress">
                                        <div class="absolute top-3.5 right-3 text-slate-300 group-hover:text-sage-dark transition-colors text-[10px] pointer-events-none flex items-center space-x-0.5 font-semibold">
                                            <i class="fas fa-expand text-[9px]"></i>
                                        </div>
                                        <!-- Loading Overlay -->
                                        <div id="kpi-goals-loading" class="overview-loading-overlay flex absolute inset-0 bg-white/85 backdrop-blur-2xs flex-col items-center justify-center z-10 transition-opacity duration-300">
                                            <div class="w-6 h-6 rounded-full border-2 border-sage-dark/20 border-t-sage-dark animate-spin mb-1"></div>
                                            <span class="text-[10px] font-semibold text-slate-500">Querying Goals...</span>
                                        </div>

                                        <div class="flex justify-between items-center text-xs text-slate-500 font-medium pr-4">
                                            <span class="font-semibold text-slate-700">Q3 Goals Progress</span>
                                            <span id="kpi-goals-ratio" class="badge-sage animate-pulse"><i class="fas fa-circle-notch fa-spin text-[9px] mr-1"></i>Loading...</span>
                                        </div>
                                        <div class="flex items-baseline space-x-2">
                                            <span id="kpi-goals-pct" class="text-3xl font-heading font-bold text-slate-900 inline-flex items-center"><span class="inline-block w-14 h-7 bg-slate-200/80 rounded-md animate-pulse"></span></span>
                                            <span id="kpi-goals-status" class="text-xs text-slate-400 font-semibold animate-pulse"><i class="fas fa-circle-notch fa-spin text-[9px] mr-1"></i>Syncing...</span>
                                        </div>
                                        <div class="w-full bg-brand-canvas h-1.5 rounded-full overflow-hidden border border-brand-border/50">
                                            <div id="kpi-goals-bar" class="bg-sage h-1.5 rounded-full transition-all duration-500" style="width: 0%"></div>
                                        </div>
                                        <p id="kpi-goals-subtitle" class="text-[11px] text-slate-400 animate-pulse">Syncing active Q3 goals...</p>
                                        <script>
                                            (function() {
                                                try {
                                                    var cached = window.PerfCache ? window.PerfCache.get('planning_data') : null;
                                                    if (cached && Array.isArray(cached.goals) && cached.goals.length > 0) {
                                                        var userObj = window.currentUser || JSON.parse(localStorage.getItem('oxford_session_user') || '{}');
                                                        var uid = (userObj.id || userObj.employee_code || '').toLowerCase().trim();
                                                        var eg = cached.goals.filter(function(g) {
                                                            var ge = (g.employee_id || '').toLowerCase().trim();
                                                            return ge === uid || (userObj.id && ge === String(userObj.id).toLowerCase()) || (userObj.employee_code && ge === String(userObj.employee_code).toLowerCase());
                                                        });
                                                        if (eg.length > 0) {
                                                            var comp = eg.filter(function(g){ var s = (g.status||'').toLowerCase(); return s==='approved'||s==='completed'||s==='passed'||s==='done'; }).length;
                                                            var p = Math.round((comp / eg.length) * 100);
                                                            var elPct = document.getElementById('kpi-goals-pct');
                                                            if (elPct) elPct.textContent = p + '%';
                                                            var elRatio = document.getElementById('kpi-goals-ratio');
                                                            if (elRatio) { elRatio.className = 'badge-sage'; elRatio.textContent = comp + ' of ' + eg.length + ' Passed (' + eg.length + '/2 Set)'; }
                                                            var elSub = document.getElementById('kpi-goals-subtitle');
                                                            if (elSub) { elSub.className = 'text-[11px] text-slate-400'; elSub.textContent = (eg.length - comp) + ' goals in progress'; }
                                                            var elBar = document.getElementById('kpi-goals-bar');
                                                            if (elBar) elBar.style.width = p + '%';
                                                            var elStatus = document.getElementById('kpi-goals-status');
                                                            if (elStatus) {
                                                                if (p >= 75) { elStatus.className = 'text-xs text-sage-dark font-semibold'; elStatus.innerHTML = '<i class="fas fa-check"></i> On Track'; }
                                                                else { elStatus.className = 'text-xs text-dusty-dark font-semibold'; elStatus.innerHTML = '<i class="fas fa-clock"></i> In Progress'; }
                                                            }
                                                            var elGl = document.getElementById('kpi-goals-loading');
                                                            if (elGl) elGl.classList.add('hidden');
                                                        }
                                                    }
                                                } catch(e) {}
                                            })();
                                        </script>
                                    </div>

                                    <!-- Card 2: Competency Matrix -->
                                    <div onclick="openOverviewDrilldown('competencies')" class="card-clean p-5 space-y-3 relative overflow-hidden cursor-pointer hover:shadow-md hover:border-dusty/40 transition-all duration-200 group" title="Click to view Competency Standards & Radar">
                                        <div class="absolute top-3.5 right-3 text-slate-300 group-hover:text-dusty-dark transition-colors text-[10px] pointer-events-none flex items-center space-x-0.5 font-semibold">
                                            <i class="fas fa-expand text-[9px]"></i>
                                        </div>
                                        <!-- Loading Overlay -->
                                        <div id="kpi-comp-loading" class="overview-loading-overlay hidden absolute inset-0 bg-white/85 backdrop-blur-2xs flex-col items-center justify-center z-10">
                                            <div class="w-6 h-6 rounded-full border-2 border-dusty-dark/20 border-t-dusty-dark animate-spin mb-1"></div>
                                            <span class="text-[10px] font-semibold text-slate-500">Querying Competencies...</span>
                                        </div>

                                        <div class="flex justify-between items-center text-xs text-slate-500 font-medium pr-4">
                                            <span class="font-semibold text-slate-700">Competency Matrix</span>
                                            <span id="kpi-comp-level" class="badge-dusty">Level 1</span>
                                        </div>
                                        <div class="flex items-baseline space-x-2">
                                            <span id="kpi-comp-val" class="text-3xl font-heading font-bold text-slate-900">0.0<span class="text-base text-slate-400 font-normal">/5</span></span>
                                            <span id="kpi-comp-tier" class="text-xs text-dusty-dark font-semibold">Core Tier</span>
                                        </div>
                                        <div class="w-full bg-brand-canvas h-1.5 rounded-full overflow-hidden border border-brand-border/50">
                                            <div id="kpi-comp-bar" class="bg-dusty h-1.5 rounded-full transition-all duration-500" style="width: 0%"></div>
                                        </div>
                                        <p id="kpi-comp-subtitle" class="text-[11px] text-slate-400">Position benchmark alignment</p>
                                    </div>

                                    <!-- Card 3: Gamified XP -->
                                    <div onclick="openOverviewDrilldown('xp_ledger')" class="card-clean p-5 space-y-3 relative overflow-hidden cursor-pointer hover:shadow-md hover:border-gold/40 transition-all duration-200 group" title="Click to view XP Ledger & Recognition History">
                                        <div class="absolute top-3.5 right-3 text-slate-300 group-hover:text-gold-dark transition-colors text-[10px] pointer-events-none flex items-center space-x-0.5 font-semibold">
                                            <i class="fas fa-expand text-[9px]"></i>
                                        </div>
                                        <!-- Loading Overlay -->
                                        <div id="kpi-xp-loading" class="overview-loading-overlay hidden absolute inset-0 bg-white/85 backdrop-blur-2xs flex-col items-center justify-center z-10">
                                            <div class="w-6 h-6 rounded-full border-2 border-gold/20 border-t-gold animate-spin mb-1"></div>
                                            <span class="text-[10px] font-semibold text-slate-500">Querying XP Ledger...</span>
                                        </div>

                                        <div class="flex justify-between items-center text-xs text-slate-500 font-medium pr-4">
                                            <span class="font-semibold text-slate-700">Gamified XP</span>
                                            <span id="kpi-xp-level-badge" class="badge-gold">Level 1</span>
                                        </div>
                                        <div class="flex items-baseline space-x-2">
                                            <span id="kpi-xp-val" class="text-3xl font-heading font-bold text-gold-dark">0 <span class="text-xs font-normal text-slate-400">XP</span></span>
                                            <span id="kpi-xp-title" class="text-xs text-slate-500 font-semibold">Novice Associate</span>
                                        </div>
                                        <div class="w-full bg-brand-canvas h-1.5 rounded-full overflow-hidden border border-brand-border/50">
                                            <div id="kpi-xp-bar" class="bg-gold h-1.5 rounded-full transition-all duration-500" style="width: 0%"></div>
                                        </div>
                                        <p id="kpi-xp-subtitle" class="text-[11px] text-slate-400">250 XP to Bronze Tier</p>
                                        <script>
                                            (function() {
                                                try {
                                                    var activeId = window.currentUser?.id || '';
                                                    if (activeId) {
                                                        var rawCached = localStorage.getItem('oxford_cached_total_xp_' + activeId);
                                                        if (rawCached !== null) {
                                                            var xp = parseInt(rawCached, 10) || 0;
                                                            var elVal = document.getElementById('kpi-xp-val');
                                                            if (elVal) elVal.innerHTML = xp.toLocaleString() + ' <span class="text-xs font-normal text-slate-400">XP</span>';
                                                        }
                                                    }
                                                } catch(e) {}
                                            })();
                                        </script>
                                    </div>

                                </div>

                                <!-- Top Row Side-by-Side: My Active Performance Objectives (Left) & Top 5 Gamified XP Champions (Right) -->
                                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-stretch">

                                    <!-- Left Column: Individual Performance Objectives Card (Live Supabase Data) -->
                                    <div class="card-clean p-6 space-y-4 flex flex-col justify-between relative overflow-hidden">
                                        <div class="flex flex-col h-full">
                                            <div onclick="openOverviewDrilldown('active_objectives')" class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-100 pb-3 shrink-0 cursor-pointer group" title="Click to view Objectives Drilldown">
                                                <div class="space-y-0.5">
                                                    <div class="flex items-center space-x-2">
                                                        <h3 class="font-heading font-bold text-base text-slate-900 group-hover:text-primary transition-colors">
                                                            My Active Performance Objectives</h3>
                                                        <span id="emp-pulse-goals-count" class="badge-primary animate-pulse"><i class="fas fa-circle-notch fa-spin text-[9px] mr-1"></i>Loading...</span>
                                                        <span class="opacity-0 group-hover:opacity-100 transition-opacity text-[10px] text-primary font-semibold hidden sm:inline-flex items-center gap-0.5">
                                                            <i class="fas fa-expand text-[9px]"></i>
                                                        </span>
                                                    </div>
                                                </div>
                                                <button onclick="event.stopPropagation(); openModal('modal-create-goal')"
                                                    class="btn-primary px-3.5 py-1.5 text-xs font-bold inline-flex items-center space-x-1.5 self-start sm:self-auto shadow-2xs">
                                                    <i class="fas fa-plus text-xs"></i>
                                                    <span>Set Objective</span>
                                                </button>
                                            </div>
                                            <div id="emp-pulse-goals-container" class="grid grid-cols-1 gap-4 pt-4 min-h-75 max-h-115 overflow-y-auto custom-scrollbar pr-1.5" style="contain: layout style;">
                                                <!-- Dynamic live goals skeleton loader (replaced once Supabase query completes) -->
                                                <div class="animate-pulse space-y-4 col-span-full">
                                                    <!-- Skeleton Card 1 -->
                                                    <div class="p-4.5 rounded-2xl bg-white border border-slate-200/80 shadow-2xs space-y-3">
                                                        <div class="flex justify-between items-start gap-3">
                                                            <div class="space-y-1.5 flex-1">
                                                                <div class="h-4 bg-slate-200/80 rounded-md w-3/4"></div>
                                                                <div class="h-3 bg-slate-200/50 rounded-md w-1/2"></div>
                                                            </div>
                                                            <div class="h-5 bg-slate-200/70 rounded-full w-24 shrink-0"></div>
                                                        </div>
                                                        <div class="space-y-1.5 pt-1">
                                                            <div class="flex justify-between text-xs">
                                                                <div class="h-3 bg-slate-200/60 rounded w-20"></div>
                                                                <div class="h-3 bg-slate-200/60 rounded w-10"></div>
                                                            </div>
                                                            <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                                                                <div class="bg-slate-200/80 h-2 rounded-full w-1/3"></div>
                                                            </div>
                                                        </div>
                                                        <div class="pt-2 border-t border-slate-100 flex items-center justify-between">
                                                            <div class="h-3 bg-slate-200/50 rounded w-28"></div>
                                                            <div class="h-6 bg-slate-200/60 rounded-lg w-16"></div>
                                                        </div>
                                                    </div>
                                                    <!-- Skeleton Card 2 -->
                                                    <div class="p-4.5 rounded-2xl bg-white border border-slate-200/80 shadow-2xs space-y-3">
                                                        <div class="flex justify-between items-start gap-3">
                                                            <div class="space-y-1.5 flex-1">
                                                                <div class="h-4 bg-slate-200/80 rounded-md w-2/3"></div>
                                                                <div class="h-3 bg-slate-200/50 rounded-md w-2/5"></div>
                                                            </div>
                                                            <div class="h-5 bg-slate-200/70 rounded-full w-24 shrink-0"></div>
                                                        </div>
                                                        <div class="space-y-1.5 pt-1">
                                                            <div class="flex justify-between text-xs">
                                                                <div class="h-3 bg-slate-200/60 rounded w-20"></div>
                                                                <div class="h-3 bg-slate-200/60 rounded w-10"></div>
                                                            </div>
                                                            <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                                                                <div class="bg-slate-200/80 h-2 rounded-full w-1/2"></div>
                                                            </div>
                                                        </div>
                                                        <div class="pt-2 border-t border-slate-100 flex items-center justify-between">
                                                            <div class="h-3 bg-slate-200/50 rounded w-32"></div>
                                                            <div class="h-6 bg-slate-200/60 rounded-lg w-16"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <?php if ($isAssociate): ?>
                                    <!-- Right Column: Employee View: My Personal Gamified XP & Recognition Standing -->
                                    <div class="card-clean p-6 space-y-5 flex flex-col justify-between">
                                        <?php
                                        $isRankedPulse = !empty($activeStanding['is_ranked']) && (int)($activeStanding['total_xp'] ?? 0) > 0;
                                        $inTop5Pulse = !empty($activeStanding['in_top_5']) && $isRankedPulse;
                                        ?>
                                        <div>
                                            <!-- Card Header: Title & Personal Standing Pill -->
                                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-100 pb-3">
                                                <div class="space-y-0.5">
                                                    <div class="flex items-center space-x-2">
                                                        <h3 class="font-heading font-bold text-base text-slate-900">
                                                            My Gamified XP &amp; Standing</h3>
                                                        <span class="badge-gold">Personal Record</span>
                                                    </div>
                                                </div>
                                                <?php if ($isRankedPulse): ?>
                                                    <div id="emp-pulse-standing-badge" class="inline-flex items-center space-x-2 px-3 py-1.5 rounded-full bg-gold-50/90 border border-gold-200 text-gold-dark text-xs font-bold shadow-2xs self-start sm:self-auto">
                                                        <i class="fas fa-medal text-gold"></i>
                                                        <span id="emp-pulse-standing-pill-text">Your Rank: <?= htmlspecialchars($activeStanding['rank_display']) ?> (<?= htmlspecialchars($activeStanding['place_display']) ?>)</span>
                                                    </div>
                                                <?php else: ?>
                                                    <div id="emp-pulse-standing-badge" class="inline-flex items-center space-x-2 px-3 py-1.5 rounded-full bg-slate-100 border border-slate-200 text-slate-600 text-xs font-semibold shadow-2xs self-start sm:self-auto">
                                                        <i class="fas fa-award text-slate-400"></i>
                                                        <span id="emp-pulse-standing-pill-text">Not in ranking (0 XP)</span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Personal Standing Card (Full & Prominent) -->
                                            <div id="employee-personal-standing-card" onclick="openOverviewDrilldown('champions_podium')" class="p-5 rounded-2xl bg-white border border-brand-border shadow-2xs flex flex-col gap-4 transition-all mt-4 cursor-pointer hover:shadow-md hover:border-gold/40 group relative" title="Click to view My Recognition Drilldown">
                                                <div class="absolute top-3 right-3 text-slate-300 group-hover:text-gold-dark transition-colors text-[10px] pointer-events-none flex items-center space-x-0.5 font-semibold">
                                                    <i class="fas fa-expand text-[9px]"></i>
                                                </div>
                                                <!-- Top Row: Rank Badge + Identity -->
                                                <div class="flex items-center space-x-3.5 min-w-0 pr-4">
                                                    <?php $rankBadgeStyle = $inTop5Pulse ? 'bg-linear-to-br from-gold via-amber-400 to-amber-600 text-white' : ($isRankedPulse ? 'bg-slate-900 text-white border-2 border-slate-700' : 'bg-slate-100 text-slate-400 border border-slate-200'); ?>
                                                    <div id="emp-standing-rank-badge" class="w-14 h-14 rounded-2xl <?= $rankBadgeStyle ?> flex flex-col items-center justify-center font-heading font-black shadow-2xs shrink-0">
                                                        <span class="text-[8px] sm:text-[9px] uppercase tracking-wider <?= $isRankedPulse ? 'opacity-80 text-white' : 'text-slate-400' ?> leading-none"><?= $isRankedPulse ? 'RANK' : 'UNRANKED' ?></span>
                                                        <span id="emp-standing-rank-num" class="text-base sm:text-xl font-bold leading-none mt-0.5 <?= $isRankedPulse ? 'text-white' : 'text-slate-400' ?>"><?= $isRankedPulse ? htmlspecialchars($activeStanding['rank_display']) : '—' ?></span>
                                                    </div>
                                                    <!-- Name, Role, & Status -->
                                                    <div class="space-y-1 min-w-0 flex-1">
                                                        <div class="flex items-center space-x-1.5 flex-wrap">
                                                            <h4 id="emp-standing-name" class="font-heading font-bold text-slate-900 text-sm truncate">
                                                                <?= htmlspecialchars($activeStanding['name']) ?>
                                                            </h4>
                                                            <span id="emp-standing-tier-badge" class="badge-gold text-[9px] py-0.2">
                                                                <?= htmlspecialchars($activeStanding['tier']) ?>
                                                            </span>
                                                        </div>
                                                        <p id="emp-standing-role-dept" class="text-[11px] text-slate-500 font-medium truncate">
                                                            <?= htmlspecialchars($activeStanding['role']) ?> · <?= htmlspecialchars($activeStanding['department']) ?>
                                                        </p>
                                                        <div id="emp-standing-place-summary" class="inline-flex items-center space-x-1.5 text-[10px] font-semibold text-slate-600 bg-slate-100/90 px-2.5 py-0.5 rounded-full border border-slate-200">
                                                            <?php if ($isRankedPulse): ?>
                                                                <i class="fas fa-chart-simple text-slate-400 text-[9px]"></i>
                                                                <span>Currently in <strong><?= htmlspecialchars($activeStanding['place_display']) ?></strong></span>
                                                            <?php else: ?>
                                                                <i class="fas fa-info-circle text-slate-400 text-[9px]"></i>
                                                                <span>Not in ranking (0 XP) · Earn XP to rank</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Live XP Metrics Grid -->
                                                <div class="grid grid-cols-3 gap-2.5 w-full pt-2 border-t border-slate-100">
                                                    <div class="px-3 py-2 rounded-xl bg-brand-canvas border border-brand-border text-center">
                                                        <span class="text-[9px] font-semibold text-slate-400 block uppercase tracking-wider">Total XP</span>
                                                        <span id="emp-standing-xp-val" class="text-base font-heading font-bold text-gold-dark"><?= number_format((int)$activeStanding['total_xp']) ?></span>
                                                    </div>
                                                    <div class="px-3 py-2 rounded-xl bg-brand-canvas border border-brand-border text-center">
                                                        <span class="text-[9px] font-semibold text-slate-400 block uppercase tracking-wider">Trophies</span>
                                                        <span id="emp-standing-trophies-val" class="text-base font-heading font-bold text-slate-800"><?= (int)$activeStanding['trophies'] ?> <i class="fas fa-trophy text-[11px] text-amber-500"></i></span>
                                                    </div>
                                                    <div class="px-3 py-2 rounded-xl bg-brand-canvas border border-brand-border text-center">
                                                        <span class="text-[9px] font-semibold text-slate-400 block uppercase tracking-wider">Next Target</span>
                                                        <span id="emp-standing-gap-val" class="text-xs font-heading font-bold <?= $inTop5Pulse ? 'text-emerald-700' : ($isRankedPulse ? 'text-terracotta-dark' : 'text-slate-500') ?>">
                                                            <?= $inTop5Pulse ? 'Podium Top 5' : ($isRankedPulse ? ('+' . number_format((int)$activeStanding['xp_to_next_rank']) . ' XP') : '+50 XP to rank') ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Personal Recognition Milestone Info Banner -->
                                            <div class="mt-4 p-4 rounded-2xl bg-amber-50/60 border border-amber-200/70 flex items-start space-x-3">
                                                <div class="w-8 h-8 rounded-xl bg-amber-100 text-amber-800 flex items-center justify-center text-sm shrink-0 mt-0.5">
                                                    <i class="fas fa-hand-holding-heart"></i>
                                                </div>
                                                <div class="space-y-1">
                                                    <h5 class="font-bold text-xs text-slate-900">Gamification &amp; Recognition Rules</h5>
                                                    <p class="text-[11px] text-slate-600 leading-relaxed">
                                                        Earn <strong>+50 XP</strong> for peer kudos, <strong>+100 XP</strong> for supervisor commendations, and points for passing SOP quizzes. All transactions are logged to your personal immutable ledger.
                                                    </p>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="pt-3 border-t border-slate-100 flex items-center justify-between">
                                            <span class="text-[11px] text-slate-500">Unified XP Ledger: <strong class="text-slate-800">Immutable Audit Trail</strong></span>
                                            <button type="button" onclick="switchPillar('pillar-social')" class="text-xs font-bold text-primary hover:underline inline-flex items-center space-x-1">
                                                <span>Recognition Wall</span>
                                                <i class="fas fa-arrow-right text-[10px]"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <!-- Right Column: Supervisor View: Top 5 Gamified XP Champions & Personal Rank Standing -->
                                    <div class="card-clean p-6 space-y-5 flex flex-col justify-between">
                                        <?php
                                        $isRankedPulse = !empty($activeStanding['is_ranked']) && (int)($activeStanding['total_xp'] ?? 0) > 0;
                                        $inTop5Pulse = !empty($activeStanding['in_top_5']) && $isRankedPulse;
                                        ?>
                                        <div>
                                            <!-- Card Header: Title & Personal Standing Pill -->
                                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-100 pb-3">
                                                <div class="space-y-0.5">
                                                    <div class="flex items-center space-x-2">
                                                        <h3 class="font-heading font-bold text-base text-slate-900">
                                                            Top 5 Gamified XP Champions</h3>
                                                        <span class="badge-gold">Property Leaderboard</span>
                                                    </div>
                                                </div>
                                                 <div id="emp-pulse-standing-badge" class="inline-flex items-center space-x-2 px-3 py-1.5 rounded-full bg-sage-50 border border-sage-200 text-sage-dark text-xs font-bold shadow-2xs self-start sm:self-auto">
                                                     <i class="fas fa-users-gear text-sage-dark"></i>
                                                     <span id="emp-pulse-standing-pill-text">Associate Rankings Oversight</span>
                                                 </div>
                                            </div>

                                            <!-- 5-Column Stepped Podium (Always exactly 5 slots) -->
                                            <div class="bg-brand-canvas border border-brand-border rounded-2xl p-3 sm:p-4 mt-4">
                                                <div class="relative pt-2">
                                                    <!-- Connecting Horizontal Bar behind pillars -->
                                                    <div class="absolute bottom-11 left-0 right-0 h-2 bg-brand-border rounded-full z-0 hidden sm:block"></div>

                                                    <div id="employee-top5-podium" class="grid grid-cols-5 gap-1.5 sm:gap-2.5 items-end relative z-10">
                                                    <?php
                                                    $rankStylesPulse = [
                                                        1 => ['avatarBg' => 'bg-gold', 'xpPill' => 'text-gold-dark bg-gold-50 border border-gold-100', 'pillarBg' => 'bg-gold', 'heightClass' => 'h-40 sm:h-48', 'labelColor' => 'text-gold-dark', 'bounceStar' => true],
                                                        2 => ['avatarBg' => 'bg-terracotta', 'xpPill' => 'text-terracotta-dark bg-terracotta-50 border border-terracotta-100', 'pillarBg' => 'bg-terracotta', 'heightClass' => 'h-32 sm:h-40', 'labelColor' => 'text-terracotta', 'bounceStar' => false],
                                                        3 => ['avatarBg' => 'bg-sage-dark', 'xpPill' => 'text-sage-dark bg-sage-50 border border-sage-100', 'pillarBg' => 'bg-sage-dark', 'heightClass' => 'h-26 sm:h-32', 'labelColor' => 'text-sage-dark', 'bounceStar' => false],
                                                        4 => ['avatarBg' => 'bg-dusty', 'xpPill' => 'text-dusty-dark bg-dusty-50 border border-dusty-100', 'pillarBg' => 'bg-dusty', 'heightClass' => 'h-20 sm:h-26', 'labelColor' => 'text-dusty', 'bounceStar' => false],
                                                        5 => ['avatarBg' => 'bg-[#6F6261]', 'xpPill' => 'text-slate-700 bg-slate-100 border border-slate-200', 'pillarBg' => 'bg-[#6F6261]', 'heightClass' => 'h-16 sm:h-20', 'labelColor' => 'text-slate-600', 'bounceStar' => false],
                                                    ];

                                                    foreach ($overviewChampions as $c):
                                                        $xp = (int)($c['total_xp'] ?? 0);
                                                        $rank = (int)($c['rank'] ?? 1);
                                                        $st = $rankStylesPulse[$rank] ?? $rankStylesPulse[5];
                                                        $rankBadge = str_pad((string)$rank, 2, '0', STR_PAD_LEFT);
                                                        $displayLabel = $c['rank_label'] ?? ('RANK ' . $rank);
                                                        $isSelf = (!empty($c['employee_id']) && $c['employee_id'] === $activeStanding['employee_id']);

                                                        if (!empty($c['is_ready'])):
                                                    ?>
                                                        <!-- Ready Empty State Slot -->
                                                        <div class="flex flex-col items-center justify-end text-center group cursor-pointer" onclick="switchPillar('pillar-social')" title="Open Podium Position <?= $rank ?>: Ready for Contender">
                                                            <div class="mb-2 flex flex-col items-center space-y-1 w-full opacity-60">
                                                                <div class="w-6 h-6 sm:w-7 sm:h-7 rounded-full border-2 border-dashed border-slate-300 bg-white/70 text-slate-400 font-bold text-[9px] sm:text-[10px] flex items-center justify-center shadow-2xs">
                                                                    <i class="fas fa-plus text-[8px] sm:text-[9px] text-slate-400"></i>
                                                                </div>
                                                                <p class="text-[9px] sm:text-[10px] font-bold text-slate-400 truncate max-w-full">Ready</p>
                                                                <span class="text-[7px] sm:text-[8px] font-medium text-slate-400 bg-slate-100/80 border border-dashed border-slate-200 px-1 py-0.2 rounded-full">-- XP</span>
                                                                <div class="pt-0.5 text-slate-200 text-xs sm:text-base">
                                                                    <i class="far fa-star"></i>
                                                                </div>
                                                            </div>
                                                            <div class="w-full <?= $st['heightClass'] ?> rounded-t-xl sm:rounded-t-2xl bg-slate-100/80 border-2 border-dashed border-slate-200 shadow-2xs group-hover:border-slate-300 transition-all duration-300 flex flex-col items-center justify-between py-2 px-1 text-slate-400">
                                                                <div class="w-5 h-5 sm:w-6 sm:h-6 rounded-full border-2 border-dashed border-slate-300 bg-white/80 flex items-center justify-center font-bold text-[9px] sm:text-[10px] text-slate-400 shadow-2xs mt-0.5">
                                                                    <?= $rankBadge ?>
                                                                </div>
                                                                <div class="space-y-0.5 text-center">
                                                                    <p class="text-[8px] sm:text-[9px] font-bold text-slate-400 uppercase tracking-wider">Ready</p>
                                                                    <span class="text-[7px] sm:text-[8px] font-medium text-slate-400 bg-black/5 px-1 py-0.5 rounded-full inline-flex items-center space-x-0.5">
                                                                        <span>Open</span>
                                                                    </span>
                                                                </div>
                                                            </div>
                                                            <div class="pt-1.5 text-center w-full bg-slate-100/90 sm:bg-transparent rounded-b-lg sm:rounded-none">
                                                                <span class="text-[8px] sm:text-[9px] font-bold tracking-wider text-slate-400 uppercase"><?= htmlspecialchars($displayLabel) ?></span>
                                                                <p class="text-[7px] text-slate-400 font-medium hidden sm:block">Awaiting XP</p>
                                                            </div>
                                                        </div>
                                                    <?php else:
                                                        $xpDisplay = $xp >= 1000 ? number_format($xp / 1000, 1) . 'k XP' : ($xp . ' XP');
                                                        $parts = preg_split('/\s+/', trim($c['name'] ?? 'Staff'));
                                                        $firstName = $parts[0] ?? 'Staff';
                                                        $initials = count($parts) > 1 ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1)) : strtoupper(substr($parts[0], 0, 2));

                                                        if (!empty($c['is_tied'])) {
                                                            $ordinals = [1 => '1ST', 2 => '2ND', 3 => '3RD', 4 => '4TH', 5 => '5TH'];
                                                            $displayLabel = 'TIED ' . ($ordinals[$rank] ?? $rank);
                                                        }
                                                        $roleShort = str_replace(['Director', 'Supervisor', 'Associate'], ['Dir', 'Sup', 'Assoc'], $c['role'] ?? 'Associate');
                                                    ?>
                                                        <!-- Active Champion Slot -->
                                                        <div class="flex flex-col items-center justify-end text-center group cursor-pointer <?= $isSelf ? 'scale-105 transition-transform' : '' ?>" onclick="switchPillar('pillar-social')" title="<?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['role']) ?>): <?= number_format($xp) ?> XP">
                                                            <div class="mb-2 flex flex-col items-center space-y-1 w-full relative">
                                                                <?php if ($isSelf): ?>
                                                                    <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-gold-dark text-white text-[7px] font-extrabold px-1.5 py-0.5 rounded-full shadow-xs border border-white tracking-wider z-20">YOU</span>
                                                                <?php endif; ?>
                                                                <div class="w-6 h-6 sm:w-7 sm:h-7 rounded-full <?= $st['avatarBg'] ?> text-white font-bold text-[9px] sm:text-[10px] flex items-center justify-center shadow-xs border-2 <?= $isSelf ? 'border-amber-400 ring-2 ring-gold' : 'border-white' ?>">
                                                                    <?= htmlspecialchars($initials) ?>
                                                                </div>
                                                                <p class="text-[9px] sm:text-[10px] font-bold text-slate-900 truncate max-w-full" title="<?= htmlspecialchars($c['name']) ?>"><?= htmlspecialchars($firstName) ?></p>
                                                                <span class="text-[7px] sm:text-[8px] font-bold <?= $st['xpPill'] ?> px-1.5 py-0.2 rounded-full"><?= $xpDisplay ?></span>
                                                                <div class="pt-0.5 text-gold text-xs sm:text-base <?= !empty($st['bounceStar']) ? 'animate-bounce drop-shadow-xs' : 'drop-shadow-xs' ?>">
                                                                    <i class="fas fa-star"></i>
                                                                </div>
                                                            </div>
                                                            <div class="w-full <?= $st['heightClass'] ?> rounded-t-xl sm:rounded-t-2xl <?= $st['pillarBg'] ?> shadow-sm group-hover:shadow-md group-hover:-translate-y-1.5 transition-all duration-300 flex flex-col items-center justify-between py-2 px-1 text-white border-t-2 border-white/40 <?= $isSelf ? 'ring-2 ring-gold ring-offset-2' : '' ?>">
                                                                <div class="w-5 h-5 sm:w-6 sm:h-6 rounded-full border-2 border-white bg-black/15 backdrop-blur-xs flex items-center justify-center font-bold text-[9px] sm:text-[10px] text-white shadow-xs mt-0.5">
                                                                    <?= $rankBadge ?>
                                                                </div>
                                                                <div class="space-y-0.5 text-center">
                                                                    <p class="text-[8px] sm:text-[9px] font-bold text-white leading-tight"><?= number_format($xp) ?></p>
                                                                    <span class="text-[7px] sm:text-[8px] font-semibold bg-black/25 text-white px-1 py-0.5 rounded-full inline-flex items-center space-x-0.5">
                                                                        <span><?= (int)($c['trophies'] ?? 0) ?></span>
                                                                        <i class="fas fa-trophy text-[7px] text-amber-300"></i>
                                                                    </span>
                                                                </div>
                                                            </div>
                                                            <div class="pt-1.5 text-center w-full bg-slate-100/90 sm:bg-transparent rounded-b-lg sm:rounded-none">
                                                                <span class="text-[8px] sm:text-[9px] font-extrabold tracking-wider <?= $st['labelColor'] ?> uppercase"><?= htmlspecialchars($displayLabel) ?></span>
                                                                <p class="text-[7px] text-slate-400 font-medium hidden sm:block truncate" title="<?= htmlspecialchars($c['role']) ?>"><?= htmlspecialchars($roleShort) ?></p>
                                                            </div>
                                                        </div>
                                                    <?php endif; endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Personal Standing & Rank Progression Strip (Shows exact rank even if in 45th place!) -->
                                        <div id="employee-personal-standing-card" onclick="openOverviewDrilldown('champions_podium')" class="p-3.5 sm:p-4 rounded-2xl bg-white border border-brand-border shadow-2xs flex flex-col gap-3 transition-all mt-3 cursor-pointer hover:shadow-md hover:border-gold/40 group relative" title="Click to view Leaderboard & Champions Drilldown">
                                            <div class="absolute top-3 right-3 text-slate-300 group-hover:text-gold-dark transition-colors text-[10px] pointer-events-none flex items-center space-x-0.5 font-semibold">
                                                <i class="fas fa-expand text-[9px]"></i>
                                            </div>
                                            <!-- Top Row: Rank Badge + Identity -->
                                            <div class="flex items-center space-x-3 min-w-0 pr-4">
                                                <!-- Prominent Rank Badge -->
                                                <?php $rankBadgeStyle = $inTop5Pulse ? 'bg-linear-to-br from-gold via-amber-400 to-amber-600 text-white' : ($isRankedPulse ? 'bg-slate-900 text-white border-2 border-slate-700' : 'bg-slate-100 text-slate-400 border border-slate-200'); ?>
                                                <div id="emp-standing-rank-badge" class="w-12 h-12 sm:w-14 sm:h-14 rounded-2xl <?= $rankBadgeStyle ?> flex flex-col items-center justify-center font-heading font-black shadow-2xs shrink-0">
                                                    <span class="text-[8px] sm:text-[9px] uppercase tracking-wider <?= $isRankedPulse ? 'opacity-80 text-white' : 'text-slate-400' ?> leading-none"><?= $isRankedPulse ? 'RANK' : 'UNRANKED' ?></span>
                                                    <span id="emp-standing-rank-num" class="text-base sm:text-xl font-bold leading-none mt-0.5 <?= $isRankedPulse ? 'text-white' : 'text-slate-400' ?>"><?= $isRankedPulse ? htmlspecialchars($activeStanding['rank_display']) : '—' ?></span>
                                                </div>
                                                <!-- Name, Role, & Status -->
                                                <div class="space-y-0.5 min-w-0 flex-1">
                                                    <div class="flex items-center space-x-1.5 flex-wrap">
                                                        <h4 id="emp-standing-name" class="font-heading font-bold text-slate-900 text-sm truncate">
                                                            <?= htmlspecialchars($activeStanding['name']) ?>
                                                        </h4>
                                                        <span id="emp-standing-tier-badge" class="badge-gold text-[9px] py-0.2">
                                                            <?= htmlspecialchars($activeStanding['tier']) ?>
                                                        </span>
                                                    </div>
                                                    <p id="emp-standing-role-dept" class="text-[11px] text-slate-500 font-medium truncate">
                                                        <?= htmlspecialchars($activeStanding['role']) ?> · <?= htmlspecialchars($activeStanding['department']) ?>
                                                    </p>
                                                    <div id="emp-standing-place-summary" class="inline-flex items-center space-x-1.5 text-[10px] font-semibold text-slate-600 bg-slate-100/90 px-2 py-0.5 rounded-full border border-slate-200">
                                                        <?php if ($isRankedPulse): ?>
                                                            <i class="fas fa-chart-simple text-slate-400 text-[9px]"></i>
                                                            <span>Currently in <strong><?= htmlspecialchars($activeStanding['place_display']) ?></strong> of <strong><?= (int)$activeStanding['total_associates'] ?> associates</strong></span>
                                                        <?php else: ?>
                                                            <i class="fas fa-info-circle text-slate-400 text-[9px]"></i>
                                                            <span>Not in ranking (0 XP) · Earn XP to rank</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Bottom Row: 3 Live XP Metrics in a balanced grid (No buttons) -->
                                            <div class="grid grid-cols-3 gap-2 w-full pt-1 border-t border-slate-100">
                                                <div class="px-2 py-1.5 rounded-xl bg-brand-canvas border border-brand-border text-center">
                                                    <span class="text-[9px] font-semibold text-slate-400 block uppercase tracking-wider">Total XP</span>
                                                    <span id="emp-standing-xp-val" class="text-sm font-heading font-bold text-gold-dark"><?= number_format((int)$activeStanding['total_xp']) ?></span>
                                                </div>
                                                <div class="px-2 py-1.5 rounded-xl bg-brand-canvas border border-brand-border text-center">
                                                    <span class="text-[9px] font-semibold text-slate-400 block uppercase tracking-wider">Trophies</span>
                                                    <span id="emp-standing-trophies-val" class="text-sm font-heading font-bold text-slate-800"><?= (int)$activeStanding['trophies'] ?> <i class="fas fa-trophy text-[10px] text-amber-500"></i></span>
                                                </div>
                                                <div class="px-2 py-1.5 rounded-xl bg-brand-canvas border border-brand-border text-center">
                                                    <span class="text-[9px] font-semibold text-slate-400 block uppercase tracking-wider">Podium Gap</span>
                                                    <span id="emp-standing-gap-val" class="text-xs font-heading font-bold <?= $inTop5Pulse ? 'text-emerald-700' : ($isRankedPulse ? 'text-terracotta-dark' : 'text-slate-500') ?>">
                                                        <?= $inTop5Pulse ? 'Podium Top 5' : ($isRankedPulse ? ('+' . number_format((int)$activeStanding['xp_to_top_5']) . ' XP') : '+50 XP to rank') ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                </div>

                                <!-- Employee Specific Evaluated Competencies Card -->
                                <div class="card-clean p-6 space-y-4">
                                    <div onclick="openOverviewDrilldown('competencies')" class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-100 pb-3 cursor-pointer group" title="Click to view Competency Standards Details">
                                        <div class="space-y-0.5">
                                            <div class="flex items-center space-x-2">
                                                <h3 class="font-heading font-bold text-base text-slate-900 group-hover:text-dusty-dark transition-colors">
                                                    My Evaluated Competencies &amp; Standards</h3>
                                                <span id="emp-overview-comp-count" class="badge-dusty">11 Assigned (1 Not Rated)</span>
                                                <span class="opacity-0 group-hover:opacity-100 transition-opacity text-[10px] text-dusty-dark font-semibold hidden sm:inline-flex items-center gap-0.5">
                                                    <i class="fas fa-expand text-[9px]"></i>
                                                </span>
                                            </div>
                                            <p class="text-xs text-slate-500">Baseline competency ratings and target benchmarks specifically evaluated for your position.</p>
                                        </div>
                                        <button onclick="event.stopPropagation(); switchPillar('pillar-comp')"
                                            class="px-3.5 py-1.5 bg-brand-canvas hover:bg-slate-100 border border-brand-border text-slate-700 rounded-xl text-xs font-semibold inline-flex items-center space-x-1.5 transition self-start sm:self-auto shadow-2xs">
                                            <i class="fas fa-cubes text-xs text-primary"></i>
                                            <span>View Full Competency Radar</span>
                                        </button>
                                    </div>
                                    <div id="emp-overview-competencies-container" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                                        <!-- Employee specific competencies rendered dynamically -->
                                    </div>
                                </div>

                                <!-- 2-Column: XP Received & Gamification Trajectory + My Shift Sentiment & Well-being (Personal Pulse) -->
                                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                                    <!-- XP Received & Gamification Trajectory -->
                                    <div onclick="openOverviewDrilldown('xp_trajectory')" class="card-clean p-6 space-y-3 cursor-pointer hover:shadow-md hover:border-gold/40 transition-all duration-200 group relative" title="Click to view XP Trajectory & Ledger Drilldown">
                                        <div class="absolute top-4 right-4 text-slate-300 group-hover:text-gold-dark transition-colors text-[10px] pointer-events-none flex items-center space-x-1 font-semibold">
                                            <span>XP History</span>
                                            <i class="fas fa-expand text-[9px]"></i>
                                        </div>
                                        <div class="flex items-center justify-between pr-16">
                                            <div>
                                                <h3 class="font-heading font-bold text-base text-slate-900 group-hover:text-gold-dark transition-colors">
                                                    XP Received &amp; Rewards Trajectory</h3>
                                                <p class="text-xs text-slate-500">Monthly Points &amp; Rewards History Sourced from <code class="text-[10px] bg-slate-100 px-1 py-0.5 rounded text-slate-700">xp_ledger</code></p>
                                            </div>
                                            <span id="xp-trajectory-badge" class="badge-gold">Live xp_ledger</span>
                                        </div>
                                        <div class="h-60 w-full relative">
                                            <canvas id="chart-performance-trend"></canvas>
                                            
                                            <!-- Loading State Indicator -->
                                            <div id="xp-trajectory-loading" class="overview-loading-overlay hidden absolute inset-0 flex-col items-center justify-center bg-white/85 backdrop-blur-2xs rounded-xl p-4 text-center z-10">
                                                <div class="w-8 h-8 rounded-full border-3 border-gold/25 border-t-gold animate-spin mb-2"></div>
                                                <p class="font-bold text-xs text-slate-800">Querying Database...</p>
                                                <p class="text-[10px] text-slate-400">Loading live points from <code>xp_ledger</code></p>
                                            </div>

                                            <!-- Empty State Indicator -->
                                            <div id="xp-trajectory-empty" class="overview-loading-overlay hidden absolute inset-0 flex-col items-center justify-center bg-white/95 rounded-xl p-4 text-center border border-dashed border-slate-200">
                                                <div class="w-10 h-10 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center text-lg mb-2">
                                                    <i class="fas fa-receipt"></i>
                                                </div>
                                                <p class="font-bold text-xs text-slate-800">No XP Records in Database</p>
                                                <p class="text-[11px] text-slate-500 max-w-xs mt-0.5">This associate has no recorded transactions in <code class="text-[10px] bg-slate-100 px-1 py-0.5 rounded text-slate-700">xp_ledger</code> yet.</p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- My Shift Sentiment & Personal Well-being (Individual Pulse) -->
                                    <div class="card-clean p-6 space-y-4 flex flex-col justify-between">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <h3 class="font-heading font-bold text-base text-slate-900">
                                                    My Shift Climate &amp; Well-being</h3>
                                                <p class="text-xs text-slate-500">Your personal shift sentiment and mood log</p>
                                            </div>
                                            <button id="btn-log-checkin-modal" onclick="event.stopPropagation(); openModal('modal-sentiment-pulse')"
                                                class="text-xs font-bold text-primary hover:underline flex items-center space-x-1 transition">
                                                <i class="fas fa-pen text-[10px]"></i>
                                                <span id="btn-log-checkin-text">Log Check-In</span>
                                            </button>
                                        </div>

                                        <?php
                                        // Dynamic database query for the active employee's shift sentiments
                                        $liveEmpShiftsCount = 0;
                                        $liveEmpSmoothCount = 0;
                                        $latestSentiment = null;
                                        try {
                                            if (!isset($pdo)) {
                                                require_once __DIR__ . '/../config/config.php';
                                                $pdo = getSupabaseDb();
                                            }
                                            if ($pdo && !empty($activeEmpId)) {
                                                $stSent = $pdo->prepare("SELECT id, sentiment_score, shift_period, note, created_at FROM public.shift_sentiments WHERE employee_id = :empId ORDER BY created_at DESC");
                                                $stSent->execute([':empId' => $activeEmpId]);
                                                $empSents = $stSent->fetchAll(PDO::FETCH_ASSOC) ?: [];
                                                $liveEmpShiftsCount = count($empSents);
                                                if ($liveEmpShiftsCount > 0) {
                                                    $latestSentiment = $empSents[0];
                                                    foreach ($empSents as $s) {
                                                        if ((int)($s['sentiment_score'] ?? 0) >= 4) $liveEmpSmoothCount++;
                                                    }
                                                }
                                            }
                                        } catch (\Throwable $e) {}
                                        $liveEmpClimatePct = $liveEmpShiftsCount > 0 ? round(($liveEmpSmoothCount / $liveEmpShiftsCount) * 100) : 0;

                                        $sentimentEmoji = '😊';
                                        $sentimentTitle = 'Smooth &amp; Energized';
                                        $sentimentDesc = 'Shift operating on schedule with zero blockers.';
                                        $sentimentTag = "Today's Check-in";
                                        if ($latestSentiment) {
                                            $sc = (int)($latestSentiment['sentiment_score'] ?? 4);
                                            if ($sc >= 4) {
                                                $sentimentEmoji = '😊';
                                                $sentimentTitle = 'Smooth &amp; Energized';
                                            } elseif ($sc === 3) {
                                                $sentimentEmoji = '😐';
                                                $sentimentTitle = 'Manageable &amp; Steady';
                                            } else {
                                                $sentimentEmoji = '😟';
                                                $sentimentTitle = 'Friction Experienced';
                                            }
                                            $sentimentDesc = htmlspecialchars($latestSentiment['note'] ?: ($latestSentiment['shift_period'] ?: 'Shift check-in logged.'));
                                            $sentimentTag = date('M d, Y', strtotime($latestSentiment['created_at'])) . ' Check-in';
                                        } elseif ($liveEmpShiftsCount === 0) {
                                            $sentimentEmoji = '📝';
                                            $sentimentTitle = 'No Check-In Recorded';
                                            $sentimentDesc = 'Log your first shift check-in to record personal operational well-being.';
                                            $sentimentTag = 'Awaiting Check-in';
                                        }
                                        ?>

                                        <!-- Active Personal Status Banner -->
                                        <div id="my-shift-sentiment-banner" onclick="openOverviewDrilldown('shift_sentiment')" class="p-4 rounded-2xl bg-sage-50/70 border border-sage-200/80 flex items-center justify-between gap-3 transition-all cursor-pointer hover:shadow-xs hover:border-sage-300 group" title="Click to view Shift Climate Pulse Details">
                                            <div class="flex items-center space-x-3">
                                                <div id="my-shift-sentiment-emoji" class="w-12 h-12 rounded-2xl bg-sage-dark text-white flex items-center justify-center text-2xl shadow-xs transition-all">
                                                    <?= $sentimentEmoji ?>
                                                </div>
                                                <div>
                                                    <span id="my-shift-sentiment-tag" class="text-[10px] font-bold uppercase tracking-wider text-sage-dark"><?= $sentimentTag ?></span>
                                                    <h4 id="my-shift-sentiment-title" class="font-heading font-bold text-slate-900 text-sm group-hover:text-sage-dark transition-colors"><?= $sentimentTitle ?></h4>
                                                    <p id="my-shift-sentiment-desc" class="text-[11px] text-slate-500"><?= $sentimentDesc ?></p>
                                                </div>
                                            </div>
                                            <div class="flex items-center space-x-2 shrink-0">
                                                <span class="opacity-0 group-hover:opacity-100 transition-opacity text-[10px] text-sage-dark font-semibold hidden sm:inline">Details <i class="fas fa-arrow-right text-[8px]"></i></span>
                                                <span id="my-shift-sentiment-badge" class="<?= $latestSentiment ? 'badge-sage' : 'badge-neutral' ?> shrink-0"><?= $latestSentiment ? 'Logged' : 'Pending' ?></span>
                                            </div>
                                        </div>

                                        <!-- Quick Sentiment Logger Buttons -->
                                        <div class="space-y-1.5">
                                            <div class="flex items-center justify-between">
                                                <span class="text-[11px] font-bold text-slate-600 block">Quick Shift Mood Update:</span>
                                                <span id="my-shift-already-logged-hint" class="hidden text-[10px] font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                                                    <i class="fas fa-check-circle mr-1 text-emerald-600"></i>Logged for Today
                                                </span>
                                            </div>
                                            <div class="grid grid-cols-3 gap-2" id="quick-mood-btn-group">
                                                <button id="quick-mood-btn-smooth" type="button" onclick="event.stopPropagation(); logQuickSentiment('smooth')" class="quick-sentiment-btn p-2.5 rounded-xl border border-sage-200 bg-white hover:bg-sage-50 text-slate-800 flex flex-col items-center justify-center space-y-1 transition group">
                                                    <span class="text-lg group-hover:scale-110 transition-transform">😊</span>
                                                    <span class="text-[11px] font-bold text-sage-dark">Smooth</span>
                                                    <span class="text-[9px] text-slate-400">Clear focus</span>
                                                </button>
                                                <button id="quick-mood-btn-manageable" type="button" onclick="event.stopPropagation(); logQuickSentiment('manageable')" class="quick-sentiment-btn p-2.5 rounded-xl border border-dusty-200 bg-white hover:bg-dusty-50 text-slate-800 flex flex-col items-center justify-center space-y-1 transition group">
                                                    <span class="text-lg group-hover:scale-110 transition-transform">😐</span>
                                                    <span class="text-[11px] font-bold text-dusty-dark">Manageable</span>
                                                    <span class="text-[9px] text-slate-400">Steady load</span>
                                                </button>
                                                <button id="quick-mood-btn-friction" type="button" onclick="event.stopPropagation(); logQuickSentiment('friction')" class="quick-sentiment-btn p-2.5 rounded-xl border border-terracotta-200 bg-white hover:bg-terracotta-50 text-slate-800 flex flex-col items-center justify-center space-y-1 transition group">
                                                    <span class="text-lg group-hover:scale-110 transition-transform">😟</span>
                                                    <span class="text-[11px] font-bold text-terracotta-dark">Friction</span>
                                                    <span class="text-[9px] text-slate-400">Need support</span>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- 7-Day Personal Consistency Track -->
                                        <div onclick="openOverviewDrilldown('shift_sentiment')" class="pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-500 cursor-pointer hover:text-slate-900 transition-colors">
                                            <span>Weekly Streak: <strong id="emp-pulse-shifts-count" class="text-slate-900"><?= $liveEmpShiftsCount ?> <?= $liveEmpShiftsCount === 1 ? 'Shift' : 'Shifts' ?> Logged</strong></span>
                                            <span id="emp-pulse-climate-tag" class="text-sage-dark font-semibold"><i class="fas fa-shield-heart mr-1"></i><?= $liveEmpClimatePct ?>% Positive Climate <i class="fas fa-chevron-right text-[8px] ml-1"></i></span>
                                        </div>
                                    </div>

                                </div>

                            </div>

                            <!-- SUB-TAB 2: SYSTEM & PROPERTY ANALYTICS (Organization-Wide Overview) -->
                            <div id="sub-dashboard-system" class="sub-panel-dashboard <?= $systemTabClass ?> space-y-6 relative">

                                <!-- Sub-Tab 2 Loading Shimmer & State -->
                                <div id="overview-tab2-loading" class="overview-loading-overlay hidden absolute inset-0 z-30 bg-white/80 backdrop-blur-2xs rounded-3xl flex-col items-center justify-center space-y-3 transition-opacity duration-300">
                                    <div class="relative flex items-center justify-center">
                                        <div class="w-12 h-12 rounded-full border-3 border-primary/20 border-t-primary animate-spin"></div>
                                        <div class="absolute w-6 h-6 rounded-full bg-primary/10 flex items-center justify-center text-primary text-xs">
                                            <i class="fas fa-chart-line text-[10px]"></i>
                                        </div>
                                    </div>
                                    <div class="text-center space-y-0.5">
                                        <p class="font-bold text-xs text-slate-800 tracking-wide">Syncing Property Telemetry...</p>
                                        <p class="text-[10px] text-slate-400">Loading live KPIs, Execution Matrix &amp; XP Champions</p>
                                    </div>
                                </div>

                                <!-- System Overview Banner -->
                                <div
                                    class="card-clean p-6 bg-white border border-brand-border flex flex-col md:flex-row md:items-center justify-between gap-4">
                                    <div class="space-y-1">
                                        <div class="flex items-center space-x-2">
                                            <span class="w-2.5 h-2.5 rounded-full bg-sage"></span>
                                            <span
                                                class="text-xs font-bold text-slate-900 uppercase tracking-wide">Property-Wide HR Operating Metrics</span>
                                            <span class="badge-neutral">All 100 Associates</span>
                                        </div>
                                        <h2 class="text-xl sm:text-2xl font-heading font-bold text-slate-900">
                                            Workforce Health &amp; Execution Velocity</h2>
                                        <p class="text-xs text-slate-500">Telemetry across all 5 departments: goal approvals, LMS certification, and succession pipeline readiness.</p>
                                    </div>
                                    <div class="flex items-center space-x-2 self-start md:self-auto shrink-0">
                                        <button
                                            onclick="openExportSummaryModal()"
                                            class="btn-primary px-4 py-2 text-xs font-bold flex items-center space-x-2 shadow-xs hover:shadow-md transition">
                                            <i class="fas fa-file-export text-xs"></i>
                                            <span>Export Summary</span>
                                        </button>
                                    </div>
                                </div>

                                <?php
                                // Dynamic calculations for System KPIs and Department Execution Matrix from Database
                                $livePropertyXp = 0;
                                $liveKudosSent = 0;
                                $liveBadgesCount = 0;
                                $liveActiveStaffCount = 0;

                                $liveTotalGoals = 0;
                                $liveApprovedGoals = 0;
                                $liveReviewGoals = 0;
                                $liveReviseGoals = 0;
                                $liveGoalsApprovalRate = 0.0;

                                $liveTotalPrescribed = 0;
                                $livePassedPrescribed = 0;
                                $liveLmsAvgScore = 0.0;
                                $liveLmsRate = 0.0;

                                $totalRolesCount = 0;
                                $coveredRolesCount = 0;
                                $fastTrackCount = 0;
                                $liveBenchDepthPct = 0.0;

                                $canonicalDepts = [
                                    'Front Office',
                                    'Food & Beverage',
                                    'Kitchen & Culinary',
                                    'Banquet & Events',
                                    'Housekeeping'
                                ];

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

                                $cacheFile = __DIR__ . '/../cache/overview_metrics.json';
                                $cached = null;

                                if (file_exists($cacheFile)) {
                                    $cached = @json_decode(file_get_contents($cacheFile), true);
                                }

                                if ($cached && is_array($cached) && isset($cached['liveActiveStaffCount'])) {
                                    $livePropertyXp        = (int)($cached['livePropertyXp'] ?? 0);
                                    $liveKudosSent         = (int)($cached['liveKudosSent'] ?? 0);
                                    $liveBadgesCount       = (int)($cached['liveBadgesCount'] ?? 0);
                                    $liveActiveStaffCount  = (int)($cached['liveActiveStaffCount'] ?? 0);

                                    $liveTotalGoals        = (int)($cached['liveTotalGoals'] ?? 0);
                                    $liveApprovedGoals     = (int)($cached['liveApprovedGoals'] ?? 0);
                                    $liveReviewGoals       = (int)($cached['liveReviewGoals'] ?? 0);
                                    $liveReviseGoals       = (int)($cached['liveReviseGoals'] ?? 0);
                                    $liveGoalsApprovalRate = (float)($cached['liveGoalsApprovalRate'] ?? 0.0);

                                    $liveTotalPrescribed   = (int)($cached['liveTotalPrescribed'] ?? 0);
                                    $livePassedPrescribed  = (int)($cached['livePassedPrescribed'] ?? 0);
                                    $liveLmsAvgScore       = (float)($cached['liveLmsAvgScore'] ?? 0.0);
                                    $liveLmsRate           = (float)($cached['liveLmsRate'] ?? 0.0);

                                    $totalRolesCount       = (int)($cached['totalRolesCount'] ?? 0);
                                    $coveredRolesCount     = (int)($cached['coveredRolesCount'] ?? 0);
                                    $fastTrackCount        = (int)($cached['fastTrackCount'] ?? 0);
                                    $liveBenchDepthPct     = (float)($cached['liveBenchDepthPct'] ?? 0.0);

                                    if (!empty($cached['deptBuckets']) && is_array($cached['deptBuckets'])) {
                                        $deptBuckets = $cached['deptBuckets'];
                                    }
                                } else {
                                    try {
                                        $pdoOverview = getSupabaseDb();
                                        if ($pdoOverview) {
                                            // 1. Total XP & Badges from unified xp_ledger
                                            $xpStmt = $pdoOverview->query("SELECT COALESCE(SUM(points), 0) AS total_xp, COUNT(*) FILTER (WHERE source_type IN ('peer_kudos', 'supervisor_kudos', 'training_cert', 'lms_quiz')) AS badge_cnt FROM public.xp_ledger");
                                            $xpRow = $xpStmt ? $xpStmt->fetch(PDO::FETCH_ASSOC) : null;
                                            if ($xpRow) {
                                                $livePropertyXp = (int)($xpRow['total_xp'] ?? 0);
                                                $liveBadgesCount = (int)($xpRow['badge_cnt'] ?? 0);
                                            }
                                            if ($livePropertyXp === 0) {
                                                try {
                                                    require_once __DIR__ . '/../models/SocialModel.php';
                                                    $smOverview = new SocialModel();
                                                    $allLg = $smOverview->getLedger(null);
                                                    foreach ($allLg as $alg) {
                                                        $livePropertyXp += (int)($alg['points'] ?? ($alg['amount'] ?? 0));
                                                    }
                                                } catch (Throwable $e) {}
                                            }

                                            // 2. Kudos count
                                            $kudosStmt = $pdoOverview->query("SELECT COUNT(*) AS kudos_cnt FROM public.social_recognitions");
                                            $kudosRow = $kudosStmt ? $kudosStmt->fetch(PDO::FETCH_ASSOC) : null;
                                            if ($kudosRow) $liveKudosSent = (int)$kudosRow['kudos_cnt'];

                                            // 3. Succession positions & candidate readiness
                                            $posStmt = $pdoOverview->query("SELECT COUNT(*) AS total_roles, COUNT(*) FILTER (WHERE primary_successor_id IS NOT NULL AND primary_successor_id != '') AS covered_roles FROM public.succession_positions");
                                            $posRow = $posStmt ? $posStmt->fetch(PDO::FETCH_ASSOC) : null;
                                            if ($posRow) {
                                                $totalRolesCount = (int)($posRow['total_roles'] ?? 0);
                                                $coveredRolesCount = (int)($posRow['covered_roles'] ?? 0);
                                            }
                                            $liveBenchDepthPct = $totalRolesCount > 0 ? round(($coveredRolesCount / $totalRolesCount) * 100, 1) : 0.0;

                                            $candStmt = $pdoOverview->query("SELECT COUNT(*) AS total_cands, COUNT(*) FILTER (WHERE LOWER(hr_readiness_flag::text) LIKE '%ready now%') AS fast_track FROM public.succession_candidates");
                                            $candRow = $candStmt ? $candStmt->fetch(PDO::FETCH_ASSOC) : null;
                                            if ($candRow) {
                                                $fastTrackCount = (int)($candRow['fast_track'] ?? 0);
                                            }

                                            // 4. Departments & Employees
                                            $deptsStmt = $pdoOverview->query("SELECT id, name FROM public.departments ORDER BY name");
                                            $allDepts = $deptsStmt ? $deptsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
                                            $deptIdMap = [];
                                            foreach ($allDepts as $d) {
                                                if (!empty($d['id']) && !empty($d['name'])) $deptIdMap[$d['id']] = $d['name'];
                                            }

                                            $empsStmt = $pdoOverview->query("SELECT id, full_name, department_id, title, status FROM public.employees");
                                            $allEmps = $empsStmt ? $empsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
                                            $liveActiveStaffCount = count($allEmps);

                                            // 5. Goals summary & buckets
                                            $allGoals = $pdoOverview->query("SELECT id, employee_id, department, status::text AS status, weight FROM public.performance_goals")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                                            $liveTotalGoals = count($allGoals);
                                            foreach ($allGoals as $g) {
                                                $st = strtolower(trim((string)($g['status'] ?? '')));
                                                if (in_array($st, ['approved', 'done', 'completed', 'active', 'endorsed', 'calibrated'])) {
                                                    $liveApprovedGoals++;
                                                } elseif (in_array($st, ['pending', 'pending approval', 'in review', 'submitted'])) {
                                                    $liveReviewGoals++;
                                                } elseif (in_array($st, ['needs revision', 'revise', 'revision', 'rejected'])) {
                                                    $liveReviseGoals++;
                                                }
                                            }
                                            $liveGoalsApprovalRate = $liveTotalGoals > 0 ? round(($liveApprovedGoals / $liveTotalGoals) * 100, 1) : 0.0;

                                            // 6. LMS summary & buckets
                                            $allLms = $pdoOverview->query("SELECT id, employee, status::text AS status, progress FROM public.lms_prescribed")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                                            $liveTotalPrescribed = count($allLms);
                                            $lmsProgSum = 0;
                                            foreach ($allLms as $l) {
                                                $st = strtolower(trim((string)($l['status'] ?? '')));
                                                $prog = (float)($l['progress'] ?? 0);
                                                $lmsProgSum += $prog;
                                                if (in_array($st, ['passed', 'completed']) || $prog >= 80) {
                                                    $livePassedPrescribed++;
                                                }
                                            }
                                            $liveLmsAvgScore = $liveTotalPrescribed > 0 ? round($lmsProgSum / $liveTotalPrescribed, 1) : 0.0;
                                            $liveLmsRate = $liveTotalPrescribed > 0 ? round(($livePassedPrescribed / $liveTotalPrescribed) * 100, 1) : 0.0;

                                            // 7. Succession candidate mapping
                                            $allSucc = $pdoOverview->query("SELECT sc.id, sc.employee_id, sc.position_id, sc.hr_readiness_flag::text AS hr_readiness_flag, sp.dept as pos_dept 
                                                FROM public.succession_candidates sc 
                                                LEFT JOIN public.succession_positions sp ON sc.position_id = sp.id")->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
                                                if (!isset($deptBuckets[$gDept])) $gDept = 'Front Office';
                                                $deptBuckets[$gDept]['goals_total']++;
                                                $st = strtolower(trim((string)($g['status'] ?? '')));
                                                if (in_array($st, ['approved', 'done', 'completed', 'active', 'endorsed', 'calibrated'])) {
                                                    $deptBuckets[$gDept]['goals_approved']++;
                                                }
                                            }

                                            foreach ($allLms as $l) {
                                                $eId = $l['employee'] ?? '';
                                                $d = $empDeptMap[$eId] ?? 'Front Office';
                                                if (!isset($deptBuckets[$d])) $d = 'Front Office';
                                                $deptBuckets[$d]['lms_total']++;
                                                $prog = (float)($l['progress'] ?? 0);
                                                $deptBuckets[$d]['lms_progress_sum'] += $prog;
                                            }

                                            foreach ($allSucc as $s) {
                                                $d = '';
                                                if (!empty($s['pos_dept'])) {
                                                    $d = $normalizeDept($s['pos_dept']);
                                                } elseif (!empty($s['employee_id'])) {
                                                    $d = $empDeptMap[$s['employee_id']] ?? 'Front Office';
                                                }
                                                if (empty($d) || !isset($deptBuckets[$d])) $d = 'Front Office';
                                                $deptBuckets[$d]['succ_candidates']++;
                                                $flag = strtolower(trim((string)($s['hr_readiness_flag'] ?? '')));
                                                if (strpos($flag, 'ready now') !== false || strpos($flag, 'ready in') !== false) {
                                                    $deptBuckets[$d]['succ_ready']++;
                                                }
                                            }

                                            // Fetch top 5 champions once for cache
                                            require_once __DIR__ . '/../models/SocialModel.php';
                                            $socialModelOverview = new SocialModel();
                                            $overviewChampions = $socialModelOverview->getTop5XpChampions();

                                            // Atomically persist to disk cache for instantaneous subsequent page loads
                                            if (!is_dir(dirname($cacheFile))) @mkdir(dirname($cacheFile), 0777, true);
                                            @file_put_contents($cacheFile, json_encode([
                                                'livePropertyXp'        => $livePropertyXp,
                                                'liveKudosSent'         => $liveKudosSent,
                                                'liveBadgesCount'       => $liveBadgesCount,
                                                'liveActiveStaffCount'  => $liveActiveStaffCount,
                                                'liveTotalGoals'        => $liveTotalGoals,
                                                'liveApprovedGoals'     => $liveApprovedGoals,
                                                'liveReviewGoals'       => $liveReviewGoals,
                                                'liveReviseGoals'       => $liveReviseGoals,
                                                'liveGoalsApprovalRate' => $liveGoalsApprovalRate,
                                                'liveTotalPrescribed'   => $liveTotalPrescribed,
                                                'livePassedPrescribed'  => $livePassedPrescribed,
                                                'liveLmsAvgScore'       => $liveLmsAvgScore,
                                                'liveLmsRate'           => $liveLmsRate,
                                                'totalRolesCount'       => $totalRolesCount,
                                                'coveredRolesCount'     => $coveredRolesCount,
                                                'fastTrackCount'        => $fastTrackCount,
                                                'liveBenchDepthPct'     => $liveBenchDepthPct,
                                                'deptBuckets'           => $deptBuckets,
                                                'overviewChampions'     => $overviewChampions,
                                                'cached_at'             => time()
                                            ], JSON_PRETTY_PRINT));
                                        }
                                    } catch (Throwable $e) {
                                        // On database timeout or error, smoothly fallback to stale cache file if available
                                        if (file_exists($cacheFile)) {
                                            $fb = @json_decode(file_get_contents($cacheFile), true);
                                            if ($fb && is_array($fb)) {
                                                $livePropertyXp        = (int)($fb['livePropertyXp'] ?? 0);
                                                $liveKudosSent         = (int)($fb['liveKudosSent'] ?? 0);
                                                $liveBadgesCount       = (int)($fb['liveBadgesCount'] ?? 0);
                                                $liveActiveStaffCount  = (int)($fb['liveActiveStaffCount'] ?? 0);
                                                $liveTotalGoals        = (int)($fb['liveTotalGoals'] ?? 0);
                                                $liveApprovedGoals     = (int)($fb['liveApprovedGoals'] ?? 0);
                                                $liveReviewGoals       = (int)($fb['liveReviewGoals'] ?? 0);
                                                $liveReviseGoals       = (int)($fb['liveReviseGoals'] ?? 0);
                                                $liveGoalsApprovalRate = (float)($fb['liveGoalsApprovalRate'] ?? 0.0);
                                                $liveTotalPrescribed   = (int)($fb['liveTotalPrescribed'] ?? 0);
                                                $livePassedPrescribed  = (int)($fb['livePassedPrescribed'] ?? 0);
                                                $liveLmsAvgScore       = (float)($fb['liveLmsAvgScore'] ?? 0.0);
                                                $liveLmsRate           = (float)($fb['liveLmsRate'] ?? 0.0);
                                                $totalRolesCount       = (int)($fb['totalRolesCount'] ?? 0);
                                                $coveredRolesCount     = (int)($fb['coveredRolesCount'] ?? 0);
                                                $fastTrackCount        = (int)($fb['fastTrackCount'] ?? 0);
                                                $liveBenchDepthPct     = (float)($fb['liveBenchDepthPct'] ?? 0.0);
                                                if (!empty($fb['deptBuckets'])) $deptBuckets = $fb['deptBuckets'];
                                            }
                                        }
                                    }
                                }

                                // Grade & bar calculation
                                if ($livePropertyXp >= 10000) $liveXpGrade = 'Grade A+';
                                elseif ($livePropertyXp >= 5000) $liveXpGrade = 'Grade A';
                                elseif ($livePropertyXp >= 2000) $liveXpGrade = 'Grade B+';
                                elseif ($livePropertyXp > 0) $liveXpGrade = 'Grade B';
                                else $liveXpGrade = 'Grade C';

                                $xpBarPct = min(100, max(0, round(($livePropertyXp / 3000) * 100)));

                                if ($liveBenchDepthPct >= 75) {
                                    $benchRisk = 'Low Risk';
                                    $benchRiskClass = 'text-sage-dark';
                                    $benchBadgeClass = 'badge-dusty';
                                } elseif ($liveBenchDepthPct >= 50) {
                                    $benchRisk = 'Moderate Risk';
                                    $benchRiskClass = 'text-gold-dark';
                                    $benchBadgeClass = 'badge-gold';
                                } elseif ($liveBenchDepthPct > 0) {
                                    $benchRisk = 'Elevated Risk';
                                    $benchRiskClass = 'text-rose-600';
                                    $benchBadgeClass = 'badge-terracotta';
                                } else {
                                    $benchRisk = 'Pipeline Empty';
                                    $benchRiskClass = 'text-slate-400';
                                    $benchBadgeClass = 'bg-slate-100 text-slate-500 border border-slate-200 text-[10px] font-semibold px-2 py-0.5 rounded-full';
                                }

                                $deptMatrixRows = [];
                                foreach ($canonicalDepts as $cDept) {
                                    $b = $deptBuckets[$cDept];
                                    $goalsPct = $b['goals_total'] > 0 ? round(($b['goals_approved'] / $b['goals_total']) * 100, 1) : 0.0;
                                    $lmsPct = $b['lms_total'] > 0 ? round($b['lms_progress_sum'] / $b['lms_total'], 1) : 0.0;
                                    $succPct = $b['succ_candidates'] > 0 ? round(($b['succ_ready'] / $b['succ_candidates']) * 100, 1) : 0.0;
                                    $composite = round(($goalsPct * 0.35) + ($lmsPct * 0.35) + ($succPct * 0.30), 1);

                                    if ($composite >= 80) {
                                        $status = 'Optimal';
                                        $badgeClass = 'badge-sage';
                                    } elseif ($composite >= 50) {
                                        $status = 'Good';
                                        $badgeClass = 'badge-dusty';
                                    } elseif ($composite > 0 || $b['staff_count'] > 0) {
                                        $status = 'Developing';
                                        $badgeClass = 'badge-terracotta';
                                    } else {
                                        $status = 'Pending';
                                        $badgeClass = 'bg-slate-100 text-slate-500 border border-slate-200 text-[10px] font-semibold px-2 py-0.5 rounded-full';
                                    }

                                    $deptMatrixRows[] = [
                                        'department'           => $cDept,
                                        'staff_count'          => $b['staff_count'],
                                        'goals_approved_pct'   => $goalsPct,
                                        'lms_rate_pct'         => $lmsPct,
                                        'succession_ready_pct' => $succPct,
                                        'composite_score'      => $composite,
                                        'status'               => $status,
                                        'badge_class'          => $badgeClass
                                    ];
                                }
                                ?>

                                <!-- 4 Master System-Wide KPI Cards -->
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

                                    <!-- System KPI 1: Approved Goals Count (100% Dynamic from performance_goals) -->
                                    <div onclick="openOverviewDrilldown('sys_goals')" class="card-clean p-5 space-y-3 cursor-pointer hover:border-sage hover:shadow-md transition-all group" title="Click to view all property goals & approval status">
                                        <div
                                            class="flex justify-between items-center text-xs text-slate-500 font-medium">
                                            <span class="flex items-center space-x-1.5">
                                                <span>Goal Approval Rate</span>
                                                <i class="fas fa-arrow-up-right-from-square text-[9px] text-slate-400 group-hover:text-sage-dark transition-colors"></i>
                                            </span>
                                            <span class="<?= $liveGoalsApprovalRate >= 80 ? 'badge-sage' : ($liveGoalsApprovalRate > 0 ? 'badge-dusty' : 'bg-slate-100 text-slate-500 border border-slate-200 text-[10px] font-semibold px-2 py-0.5 rounded-full') ?>" id="sys-kpi-goals-rate-badge"><?= $liveGoalsApprovalRate ?>% Approved</span>
                                        </div>
                                        <div class="flex items-baseline space-x-2">
                                            <span class="text-3xl font-heading font-bold text-slate-900 group-hover:text-sage-dark transition-colors" id="sys-kpi-goals-ratio"><?= $liveApprovedGoals ?>
                                                <span class="text-sm font-normal text-slate-400">/ <?= $liveTotalGoals ?></span></span>
                                            <span class="text-xs text-slate-400 font-medium" id="sys-kpi-goals-subtext"><?= $liveTotalGoals > 0 ? 'Live Database' : 'No Goals Set' ?></span>
                                        </div>
                                        <div class="w-full bg-brand-canvas h-1.5 rounded-full overflow-hidden border border-brand-border/50">
                                            <div class="bg-sage h-1.5 rounded-full transition-all duration-500" id="sys-kpi-goals-bar" style="width: <?= $liveGoalsApprovalRate ?>%">
                                            </div>
                                        </div>
                                        <div class="flex justify-between items-center text-[11px] text-slate-500" id="sys-kpi-goals-breakdown">
                                            <span><?= $liveApprovedGoals ?> Approved</span>
                                            <span class="text-gold-dark font-medium"><?= $liveReviewGoals ?> In Review</span>
                                            <span class="text-slate-400"><?= $liveReviseGoals ?> Revise</span>
                                        </div>
                                    </div>

                                    <!-- System KPI 2: Total Gamified XP (100% Dynamic from xp_ledger) -->
                                    <div onclick="openOverviewDrilldown('xp_ledger')" class="card-clean p-5 space-y-3 cursor-pointer hover:border-gold hover:shadow-md transition-all group" title="Click to inspect property XP transactions & recognition ledger">
                                        <div
                                            class="flex justify-between items-center text-xs text-slate-500 font-medium">
                                            <span class="flex items-center space-x-1.5">
                                                <span>Total Property XP</span>
                                                <i class="fas fa-arrow-up-right-from-square text-[9px] text-slate-400 group-hover:text-gold-dark transition-colors"></i>
                                            </span>
                                            <span class="badge-gold" id="sys-kpi-property-xp-grade"><?= htmlspecialchars($liveXpGrade) ?></span>
                                        </div>
                                        <div class="flex items-baseline space-x-2">
                                            <span class="text-3xl font-heading font-bold text-gold-dark group-hover:scale-[1.02] transition-transform" id="sys-kpi-property-xp-val"><?= number_format($livePropertyXp) ?>
                                                <span class="text-xs font-normal text-slate-400">XP</span></span>
                                            <span class="text-xs text-slate-500 font-medium" id="sys-kpi-property-xp-staff"><?= $liveActiveStaffCount ?> Staff</span>
                                        </div>
                                        <div class="w-full bg-brand-canvas h-1.5 rounded-full overflow-hidden border border-brand-border/50">
                                            <div class="bg-gold h-1.5 rounded-full transition-all duration-500" id="sys-kpi-property-xp-bar" style="width: <?= $xpBarPct ?>%">
                                            </div>
                                        </div>
                                        <div class="flex justify-between items-center text-[11px] text-slate-500">
                                            <span id="sys-kpi-property-xp-kudos"><?= number_format($liveKudosSent) ?> Kudos Sent</span>
                                            <span class="text-gold-dark font-medium" id="sys-kpi-property-xp-badges"><?= number_format($liveBadgesCount) ?> Badges</span>
                                        </div>
                                    </div>

                                    <!-- System KPI 3: Average LMS Completion Rate (100% Dynamic from lms_prescribed) -->
                                    <div onclick="openOverviewDrilldown('sys_lms')" class="card-clean p-5 space-y-3 cursor-pointer hover:border-primary hover:shadow-md transition-all group" title="Click to view full hotel LMS course catalog & completion stats">
                                        <div
                                            class="flex justify-between items-center text-xs text-slate-500 font-medium">
                                            <span class="flex items-center space-x-1.5">
                                                <span>LMS Course Completion</span>
                                                <i class="fas fa-arrow-up-right-from-square text-[9px] text-slate-400 group-hover:text-primary transition-colors"></i>
                                            </span>
                                            <span class="<?= $liveLmsRate >= 80 ? 'badge-primary' : ($liveLmsRate > 0 ? 'badge-dusty' : 'bg-slate-100 text-slate-500 border border-slate-200 text-[10px] font-semibold px-2 py-0.5 rounded-full') ?>" id="sys-kpi-lms-rate-badge"><?= $liveLmsRate ?>% Rate</span>
                                        </div>
                                        <div class="flex items-baseline space-x-2">
                                            <span class="text-3xl font-heading font-bold text-slate-900 group-hover:text-primary transition-colors" id="sys-kpi-lms-rate-val"><?= $liveLmsRate ?>%</span>
                                            <span class="text-xs text-slate-400" id="sys-kpi-lms-target"><?= $liveTotalPrescribed > 0 ? 'Target: 80.0%' : 'No Courses' ?></span>
                                        </div>
                                        <div class="w-full bg-brand-canvas h-1.5 rounded-full overflow-hidden border border-brand-border/50">
                                            <div class="bg-primary h-1.5 rounded-full transition-all duration-500" id="sys-kpi-lms-bar" style="width: <?= $liveLmsRate ?>%">
                                            </div>
                                        </div>
                                        <div class="flex justify-between items-center text-[11px] text-slate-500" id="sys-kpi-lms-breakdown">
                                            <span><?= $livePassedPrescribed ?> / <?= $liveTotalPrescribed ?> Modules</span>
                                            <span class="text-sage-dark font-medium"><?= $liveLmsAvgScore ?>% Avg Score</span>
                                        </div>
                                    </div>

                                    <!-- System KPI 4: Succession Pipeline Health Rate (100% Dynamic from succession tables) -->
                                    <div onclick="openOverviewDrilldown('sys_succession')" class="card-clean p-5 space-y-3 cursor-pointer hover:border-dusty hover:shadow-md transition-all group" title="Click to view leadership succession coverage & bench depth">
                                        <div
                                            class="flex justify-between items-center text-xs text-slate-500 font-medium">
                                            <span class="flex items-center space-x-1.5">
                                                <span>Succession Bench Depth</span>
                                                <i class="fas fa-arrow-up-right-from-square text-[9px] text-slate-400 group-hover:text-dusty-dark transition-colors"></i>
                                            </span>
                                            <span class="<?= $benchBadgeClass ?>" id="sys-kpi-succession-badge"><?= $liveBenchDepthPct ?>% Ready</span>
                                        </div>
                                        <div class="flex items-baseline space-x-2">
                                            <span class="text-3xl font-heading font-bold text-slate-900 group-hover:text-dusty-dark transition-colors" id="sys-kpi-succession-val"><?= $liveBenchDepthPct ?>%</span>
                                            <span class="text-xs <?= $benchRiskClass ?> font-semibold" id="sys-kpi-succession-risk"><?= $benchRisk ?></span>
                                        </div>
                                        <div class="w-full bg-brand-canvas h-1.5 rounded-full overflow-hidden border border-brand-border/50">
                                            <div class="bg-dusty h-1.5 rounded-full transition-all duration-500" id="sys-kpi-succession-bar" style="width: <?= $liveBenchDepthPct ?>%">
                                            </div>
                                        </div>
                                        <div class="flex justify-between items-center text-[11px] text-slate-500">
                                            <span id="sys-kpi-succession-roles"><?= $coveredRolesCount ?> / <?= max(1, $totalRolesCount) ?> Key Roles Covered</span>
                                            <span class="text-slate-400" id="sys-kpi-succession-fasttrack"><?= $fastTrackCount ?> In Fast-Track</span>
                                        </div>
                                    </div>

                                </div>

                                <!-- 6 Fast Core Module Navigation Cards (Property-Wide Architecture) -->
                                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                                    <div onclick="switchPillar('pillar-perf')"
                                        class="card-clean p-4 cursor-pointer hover:border-primary transition group">
                                        <i class="fas fa-bullseye text-primary text-xl mb-2 group-hover:scale-105 transition-transform"></i>
                                        <p class="font-bold text-xs text-slate-900">1. Performance</p>
                                        <p class="text-[10px] text-slate-500">7-Step Cycle</p>
                                    </div>
                                    <div onclick="switchPillar('pillar-comp')"
                                        class="card-clean p-4 cursor-pointer hover:border-primary transition group">
                                        <i class="fas fa-cubes text-dusty-dark text-xl mb-2 group-hover:scale-105 transition-transform"></i>
                                        <p class="font-bold text-xs text-slate-900">2. Competency</p>
                                        <p class="text-[10px] text-slate-500">Radar &amp; Gaps</p>
                                    </div>
                                    <div onclick="switchPillar('pillar-lms')"
                                        class="card-clean p-4 cursor-pointer hover:border-primary transition group">
                                        <i class="fas fa-graduation-cap text-sage-dark text-xl mb-2 group-hover:scale-105 transition-transform"></i>
                                        <p class="font-bold text-xs text-slate-900">3. Learning LMS</p>
                                        <p class="text-[10px] text-slate-500">TNA &amp; Quizzes</p>
                                    </div>
                                    <div onclick="switchPillar('pillar-training')"
                                        class="card-clean p-4 cursor-pointer hover:border-primary transition group">
                                        <i class="fas fa-chalkboard-user text-terracotta text-xl mb-2 group-hover:scale-105 transition-transform"></i>
                                        <p class="font-bold text-xs text-slate-900">4. Training Ops</p>
                                        <p class="text-[10px] text-slate-500">12 Functions</p>
                                    </div>
                                    <div onclick="switchPillar('pillar-succession')"
                                        class="card-clean p-4 cursor-pointer hover:border-primary transition group">
                                        <i class="fas fa-sitemap text-dusty-dark text-xl mb-2 group-hover:scale-105 transition-transform"></i>
                                        <p class="font-bold text-xs text-slate-900">5. Succession</p>
                                        <p class="text-[10px] text-slate-500">9-Box Bench</p>
                                    </div>
                                    <div onclick="switchPillar('pillar-social')"
                                        class="card-clean p-4 cursor-pointer hover:border-primary transition group">
                                        <i class="fas fa-trophy text-gold text-xl mb-2 group-hover:scale-105 transition-transform"></i>
                                        <p class="font-bold text-xs text-slate-900">6. Kudos &amp; XP</p>
                                        <p class="text-[10px] text-slate-500">Social Climate</p>
                                    </div>
                                </div>

                                <!-- Row 1: Top 5 Gamified XP Champions + Shift Climate Pulse -->
                                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

                                    <!-- Column 1: Top 5 Highest Gamified XP Staff Leaderboard (5 cols) -->
                                    <div class="lg:col-span-5 card-clean p-6 space-y-4">
                                        <div class="flex items-center justify-between cursor-pointer group" onclick="openOverviewDrilldown('champions_podium')" title="Click for XP podium leaderboard telemetry">
                                            <div>
                                                <h3 class="font-heading font-bold text-base text-slate-900 group-hover:text-gold-dark transition-colors flex items-center space-x-1.5">
                                                    <span>Top 5 Gamified XP Champions</span>
                                                    <i class="fas fa-arrow-up-right-from-square text-[10px] text-slate-400 group-hover:text-gold-dark transition-colors"></i>
                                                </h3>
                                                <p class="text-xs text-slate-500">Highest accumulated recognition points &amp; badges</p>
                                            </div>
                                            <span class="badge-gold">Property Top 5</span>
                                        </div>

                                        <!-- Top 5 Vertical Bar Podium (Names & Stars on Top - Clean Solid Palette) -->
                                        <div onclick="openOverviewDrilldown('champions_podium')" class="bg-brand-canvas border border-brand-border rounded-2xl p-3.5 sm:p-5 cursor-pointer hover:border-gold/60 transition-all group" title="Click to view full podium standings & recognition feed">
                                            <div class="relative pt-2">
                                                <!-- Connecting Horizontal Bar behind pillars -->
                                                <div
                                                    class="absolute bottom-11 left-0 right-0 h-2.5 bg-brand-border rounded-full z-0 hidden sm:block">
                                                </div>

                                                <div id="overview-top5-podium" class="grid grid-cols-5 gap-2 sm:gap-3.5 items-end relative z-10">
                                                <?php
                                                $rankStylesPhp = [
                                                    1 => ['avatarBg' => 'bg-gold', 'xpPill' => 'text-gold-dark bg-gold-50 border border-gold-100', 'pillarBg' => 'bg-gold', 'heightClass' => 'h-44 sm:h-52', 'labelColor' => 'text-gold-dark', 'bounceStar' => true],
                                                    2 => ['avatarBg' => 'bg-terracotta', 'xpPill' => 'text-terracotta-dark bg-terracotta-50 border border-terracotta-100', 'pillarBg' => 'bg-terracotta', 'heightClass' => 'h-36 sm:h-44', 'labelColor' => 'text-terracotta', 'bounceStar' => false],
                                                    3 => ['avatarBg' => 'bg-sage-dark', 'xpPill' => 'text-sage-dark bg-sage-50 border border-sage-100', 'pillarBg' => 'bg-sage-dark', 'heightClass' => 'h-28 sm:h-36', 'labelColor' => 'text-sage-dark', 'bounceStar' => false],
                                                    4 => ['avatarBg' => 'bg-dusty', 'xpPill' => 'text-dusty-dark bg-dusty-50 border border-dusty-100', 'pillarBg' => 'bg-dusty', 'heightClass' => 'h-22 sm:h-28', 'labelColor' => 'text-dusty', 'bounceStar' => false],
                                                    5 => ['avatarBg' => 'bg-[#6F6261]', 'xpPill' => 'text-slate-700 bg-slate-100 border border-slate-200', 'pillarBg' => 'bg-[#6F6261]', 'heightClass' => 'h-16 sm:h-22', 'labelColor' => 'text-slate-600', 'bounceStar' => false],
                                                ];

                                                foreach ($overviewChampions as $c):
                                                    $xp = (int)($c['total_xp'] ?? 0);
                                                    $rank = (int)($c['rank'] ?? 1);
                                                    $st = $rankStylesPhp[$rank] ?? $rankStylesPhp[5];
                                                    $rankBadge = str_pad((string)$rank, 2, '0', STR_PAD_LEFT);
                                                    $displayLabel = $c['rank_label'] ?? ('RANK ' . $rank);

                                                    if (!empty($c['is_ready'])):
                                                ?>
                                                    <!-- Ready Empty State Slot -->
                                                    <div class="flex flex-col items-center justify-end text-center group cursor-pointer" onclick="event.stopPropagation(); openOverviewDrilldown('champions_podium')" title="Open Podium Position <?= $rank ?>: Ready for Contender">
                                                        <div class="mb-2 flex flex-col items-center space-y-1 w-full opacity-60">
                                                            <div class="w-7 h-7 sm:w-8 sm:h-8 rounded-full border-2 border-dashed border-slate-300 bg-white/70 text-slate-400 font-bold text-[10px] sm:text-xs flex items-center justify-center shadow-2xs">
                                                                <i class="fas fa-plus text-[9px] sm:text-[10px] text-slate-400"></i>
                                                            </div>
                                                            <p class="text-[10px] sm:text-xs font-bold text-slate-400 truncate max-w-full">Ready</p>
                                                            <span class="text-[8px] sm:text-[9px] font-medium text-slate-400 bg-slate-100/80 border border-dashed border-slate-200 px-1.5 py-0.2 rounded-full">-- XP</span>
                                                            <div class="pt-0.5 text-slate-200 text-sm sm:text-lg">
                                                                <i class="far fa-star"></i>
                                                            </div>
                                                        </div>
                                                        <div class="w-full <?= $st['heightClass'] ?> rounded-t-xl sm:rounded-t-2xl bg-slate-100/80 border-2 border-dashed border-slate-200 shadow-2xs group-hover:border-slate-300 transition-all duration-300 flex flex-col items-center justify-between py-2.5 px-1 text-slate-400">
                                                            <div class="w-6 h-6 sm:w-7 sm:h-7 rounded-full border-2 border-dashed border-slate-300 bg-white/80 flex items-center justify-center font-bold text-[10px] sm:text-xs text-slate-400 shadow-2xs mt-1">
                                                                <?= $rankBadge ?>
                                                            </div>
                                                            <div class="space-y-0.5 text-center">
                                                                <p class="text-[9px] sm:text-[10px] font-bold text-slate-400 uppercase tracking-wider">Ready</p>
                                                                <span class="text-[7px] sm:text-[8px] font-medium text-slate-400 bg-black/5 px-1.5 py-0.5 rounded-full inline-flex items-center space-x-0.5">
                                                                    <span>Open</span>
                                                                </span>
                                                            </div>
                                                        </div>
                                                        <div class="pt-2 text-center w-full bg-slate-100/90 sm:bg-transparent rounded-b-lg sm:rounded-none">
                                                            <span class="text-[9px] sm:text-[10px] font-bold tracking-wider text-slate-400 uppercase"><?= htmlspecialchars($displayLabel) ?></span>
                                                            <p class="text-[8px] text-slate-400 font-medium hidden sm:block">Awaiting XP</p>
                                                        </div>
                                                    </div>
                                                <?php else:
                                                    $xpDisplay = $xp >= 1000 ? number_format($xp / 1000, 1) . 'k XP' : ($xp . ' XP');
                                                    $parts = preg_split('/\s+/', trim($c['name'] ?? 'Staff'));
                                                    $firstName = $parts[0] ?? 'Staff';
                                                    $initials = count($parts) > 1 ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1)) : strtoupper(substr($parts[0], 0, 2));

                                                    if (!empty($c['is_tied'])) {
                                                        $ordinals = [1 => '1ST', 2 => '2ND', 3 => '3RD', 4 => '4TH', 5 => '5TH'];
                                                        $displayLabel = 'TIED ' . ($ordinals[$rank] ?? $rank);
                                                    }
                                                    $roleShort = str_replace(['Director', 'Supervisor', 'Associate'], ['Dir', 'Sup', 'Assoc'], $c['role'] ?? 'Associate');
                                                ?>
                                                    <!-- Active Champion Slot -->
                                                    <div class="flex flex-col items-center justify-end text-center group cursor-pointer" onclick="event.stopPropagation(); openOverviewDrilldown('champions_podium')" title="<?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['role']) ?>): <?= number_format($xp) ?> XP">
                                                        <div class="mb-2 flex flex-col items-center space-y-1 w-full">
                                                            <div class="w-7 h-7 sm:w-8 sm:h-8 rounded-full <?= $st['avatarBg'] ?> text-white font-bold text-[10px] sm:text-xs flex items-center justify-center shadow-xs border-2 border-white">
                                                                <?= htmlspecialchars($initials) ?>
                                                            </div>
                                                            <p class="text-[10px] sm:text-xs font-bold text-slate-900 truncate max-w-full" title="<?= htmlspecialchars($c['name']) ?>"><?= htmlspecialchars($firstName) ?></p>
                                                            <span class="text-[8px] sm:text-[9px] font-bold <?= $st['xpPill'] ?> px-1.5 py-0.2 rounded-full"><?= $xpDisplay ?></span>
                                                            <div class="pt-0.5 text-gold text-sm sm:text-lg <?= !empty($st['bounceStar']) ? 'animate-bounce drop-shadow-xs' : 'drop-shadow-xs' ?>">
                                                                <i class="fas fa-star"></i>
                                                            </div>
                                                        </div>
                                                        <div class="w-full <?= $st['heightClass'] ?> rounded-t-xl sm:rounded-t-2xl <?= $st['pillarBg'] ?> shadow-sm group-hover:shadow-md group-hover:-translate-y-1.5 transition-all duration-300 flex flex-col items-center justify-between py-2.5 px-1 text-white border-t-2 border-white/40">
                                                            <div class="w-6 h-6 sm:w-7 sm:h-7 rounded-full border-2 border-white bg-black/15 backdrop-blur-xs flex items-center justify-center font-bold text-[10px] sm:text-xs text-white shadow-xs mt-1">
                                                                <?= $rankBadge ?>
                                                            </div>
                                                            <div class="space-y-0.5 text-center">
                                                                <p class="text-[9px] sm:text-[10px] font-bold text-white leading-tight"><?= number_format($xp) ?></p>
                                                                <span class="text-[7px] sm:text-[8px] font-semibold bg-black/25 text-white px-1.5 py-0.5 rounded-full inline-flex items-center space-x-0.5">
                                                                    <span><?= (int)($c['trophies'] ?? 0) ?></span>
                                                                    <i class="fas fa-trophy text-[7px] text-amber-300"></i>
                                                                </span>
                                                            </div>
                                                        </div>
                                                        <div class="pt-2 text-center w-full bg-slate-100/90 sm:bg-transparent rounded-b-lg sm:rounded-none">
                                                            <span class="text-[9px] sm:text-[10px] font-extrabold tracking-wider <?= $st['labelColor'] ?> uppercase"><?= htmlspecialchars($displayLabel) ?></span>
                                                            <p class="text-[8px] text-slate-400 font-medium hidden sm:block truncate" title="<?= htmlspecialchars($c['role']) ?>"><?= htmlspecialchars($roleShort) ?></p>
                                                        </div>
                                                    </div>
                                                <?php endif; endforeach; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <button onclick="event.stopPropagation(); switchPillar('pillar-social')"
                                            class="w-full py-2.5 bg-brand-canvas hover:bg-slate-100 text-slate-700 font-semibold text-xs rounded-xl border border-brand-border transition flex items-center justify-center space-x-1.5">
                                            <i class="fas fa-award text-gold"></i>
                                            <span>View All Leaderboard Ranks &amp; Kudos</span>
                                        </button>
                                    </div>

                                    <!-- Column 2: Shift Climate Pulse (7 cols) -->
                                    <div class="lg:col-span-7 card-clean p-6 space-y-4">
                                        <div class="flex items-center justify-between cursor-pointer group" onclick="openOverviewDrilldown('shift_sentiment')" title="Click for full shift climate & sentiment log">
                                            <div>
                                                <h3 class="font-heading font-bold text-base text-slate-900 group-hover:text-primary transition-colors flex items-center space-x-1.5">
                                                    <span>Shift Climate Pulse</span>
                                                    <i class="fas fa-arrow-up-right-from-square text-[10px] text-slate-400 group-hover:text-primary transition-colors"></i>
                                                </h3>
                                                <p id="pulse-total-staff-subtitle" class="text-xs text-slate-500">Aggregated Employee Sentiment (Live Supabase Telemetry)</p>
                                            </div>
                                            <span class="badge-sage text-[10px]"><i class="fas fa-heart-pulse mr-1"></i>Live Pulse</span>
                                        </div>
                                        
                                        <div class="grid grid-cols-1 md:grid-cols-12 gap-6 items-center pt-2">
                                            <!-- Doughnut Chart Canvas Container -->
                                            <div onclick="openOverviewDrilldown('shift_sentiment')" class="md:col-span-5 h-48 w-full flex items-center justify-center relative cursor-pointer hover:scale-[1.01] transition-transform" title="Click for sentiment distribution & recent mood log">
                                                <canvas id="chart-sentiment-doughnut"></canvas>
                                                
                                                <!-- Empty State for Shift Climate Pulse -->
                                                <div id="chart-sentiment-empty-state" class="overview-loading-overlay hidden absolute inset-0 flex-col items-center justify-center text-center p-4 bg-slate-50/90 rounded-2xl border border-dashed border-slate-200">
                                                    <div class="w-11 h-11 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center text-lg mb-2 shadow-2xs">
                                                        <i class="fas fa-heart-pulse text-primary/60"></i>
                                                    </div>
                                                    <p class="font-bold text-xs text-slate-700">No Shift Climate Data</p>
                                                    <p class="text-[10px] text-slate-400 mt-0.5 max-w-52.5 leading-tight">No employee shift sentiments recorded yet in Supabase. Check in above to start tracking live team pulse.</p>
                                                </div>
                                            </div>
                                            
                                            <!-- Sentiment Breakdown Metric Cards -->
                                            <div onclick="openOverviewDrilldown('shift_sentiment')" class="md:col-span-7 grid grid-cols-1 sm:grid-cols-3 gap-3 text-center cursor-pointer" title="Click for sentiment history">
                                                <div class="p-3.5 bg-sage-50/70 rounded-2xl border border-sage-100/90 hover:bg-sage-100/70 transition-colors">
                                                    <div class="w-7 h-7 rounded-full bg-sage-100 text-sage-dark flex items-center justify-center mx-auto mb-1.5 text-xs">
                                                        <i class="fas fa-face-smile"></i>
                                                    </div>
                                                    <p id="pulse-smooth-pct" class="text-lg font-extrabold text-sage-dark">0.0%</p>
                                                    <p class="text-[11px] font-bold text-slate-800 mt-0.5">Smooth</p>
                                                    <p class="text-[9px] text-slate-500 mt-0.5">High morale</p>
                                                </div>
                                                <div class="p-3.5 bg-dusty-50/70 rounded-2xl border border-dusty-100/90 hover:bg-dusty-100/70 transition-colors">
                                                    <div class="w-7 h-7 rounded-full bg-dusty-100 text-dusty-dark flex items-center justify-center mx-auto mb-1.5 text-xs">
                                                        <i class="fas fa-face-meh"></i>
                                                    </div>
                                                    <p id="pulse-manageable-pct" class="text-lg font-extrabold text-dusty-dark">0.0%</p>
                                                    <p class="text-[11px] font-bold text-slate-800 mt-0.5">Manageable</p>
                                                    <p class="text-[9px] text-slate-500 mt-0.5">Steady</p>
                                                </div>
                                                <div class="p-3.5 bg-terracotta-50/70 rounded-2xl border border-terracotta-100/90 hover:bg-terracotta-100/70 transition-colors">
                                                    <div class="w-7 h-7 rounded-full bg-terracotta-100 text-terracotta-dark flex items-center justify-center mx-auto mb-1.5 text-xs">
                                                        <i class="fas fa-face-frown"></i>
                                                    </div>
                                                    <p id="pulse-friction-pct" class="text-lg font-extrabold text-terracotta-dark">0.0%</p>
                                                    <p class="text-[11px] font-bold text-slate-800 mt-0.5">Friction</p>
                                                    <p class="text-[9px] text-slate-500 mt-0.5">Needs check</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                </div>

                                <!-- Row 2: Department Execution Matrix (Full Width) -->
                                <div class="grid grid-cols-1 gap-6 items-start">

                                    <!-- Department Completion & Progress Comparison (Full Width) -->
                                    <div class="card-clean p-6 space-y-4">
                                        <div class="flex items-center justify-between cursor-pointer group" onclick="openOverviewDrilldown('dept_matrix')" title="Click for detailed department execution metrics & rankings">
                                            <div>
                                                <h3 class="font-heading font-bold text-base text-slate-900 group-hover:text-primary transition-colors flex items-center space-x-1.5">
                                                    <span>Department Execution Matrix</span>
                                                    <i class="fas fa-arrow-up-right-from-square text-[10px] text-slate-400 group-hover:text-primary transition-colors"></i>
                                                </h3>
                                                <p class="text-xs text-slate-500">Goal Approval %, LMS Completion %, and Succession Depth across all property departments</p>
                                            </div>
                                            <span class="badge-neutral text-[10px]">5 Departments</span>
                                        </div>

                                        <!-- Department Comparison Horizontal Bar Chart -->
                                        <div onclick="openOverviewDrilldown('dept_matrix')" class="h-56 sm:h-64 w-full relative cursor-pointer hover:opacity-95 transition-opacity" title="Click to view department comparison telemetry">
                                            <canvas id="chart-system-dept-progress"></canvas>
                                            <div id="dept-matrix-loading-overlay" class="overview-loading-overlay absolute inset-0 bg-white/60 backdrop-blur-[1px] rounded-lg hidden items-center justify-center transition-opacity">
                                                <div class="flex items-center space-x-2 text-xs font-semibold text-slate-600 bg-white/90 shadow-sm px-3 py-1.5 rounded-full border border-slate-200">
                                                    <i class="fas fa-circle-notch fa-spin text-primary"></i>
                                                    <span>Syncing...</span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Department Breakdown Mini Table -->
                                        <div onclick="openOverviewDrilldown('dept_matrix')" class="overflow-x-auto custom-scrollbar pt-2 border-t border-brand-border cursor-pointer hover:bg-slate-50/40 transition-colors" title="Click to inspect full department breakdown">
                                            <table class="w-full text-left text-xs">
                                                <thead>
                                                    <tr class="text-slate-400 font-semibold border-b border-brand-border">
                                                        <th class="pb-2 font-medium">Department</th>
                                                        <th class="pb-2 font-medium text-center">Staff</th>
                                                        <th class="pb-2 font-medium text-center">Goals Approved</th>
                                                        <th class="pb-2 font-medium text-center">LMS Rate</th>
                                                        <th class="pb-2 font-medium text-center">Succession</th>
                                                        <th class="pb-2 font-medium text-right">Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="table-dept-execution-matrix-body" class="divide-y divide-brand-border">
                                                    <?php foreach ($deptMatrixRows as $dRow): 
                                                         $gColor = $dRow['goals_approved_pct'] > 0 ? 'text-sage-dark font-bold' : 'text-slate-400 font-medium';
                                                         $lColor = $dRow['lms_rate_pct'] > 0 ? 'text-primary font-bold' : 'text-slate-400 font-medium';
                                                         $sColor = $dRow['succession_ready_pct'] > 0 ? 'text-dusty-dark font-bold' : 'text-slate-400 font-medium';
                                                     ?>
                                                    <tr class="hover:bg-slate-50/50 transition-colors">
                                                        <td class="py-2.5 font-bold text-slate-800"><?= htmlspecialchars($dRow['department']) ?></td>
                                                        <td class="py-2.5 text-center text-slate-500 font-medium"><?= (int)$dRow['staff_count'] ?></td>
                                                        <td class="py-2.5 text-center <?= $gColor ?>"><?= number_format($dRow['goals_approved_pct'], 1) ?>%</td>
                                                        <td class="py-2.5 text-center <?= $lColor ?>"><?= number_format($dRow['lms_rate_pct'], 1) ?>%</td>
                                                        <td class="py-2.5 text-center <?= $sColor ?>"><?= number_format($dRow['succession_ready_pct'], 1) ?>%</td>
                                                        <td class="py-2.5 text-right"><span class="<?= $dRow['badge_class'] ?>"><?= htmlspecialchars($dRow['status']) ?></span></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                            <script>
                                                window.initialDeptMatrixData = <?= json_encode($deptMatrixRows) ?>;
                                            </script>
                                        </div>
                                    </div>

                                </div>

                            </div>

                        </div>

                        <!-- OVERVIEW DRILLDOWN & REALTIME TELEMETRY MODAL -->
                        <div id="modal-overview-drilldown" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-xs p-4 sm:p-6 overflow-y-auto">
                            <div class="relative w-full max-w-2xl bg-white rounded-3xl shadow-2xl border border-brand-border flex flex-col max-h-[90vh] overflow-hidden animate-in fade-in zoom-in-95 duration-200">
                                
                                <!-- Modal Header -->
                                <div class="p-5 sm:p-6 border-b border-brand-border flex items-start justify-between bg-brand-canvas/60">
                                    <div class="flex items-start space-x-3.5">
                                        <div id="overview-modal-icon-bg" class="w-10 h-10 rounded-2xl bg-primary/10 text-primary flex items-center justify-center text-lg font-bold shrink-0">
                                            <i id="overview-modal-icon" class="fas fa-chart-line"></i>
                                        </div>
                                        <div class="space-y-0.5">
                                            <div class="flex items-center space-x-2 flex-wrap">
                                                <h3 id="overview-modal-title" class="font-heading font-bold text-base sm:text-lg text-slate-900 leading-tight">Metric Detail</h3>
                                                <span id="overview-modal-cache-badge" class="badge-sage text-[10px]"><i class="fas fa-bolt mr-1"></i>Live Data</span>
                                            </div>
                                            <p id="overview-modal-subtitle" class="text-xs text-slate-500">Telemetry &amp; granular drilldown</p>
                                        </div>
                                    </div>
                                    
                                    <div class="flex items-center space-x-2 shrink-0">
                                        <!-- Refresh Button with real-time feedback -->
                                        <button type="button" id="btn-refresh-overview-modal" onclick="refreshCurrentOverviewModal()" class="w-8 h-8 rounded-full border border-brand-border bg-white hover:bg-slate-100 text-slate-600 flex items-center justify-center transition shadow-2xs" title="Refresh Live Telemetry">
                                            <i class="fas fa-rotate text-xs"></i>
                                        </button>
                                        <!-- Close Button -->
                                        <button type="button" onclick="closeModal('modal-overview-drilldown')" class="w-8 h-8 rounded-full border border-brand-border bg-white hover:bg-rose-50 hover:text-rose-600 hover:border-rose-200 text-slate-400 flex items-center justify-center transition shadow-2xs" title="Close Modal">
                                            <i class="fas fa-xmark text-sm"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Modal Body (Dynamic AJAX / Cache Content) -->
                                <div id="overview-modal-body" class="p-5 sm:p-6 space-y-4 overflow-y-auto custom-scrollbar flex-1">
                                    <!-- Dynamically populated by js/overview.js -->
                                </div>

                                <!-- Modal Footer -->
                                <div class="p-4 sm:px-6 border-t border-brand-border bg-slate-50/80 flex items-center justify-between text-xs">
                                    <div class="flex items-center space-x-2 text-slate-400 text-[11px]">
                                        <i class="fas fa-clock text-[10px]"></i>
                                        <span id="overview-modal-timestamp">Synced just now</span>
                                        <span class="text-slate-300 hidden sm:inline">•</span>
                                        <span id="overview-modal-cache-info" class="text-slate-400 hidden sm:inline">Realtime TTL 45s</span>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <button type="button" onclick="closeModal('modal-overview-drilldown')" class="btn-neutral px-4 py-2 text-xs font-semibold">
                                            Close
                                        </button>
                                        <button type="button" id="overview-modal-action-btn" class="btn-primary px-4 py-2 text-xs font-bold flex items-center space-x-1.5 shadow-xs">
                                            <span>Go to Module</span>
                                            <i class="fas fa-arrow-right text-[10px]"></i>
                                        </button>
                                    </div>
                                </div>

                            </div>
                        </div>

                        <!-- ======================================================== -->
                        <!-- ======================================================== -->
                        <!-- PILLAR 1: PERFORMANCE MANAGEMENT (7-STAGE CONTINUOUS CYCLE) -->
