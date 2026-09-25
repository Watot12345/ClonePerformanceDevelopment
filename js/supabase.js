/**
 * Supabase Client & Instant In-Memory Realtime Engine (Frontend JavaScript)
 * Oxford Suites, Makati · HR3 System
 */

const SUPABASE_CONFIG = window.SUPABASE_CONFIG || {
    url: 'https://jvxnrgcxegzhyaekxdok.supabase.co',
    anonKey: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Imp2eG5yZ2N4ZWd6aHlhZWt4ZG9rIiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODc1NTczOTYsImV4cCI6MjEwMzEzMzM5Nn0.nPTeedzMfSnFgFhxb2PDoXiH_aW8Mmwt04ltYR7IznU'
};

// Initialize Supabase Client
let supabaseClient = null;
if (typeof supabase !== 'undefined' && supabase.createClient) {
    try {
        supabaseClient = supabase.createClient(SUPABASE_CONFIG.url, SUPABASE_CONFIG.anonKey, {
            realtime: {
                params: {
                    eventsPerSecond: 20
                }
            }
        });
        window.supabaseClient = supabaseClient;
    } catch (e) {
        console.warn('[Supabase] Failed to initialize createClient:', e);
    }
}

/**
 * Helper to fetch data from Supabase REST API directly via fetch
 */
async function fetchSupabase(table, options = {}) {
    const { method = 'GET', body = null, headers = {} } = options;
    const url = `${SUPABASE_CONFIG.url}/rest/v1/${table}`;
    
    const requestHeaders = {
        'apikey': SUPABASE_CONFIG.anonKey,
        'Authorization': `Bearer ${SUPABASE_CONFIG.anonKey}`,
        'Content-Type': 'application/json',
        'Prefer': 'return=representation',
        ...headers
    };

    try {
        const response = await fetch(url, {
            method,
            headers: requestHeaders,
            body: body ? JSON.stringify(body) : null
        });

        if (!response.ok) {
            const error = await response.json().catch(() => ({ message: response.statusText }));
            throw new Error(error.message || 'Supabase request failed');
        }

        return await response.json();
    } catch (err) {
        console.error(`[Supabase Error] ${table}:`, err);
        throw err;
    }
}
window.fetchSupabase = fetchSupabase;

/**
 * Realtime Instant In-Memory Synchronizer
 * Mutates state and re-renders visible components in 0ms without network fetches or loading indicators
 */
let realtimeChannels = {};

/**
 * Global Performance Realtime Debounced Sync Engine (Stages 1 through 7)
 * Synchronizes visible stage tables, active detail cards, and top navigation stepper badges in 0ms without reloads
 */
let _perfRealtimeDebounceTimer = null;
function triggerPerformanceRealtimeSync(sourceTable, empId = null) {
    if (_perfRealtimeDebounceTimer) {
        clearTimeout(_perfRealtimeDebounceTimer);
    }
    _perfRealtimeDebounceTimer = setTimeout(() => {
        // 1. Instant re-render of currently active stage table
        if (typeof renderActiveStageTable === 'function') {
            renderActiveStageTable();
        }

        // 2. Re-render all stage tables in background so switching stage tabs is instant
        if (typeof renderAllStageTables === 'function') {
            renderAllStageTables();
        }

        // 3. Update top stepper badge counters across all Stages 1 to 7
        if (typeof updateAllPerfStepperBadges === 'function') {
            updateAllPerfStepperBadges();
        }

        // 4. Update Stage 1 Pulse Goals (Employee & Supervisor self-views)
        if (typeof renderEmployeePulseGoals === 'function' && Array.isArray(window.dbGoals)) {
            renderEmployeePulseGoals(window.dbGoals);
        }

        // 5. Update Stage 2 General Tasks Matrix Table
        if (sourceTable === 'performance_general_tasks' && typeof renderGeneralTasksTable === 'function') {
            renderGeneralTasksTable();
        }

        // 6. Update Stage 4 Appraisal Metrics
        if (typeof renderAppraisalMetrics === 'function') {
            renderAppraisalMetrics();
        }

        // 7. Update Stage 5 Calibration Distribution
        if (typeof renderCalibrationDistribution === 'function') {
            renderCalibrationDistribution();
        }

        // 8. Re-render active detail panels or modals if an associate is currently selected
        const targetEmpId = empId || window.selectedEvalEmpId || window.selectedCalibEmpId;
        if (targetEmpId) {
            // Stage 4 detail card
            const evalDetailEl = document.getElementById('eval-detail-emp-name');
            if (evalDetailEl && typeof showAppraisalDetail === 'function') {
                showAppraisalDetail(targetEmpId);
            }

            // Stage 5 detail card
            const calibDetailEl = document.getElementById('calib-detail-emp-name');
            if (calibDetailEl && typeof showCalibrationDetail === 'function') {
                showCalibrationDetail(targetEmpId);
            }

            // Stage 6 detail card
            const idpDetailEl = document.getElementById('idp-detail-emp-name');
            if (idpDetailEl && typeof showIDPDetail === 'function') {
                showIDPDetail(targetEmpId);
            }

            // Stage 7 transition card
            const cycleDetailEl = document.getElementById('cycle-detail-transition-card');
            if (cycleDetailEl && typeof showCycleDetail === 'function') {
                showCycleDetail(targetEmpId, false);
            }

            // Stage 7 Review Tasks Modal (if open)
            const reviewModalEl = document.getElementById('modal-review-tasks');
            if (reviewModalEl && !reviewModalEl.classList.contains('hidden') && typeof openReviewTasksModal === 'function') {
                openReviewTasksModal(targetEmpId);
            }

            // Stage 3 Monitoring Stream Modal (if open)
            const monStreamModal = document.getElementById('modal-monitoring-stream');
            if (monStreamModal && !monStreamModal.classList.contains('hidden') && typeof renderEmployeeMonitoringStream === 'function') {
                const emp = (window.perfRoster || []).find(e => typeof isSameEmployee === 'function' ? isSameEmployee(e.id, targetEmpId) : e.id == targetEmpId);
                if (emp) renderEmployeeMonitoringStream(emp);
            }
        }

        // Objective Details Modal (if open, instant live sync)
        const viewGoalModal = document.getElementById('modal-view-goal');
        if (viewGoalModal && !viewGoalModal.classList.contains('hidden') && typeof refreshObjectiveDetailsModal === 'function') {
            refreshObjectiveDetailsModal();
        }

        // 9. Silent background parity fetch to guarantee 100% database integrity without skeleton flash
        if (typeof loadAndRenderPlanningGoals === 'function') {
            loadAndRenderPlanningGoals(true).catch(() => {});
        }

        // 10. Live sync Department Execution Matrix on Goals/Tasks/Evals updates
        if (typeof fetchAndRenderDepartmentExecutionMatrix === 'function') {
            fetchAndRenderDepartmentExecutionMatrix(true);
        }

        // 11. Dispatch live audit log entry
        if (typeof window.appendLiveAuditLog === 'function' && sourceTable) {
            window.appendLiveAuditLog(
                'Performance Management',
                'REALTIME_' + sourceTable.toUpperCase() + '_SYNC',
                'Oxford Performance Engine',
                `Synchronized ${sourceTable.replace(/_/g, ' ')} across active views.`,
                'SUCCESS'
            );
        }
    }, 100);
}
window.triggerPerformanceRealtimeSync = triggerPerformanceRealtimeSync;

function initSupabaseRealtime() {
    if (!supabaseClient) return;

    try {
        // ====================================================================
        // 1. Performance Lifecycle Hub Channel (Realtime across Stages 1 to 7)
        // ====================================================================
        if (!realtimeChannels.performance_hub) {
            realtimeChannels.performance_hub = supabaseClient
                .channel('realtime_performance_hub')
                // A. Performance Goals (Stages 1, 2, 3, 4, 5, 6, 7)
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'performance_goals' },
                    (payload) => {
                        const newRow = payload.new || {};
                        const oldRow = payload.old || {};
                        const empId = newRow.employee_id || oldRow.employee_id;

                        // 1. Live Sync window.dbGoals
                        if (Array.isArray(window.dbGoals)) {
                            if (payload.eventType === 'INSERT' && newRow.id) {
                                const exists = window.dbGoals.some(g => g.id == newRow.id);
                                if (!exists) {
                                    newRow.tasks = newRow.tasks || [];
                                    window.dbGoals.unshift(newRow);
                                }
                            } else if (payload.eventType === 'UPDATE' && newRow.id) {
                                const idx = window.dbGoals.findIndex(g => g.id == newRow.id);
                                if (idx >= 0) {
                                    const existingTasks = window.dbGoals[idx].tasks || [];
                                    window.dbGoals[idx] = Object.assign({}, window.dbGoals[idx], newRow);
                                    if (!newRow.tasks && existingTasks.length > 0) {
                                        window.dbGoals[idx].tasks = existingTasks;
                                    }
                                } else {
                                    window.dbGoals.unshift(newRow);
                                }
                            } else if (payload.eventType === 'DELETE' && oldRow.id) {
                                window.dbGoals = window.dbGoals.filter(g => g.id != oldRow.id);
                            }
                        }

                        // 2. Sync perfRoster employee goals
                        if (Array.isArray(window.perfRoster) && empId) {
                            const emp = window.perfRoster.find(e => typeof isSameEmployee === 'function' ? isSameEmployee(e.id, empId) : e.id == empId);
                            if (emp) {
                                emp.goals = emp.goals || [];
                                if (payload.eventType === 'INSERT' && newRow.id) {
                                    const gExists = emp.goals.some(g => g.id == newRow.id);
                                    if (!gExists) {
                                        emp.goals.unshift({
                                            id: newRow.id,
                                            title: newRow.title,
                                            category: newRow.department,
                                            department: newRow.department,
                                            kpi: newRow.target_metric,
                                            target_metric: newRow.target_metric,
                                            weight: newRow.weight,
                                            evidence: newRow.evidence,
                                            deliverables: newRow.evidence || 'Standard shift operational log verification',
                                            targetDate: newRow.target_date,
                                            target_date: newRow.target_date,
                                            status: newRow.status || 'Pending Approval',
                                            supervisor_notes: newRow.supervisor_notes,
                                            tasks: newRow.tasks || [],
                                            general_tasks: newRow.general_tasks || [],
                                            specific_tasks: newRow.specific_tasks || [],
                                            task_progress: 0,
                                            created_at: newRow.created_at
                                        });
                                    }
                                } else if (payload.eventType === 'UPDATE' && newRow.id) {
                                    const gIdx = emp.goals.findIndex(g => g.id == newRow.id);
                                    if (gIdx >= 0) {
                                        Object.assign(emp.goals[gIdx], {
                                            title: newRow.title || emp.goals[gIdx].title,
                                            category: newRow.department || emp.goals[gIdx].category,
                                            department: newRow.department || emp.goals[gIdx].department,
                                            kpi: newRow.target_metric || emp.goals[gIdx].kpi,
                                            target_metric: newRow.target_metric || emp.goals[gIdx].target_metric,
                                            weight: newRow.weight || emp.goals[gIdx].weight,
                                            deliverables: newRow.evidence || emp.goals[gIdx].deliverables,
                                            evidence: newRow.evidence || emp.goals[gIdx].evidence,
                                            targetDate: newRow.target_date || emp.goals[gIdx].targetDate,
                                            target_date: newRow.target_date || emp.goals[gIdx].target_date,
                                            status: newRow.status || emp.goals[gIdx].status,
                                            supervisor_notes: newRow.supervisor_notes !== undefined ? newRow.supervisor_notes : emp.goals[gIdx].supervisor_notes,
                                            needs_training: newRow.needs_training !== undefined ? newRow.needs_training : emp.goals[gIdx].needs_training,
                                            retry_count: newRow.retry_count !== undefined ? newRow.retry_count : emp.goals[gIdx].retry_count,
                                            final_rating: newRow.final_rating !== undefined ? newRow.final_rating : emp.goals[gIdx].final_rating
                                        });
                                    }
                                } else if (payload.eventType === 'DELETE' && oldRow.id) {
                                    emp.goals = emp.goals.filter(g => g.id != oldRow.id);
                                }
                                emp.goalsCount = emp.goals.length;
                                const hasPending = emp.goals.some(g => {
                                    const st = (g.status || '').toLowerCase();
                                    return st !== 'approved' && st !== 'completed' && st !== 'failed';
                                });
                                const allFailed = emp.goals.length > 0 && emp.goals.every(g => (g.status || '').toLowerCase() === 'failed');
                                emp.planningStatus = allFailed ? 'Failed' : (hasPending ? 'Pending Approval' : (emp.goals.length > 0 ? 'Approved' : 'Draft'));
                                emp.approvalStatus = emp.planningStatus;
                            }
                        }

                        // 3. Update in-memory employee goals_summary in Competency Matrix table
                        if (empId) {
                            const isNT = (newRow.needs_training === true || newRow.needs_training === 1 || newRow.needs_training === '1' || newRow.needs_training === 'true' || newRow.needs_training === 't');
                            const isIT = (newRow.in_training === true || newRow.in_training === 1 || newRow.in_training === '1' || newRow.in_training === 'true' || newRow.in_training === 't');

                            const employees = window.dynamicCompetencyState?.employees || [];
                            const targetEmp = employees.find(e => typeof isSameEmployee === 'function' ? isSameEmployee(e.id, empId) : e.id == empId);
                            if (targetEmp) {
                                targetEmp.goals_summary = targetEmp.goals_summary || {};
                                targetEmp.goals_summary.needs_training = isNT;
                                targetEmp.goals_summary.in_training = isIT;
                                targetEmp.goals_summary.status_label = isNT ? 'Needs Training' : (isIT ? 'In Training' : (newRow.status || null));
                                if (typeof renderCompetencyMatrixTable === 'function') {
                                    renderCompetencyMatrixTable();
                                }
                            }
                        }

                        triggerPerformanceRealtimeSync('performance_goals', empId);
                    }
                )
                // B. Performance Tasks (Stages 1, 2, 3, 4, 6, 7)
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'performance_tasks' },
                    (payload) => {
                        const newRow = payload.new || {};
                        const oldRow = payload.old || {};
                        const goalId = newRow.goal_id || oldRow.goal_id;
                        const empId = newRow.employee_id || oldRow.employee_id;

                        // 1. Live Sync tasks in window.dbGoals
                        if (Array.isArray(window.dbGoals) && goalId) {
                            const goal = window.dbGoals.find(g => g.id == goalId);
                            if (goal) {
                                goal.tasks = goal.tasks || [];
                                if (payload.eventType === 'INSERT' && newRow.id) {
                                    const tExists = goal.tasks.some(t => t.id == newRow.id);
                                    if (!tExists) goal.tasks.push(newRow);
                                } else if (payload.eventType === 'UPDATE' && newRow.id) {
                                    const tIdx = goal.tasks.findIndex(t => t.id == newRow.id);
                                    if (tIdx >= 0) {
                                        goal.tasks[tIdx] = Object.assign({}, goal.tasks[tIdx], newRow);
                                    } else {
                                        goal.tasks.push(newRow);
                                    }
                                } else if (payload.eventType === 'DELETE' && oldRow.id) {
                                    goal.tasks = goal.tasks.filter(t => t.id != oldRow.id);
                                }
                                const done = goal.tasks.filter(t => t.status === 'completed').length;
                                goal.task_progress = goal.tasks.length > 0 ? Math.round((done / goal.tasks.length) * 100) : 0;
                                goal.progress = goal.task_progress;
                            }
                        }

                        // 2. Live Sync tasks in window.perfRoster employee goals
                        if (Array.isArray(window.perfRoster)) {
                            window.perfRoster.forEach(emp => {
                                (emp.goals || []).forEach(goal => {
                                    if (goal.id == goalId) {
                                        goal.tasks = goal.tasks || [];
                                        if (payload.eventType === 'INSERT' && newRow.id) {
                                            const tExists = goal.tasks.some(t => t.id == newRow.id);
                                            if (!tExists) goal.tasks.push(newRow);
                                        } else if (payload.eventType === 'UPDATE' && newRow.id) {
                                            const tIdx = goal.tasks.findIndex(t => t.id == newRow.id);
                                            if (tIdx >= 0) {
                                                goal.tasks[tIdx] = Object.assign({}, goal.tasks[tIdx], newRow);
                                            } else {
                                                goal.tasks.push(newRow);
                                            }
                                        } else if (payload.eventType === 'DELETE' && oldRow.id) {
                                            goal.tasks = goal.tasks.filter(t => t.id != oldRow.id);
                                        }
                                        const done = goal.tasks.filter(t => t.status === 'completed').length;
                                        goal.task_progress = goal.tasks.length > 0 ? Math.round((done / goal.tasks.length) * 100) : 0;
                                        goal.progress = goal.task_progress;
                                    }
                                });
                            });
                        }

                        triggerPerformanceRealtimeSync('performance_tasks', empId);
                        if (typeof fetchPrescribedLms === 'function') {
                            fetchPrescribedLms();
                        }
                        if (typeof renderLmsBooks === 'function') {
                            renderLmsBooks();
                        }
                    }
                )
                // C. Performance Evaluations (Stages 4, 5, 6, 7)
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'performance_evaluations' },
                    (payload) => {
                        const newRow = payload.new || {};
                        const oldRow = payload.old || {};
                        const empId = newRow.employee_id || oldRow.employee_id;

                        // 1. Live Sync window.dbEvaluations
                        if (Array.isArray(window.dbEvaluations)) {
                            if (payload.eventType === 'INSERT' && newRow.id) {
                                const exists = window.dbEvaluations.some(ev => ev.id == newRow.id || (newRow.goal_id && ev.goal_id && String(ev.goal_id) === String(newRow.goal_id) && isSameEmployee(ev.employee_id, empId)));
                                if (!exists) {
                                    window.dbEvaluations.unshift(newRow);
                                } else {
                                    const idx = window.dbEvaluations.findIndex(ev => ev.id == newRow.id || (newRow.goal_id && ev.goal_id && String(ev.goal_id) === String(newRow.goal_id) && isSameEmployee(ev.employee_id, empId)));
                                    if (idx >= 0) window.dbEvaluations[idx] = Object.assign({}, window.dbEvaluations[idx], newRow);
                                }
                            } else if (payload.eventType === 'UPDATE' && (newRow.id || empId)) {
                                const idx = window.dbEvaluations.findIndex(ev => (newRow.id && ev.id == newRow.id) || (newRow.goal_id && ev.goal_id && String(ev.goal_id) === String(newRow.goal_id) && isSameEmployee(ev.employee_id, empId)));
                                if (idx >= 0) {
                                    window.dbEvaluations[idx] = Object.assign({}, window.dbEvaluations[idx], newRow);
                                } else {
                                    window.dbEvaluations.unshift(newRow);
                                }
                            } else if (payload.eventType === 'DELETE' && (oldRow.id || empId)) {
                                window.dbEvaluations = window.dbEvaluations.filter(ev => (oldRow.id && ev.id != oldRow.id));
                            }
                        }

                        // 2. Sync matching employee in window.perfRoster only if matching active goal
                        if (Array.isArray(window.perfRoster) && empId) {
                            const emp = window.perfRoster.find(e => isSameEmployee(e.id, empId) || isSameEmployee(e.employee_code, empId));
                            const activeGoal = typeof getEmployeeActiveGoal === 'function' ? getEmployeeActiveGoal(empId) : null;
                            const matchesActiveGoal = !newRow.goal_id || !activeGoal || String(newRow.goal_id) === String(activeGoal.id);

                            if (emp && matchesActiveGoal) {
                                emp.evaluationRecord = Object.assign({}, emp.evaluationRecord || {}, newRow);
                                const supScore = (newRow.supervisor_rating !== undefined && newRow.supervisor_rating !== null && parseFloat(newRow.supervisor_rating) > 0)
                                    ? parseFloat(newRow.supervisor_rating)
                                    : (emp.supervisorRating || 0.0);
                                const selfScore = (newRow.self_evaluation !== undefined && newRow.self_evaluation !== null && parseFloat(newRow.self_evaluation) > 0)
                                    ? parseFloat(newRow.self_evaluation)
                                    : (emp.selfRating || 0.0);
                                const calibScore = (newRow.calibrated_score !== undefined && newRow.calibrated_score !== null && parseFloat(newRow.calibrated_score) > 0)
                                    ? parseFloat(newRow.calibrated_score)
                                    : (emp.calibratedScore || null);

                                emp.supervisorRating = supScore;
                                emp.selfRating = selfScore;
                                if (calibScore) emp.calibratedScore = calibScore;
                                if (newRow.tier_label) emp.tierLabel = newRow.tier_label;
                                emp.evaluationStatus = newRow.status || (calibScore ? 'Calibrated' : (supScore > 0 ? 'Rated' : (selfScore ? 'Self-Reviewed' : 'Pending Evaluation')));
                                if (newRow.status === 'Calibrated') emp.reviewStatus = 'Calibrated';
                            }
                        }

                        triggerPerformanceRealtimeSync('performance_evaluations', empId);
                    }
                )
                // D. Performance Development Plans (Stages 6 & 7)
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'performance_development_plans' },
                    (payload) => {
                        const newRow = payload.new || {};
                        const oldRow = payload.old || {};
                        const empId = newRow.employee_id || oldRow.employee_id;

                        if (empId) {
                            window.dbDraftPlans = window.dbDraftPlans || {};
                            if (typeof loadDraftSummary === 'function') {
                                loadDraftSummary(empId, true).then(summary => {
                                    window.dbDraftPlans[empId] = summary;
                                    triggerPerformanceRealtimeSync('performance_development_plans', empId);
                                }).catch(() => {
                                    triggerPerformanceRealtimeSync('performance_development_plans', empId);
                                });
                            } else {
                                delete window.dbDraftPlans[empId];
                                triggerPerformanceRealtimeSync('performance_development_plans', empId);
                            }
                        } else {
                            triggerPerformanceRealtimeSync('performance_development_plans');
                        }
                    }
                )
                // E. General Tasks (Stage 2)
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'performance_general_tasks' },
                    (payload) => {
                        const newRow = payload.new || {};
                        const oldRow = payload.old || {};

                        if (Array.isArray(window.dbGeneralTasks)) {
                            if (payload.eventType === 'INSERT' && newRow.id) {
                                const exists = window.dbGeneralTasks.some(t => t.id == newRow.id);
                                if (!exists) window.dbGeneralTasks.unshift(newRow);
                            } else if (payload.eventType === 'UPDATE' && newRow.id) {
                                const idx = window.dbGeneralTasks.findIndex(t => t.id == newRow.id);
                                if (idx >= 0) window.dbGeneralTasks[idx] = Object.assign({}, window.dbGeneralTasks[idx], newRow);
                                else window.dbGeneralTasks.unshift(newRow);
                            } else if (payload.eventType === 'DELETE' && oldRow.id) {
                                window.dbGeneralTasks = window.dbGeneralTasks.filter(t => t.id != oldRow.id);
                            }
                        }

                        if (typeof renderGeneralTasksTable === 'function') {
                            renderGeneralTasksTable();
                        }
                        triggerPerformanceRealtimeSync('performance_general_tasks');
                    }
                )
                // F. Monitoring Milestones & Evidence (Stage 3)
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'performance_monitoring' },
                    (payload) => {
                        const newRow = payload.new || {};
                        const oldRow = payload.old || {};
                        const empId = newRow.employee_id || oldRow.employee_id;
                        triggerPerformanceRealtimeSync('performance_monitoring', empId);
                    }
                )
                // G. Coaching & 1-on-1 Notes (Stage 3)
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'coaching_notes' },
                    (payload) => {
                        const newRow = payload.new || {};
                        const oldRow = payload.old || {};
                        const empId = newRow.employee_id || oldRow.employee_id;
                        triggerPerformanceRealtimeSync('coaching_notes', empId);
                    }
                )
                // H. Training Needs & Deficits (Stages 3 & 7)
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'training_needs' },
                    (payload) => {
                        const newRow = payload.new || {};
                        const oldRow = payload.old || {};
                        const empId = newRow.employee_id || oldRow.employee_id;

                        if (Array.isArray(window.dbTrainingNeeds)) {
                            if (payload.eventType === 'INSERT' && newRow.id) {
                                window.dbTrainingNeeds.unshift(newRow);
                            } else if (payload.eventType === 'UPDATE' && newRow.id) {
                                const idx = window.dbTrainingNeeds.findIndex(tn => tn.id == newRow.id);
                                if (idx >= 0) window.dbTrainingNeeds[idx] = Object.assign({}, window.dbTrainingNeeds[idx], newRow);
                            } else if (payload.eventType === 'DELETE' && oldRow.id) {
                                window.dbTrainingNeeds = window.dbTrainingNeeds.filter(tn => tn.id != oldRow.id);
                            }
                        }

                        triggerPerformanceRealtimeSync('training_needs', empId);
                    }
                )
                .subscribe((status) => {
                    if (status === 'SUBSCRIBED') {
                        console.log('[Supabase Realtime] Performance Lifecycle Hub subscribed (Stages 1-7 live)');
                    }
                });
        }

        // 2. Certificates Registry Channel (Instant DOM Mutation)
        if (!realtimeChannels.certificates) {
            realtimeChannels.certificates = supabaseClient
                .channel('realtime_certificates')
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'certificates' },
                    (payload) => {
                        const newRow = payload.new || {};
                        const oldRow = payload.old || {};
                        const associateId = newRow.associate_id || oldRow.associate_id;
                        if (!associateId) return;

                        const certsCacheKey = `comp_certs_cache_${associateId}`;
                        let cachedCerts = window.dynamicCompetencyState?.cache?.[certsCacheKey];
                        if (Array.isArray(cachedCerts)) {
                            if (payload.eventType === 'INSERT') {
                                cachedCerts.unshift(newRow);
                            } else if (payload.eventType === 'UPDATE') {
                                const idx = cachedCerts.findIndex(c => c.id == newRow.id);
                                if (idx >= 0) cachedCerts[idx] = Object.assign({}, cachedCerts[idx], newRow);
                                else cachedCerts.unshift(newRow);
                            } else if (payload.eventType === 'DELETE') {
                                cachedCerts = cachedCerts.filter(c => c.id != oldRow.id);
                                window.dynamicCompetencyState.cache[certsCacheKey] = cachedCerts;
                            }
                            try { sessionStorage.setItem(certsCacheKey, JSON.stringify(cachedCerts)); } catch (e) {}
                        }

                        if (typeof activeCompetencyEmpKey !== 'undefined' && activeCompetencyEmpKey === associateId) {
                            if (typeof renderCertificationsRoster === 'function') {
                                renderCertificationsRoster(false);
                            }
                        }
                    }
                )
                .subscribe();
        }

        // 3. Competency Evaluations Channel (Instant Score Update & Cache Invalidation)
        //    Also drives Training Active Deficit detection purely in-memory:
        //    when competency_assessments fires, recompute the employee's average score
        //    and INSERT/UPDATE/DELETE the synthetic need in trainingNeedsState — no DB trigger needed.
        if (!realtimeChannels.competency_evaluations) {
            // In-memory cache: empId -> { compId: score }
            window._liveCompScores = window._liveCompScores || {};

            realtimeChannels.competency_evaluations = supabaseClient
                .channel('realtime_competency_evals')
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'competency_evaluations' },
                    (payload) => {
                        const newRow = payload.new || {};
                        const empId = newRow.employee_id;
                        const compId = newRow.competency_id;
                        if (!empId || !compId) return;

                        // Invalidate competency caches in memory and sessionStorage
                        if (window.dynamicCompetencyState) {
                            window.dynamicCompetencyState.cache = {};
                        }
                        window._cachedEmpCompetencies = window._cachedEmpCompetencies || {};
                        delete window._cachedEmpCompetencies[empId.toLowerCase()];
                        try {
                            for (let i = sessionStorage.length - 1; i >= 0; i--) {
                                const k = sessionStorage.key(i);
                                if (k && (k.startsWith('comp_matrix_cache_') || k.startsWith('comp_emp_cache_'))) {
                                    sessionStorage.removeItem(k);
                                }
                            }
                        } catch (e) {}

                        const employees = window.dynamicCompetencyState?.employees || [];
                        const targetEmp = employees.find(e => e.id === empId);
                        if (targetEmp && targetEmp.scores) {
                            const newScore = parseFloat(newRow.score || 0);
                            targetEmp.scores[compId] = {
                                score: newScore,
                                formatted: newScore.toFixed(2),
                                isApplicable: true
                            };
                            if (typeof renderCompetencyMatrixTable === 'function') {
                                renderCompetencyMatrixTable();
                            }
                        }

                        if (typeof renderEmployeeOverviewCompetencies === 'function') {
                            renderEmployeeOverviewCompetencies(empId, true);
                        }

                        if (typeof activeCompetencyEmpKey !== 'undefined' && activeCompetencyEmpKey === empId) {
                            if (typeof renderSelectedEmployeeRadarView === 'function') {
                                renderSelectedEmployeeRadarView();
                            }
                        }
                    }
                )
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'competency_assessments' },
                    (payload) => {
                        const newRow = payload.new || {};
                        const oldRow = payload.old || {};
                        const empId = newRow.employee_id || oldRow.employee_id;
                        const compId = newRow.competency_id || oldRow.competency_id;
                        if (!empId) return;

                        // 1. Keep live per-employee score map up to date
                        window._liveCompScores[empId] = window._liveCompScores[empId] || {};
                        if (payload.eventType === 'DELETE') {
                            delete window._liveCompScores[empId][compId];
                        } else {
                            window._liveCompScores[empId][compId] = parseFloat(newRow.score || 0);
                        }

                        // 2. Compute new overall average for this employee
                        const scores = Object.values(window._liveCompScores[empId]);
                        if (scores.length === 0) return;
                        const avg = scores.reduce((s, v) => s + v, 0) / scores.length;
                        const overallScore = Math.round(avg * 100) / 100;
                        const TNA_THRESHOLD = 3.8;
                        const REQUIRED = 4.5;

                        if (typeof window.normalizeTrainingNeed !== 'function') return;

                        // 3. Resolve employee metadata from existing state
                        const empMeta = (window.trainingEmployeesState || []).find(
                            e => String(e.id).toLowerCase() === empId.toLowerCase()
                        ) || {};
                        const associateName = empMeta.full_name || empMeta.name || 'Associate';
                        const associateRole = empMeta.title || empMeta.role || 'Staff';
                        const dept          = empMeta.department || empMeta.dept || 'General';
                        const avatar        = empMeta.avatar_url || empMeta.avatar ||
                            'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150&auto=format&fit=crop&q=80';

                        // 4. Find any existing synthetic need for this employee (source_type = competency_gap, no target_goal_id)
                        const existingIdx = window.trainingNeedsState.findIndex(
                            n => String(n.employeeId || n.employee_id || '').toLowerCase() === empId.toLowerCase()
                                && (n.sourceType === 'competency_gap' || n.source_type === 'competency_gap')
                                && !n.targetGoalId && !n.target_goal_id
                        );

                        if (overallScore < TNA_THRESHOLD) {
                            // Low competency scores — low areas below threshold
                            const lowAreas = Object.entries(window._liveCompScores[empId])
                                .filter(([, s]) => s < TNA_THRESHOLD)
                                .map(([cId, s]) => `${cId} (${s.toFixed(1)}/${TNA_THRESHOLD})`);

                            const urgency = overallScore < 2.0 ? 'Critical' : overallScore < 3.5 ? 'High' : 'Medium';
                            const gap     = Math.round((overallScore - REQUIRED) * 100) / 100;

                            const syntheticNeed = window.normalizeTrainingNeed({
                                id: existingIdx >= 0
                                    ? window.trainingNeedsState[existingIdx].id
                                    : `need-live-${empId.replace(/[^a-z0-9]/gi, '')}`,
                                title: `Skill Gap & TNA Deficit: ${associateName}`,
                                source_type:  'competency_gap',
                                source_label: 'Skill Gap',
                                category:     'Associate Skill Gap',
                                dept,
                                employee_id:    empId,
                                associate_name: associateName,
                                associate_role: associateRole,
                                associate_avatar: avatar,
                                target_competency: lowAreas.length
                                    ? lowAreas.slice(0, 3).join(', ') + (lowAreas.length > 3 ? ` +${lowAreas.length - 3} more` : '')
                                    : 'Overall Hospitality Proficiency',
                                competency_key: 'general_tna',
                                current_score:  overallScore,
                                required_score: REQUIRED,
                                gap,
                                urgency,
                                status: existingIdx >= 0
                                    ? (window.trainingNeedsState[existingIdx].status || 'Identified')
                                    : 'Identified',
                                linked_program_id: existingIdx >= 0
                                    ? (window.trainingNeedsState[existingIdx].linkedProgramId || null)
                                    : null,
                                date_identified: existingIdx >= 0
                                    ? window.trainingNeedsState[existingIdx].dateIdentified
                                    : new Date().toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' }),
                                notes: `Assessed Overall Score: ${overallScore.toFixed(1)} / 5.0 (Benchmark: ${REQUIRED})\n` +
                                    lowAreas.map(a => `• ${a}`).join('\n')
                            });

                            if (existingIdx >= 0) {
                                window.trainingNeedsState[existingIdx] = Object.assign(
                                    {}, window.trainingNeedsState[existingIdx], syntheticNeed
                                );
                            } else {
                                window.trainingNeedsState.unshift(syntheticNeed);
                            }
                        } else {
                            // Score recovered above threshold — resolve the deficit
                            if (existingIdx >= 0) {
                                const existing = window.trainingNeedsState[existingIdx];
                                if (existing.status !== 'Resolved' && existing.status !== 'Completed') {
                                    window.trainingNeedsState[existingIdx] = Object.assign({}, existing, {
                                        status: 'Resolved',
                                        currentScore: overallScore,
                                        current_score: overallScore,
                                        gap: Math.round((overallScore - REQUIRED) * 100) / 100
                                    });
                                }
                            }
                        }

                        // 5. Re-render Training Needs panel instantly — no DB write, no trigger
                        if (typeof window.renderTrainingNeeds === 'function') window.renderTrainingNeeds();
                        if (typeof window.updateTrainingStats === 'function') window.updateTrainingStats();
                    }
                )
                .subscribe();
        }

        // 4. Social Recognition Feed & Gamified XP Ledger Channel
        if (!realtimeChannels.social_recognitions) {
            realtimeChannels.social_recognitions = supabaseClient
                .channel('realtime_social_recognitions')
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'social_recognitions' },
                    (payload) => {
                        const newRow = payload.new || {};
                        const oldRow = payload.old || {};
                        const postId = newRow.id || oldRow.id;
                        if (!postId) return;

                        if (payload.eventType === 'UPDATE' && newRow.id) {
                            if (typeof window.updatePostFromRealtime === 'function') {
                                window.updatePostFromRealtime(newRow);
                            } else if (typeof renderSocialFeed === 'function') {
                                renderSocialFeed();
                            }
                        } else if (payload.eventType === 'INSERT' && newRow.id) {
                            if (typeof window.addRealtimeRecognitionPost === 'function') {
                                window.addRealtimeRecognitionPost(newRow);
                            } else if (typeof loadSocialOverview === 'function') {
                                loadSocialOverview();
                            }
                        } else if (payload.eventType === 'DELETE' && oldRow.id) {
                            if (Array.isArray(window.socialFeedPostsState)) {
                                window.socialFeedPostsState = window.socialFeedPostsState.filter(p => p.id !== oldRow.id);
                                if (typeof renderSocialFeed === 'function') renderSocialFeed();
                            }
                        }
                    }
                )
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'xp_ledger' },
                    (payload) => {
                        const currentUserId = window.currentUser?.id || (window.activePersonaRole === 'Supervisor' ? 'emp-102' : 'emp-101');
                        
                        // Invalidate XP ledger cache for real-time update
                        window._cachedXpLedger = window._cachedXpLedger || {};
                        delete window._cachedXpLedger[currentUserId];
                        try {
                            sessionStorage.removeItem(`xp_ledger_cache_${currentUserId}`);
                        } catch(e) {}

                        if (typeof updateXpTrajectoryFromLedger === 'function') {
                            updateXpTrajectoryFromLedger(currentUserId, true);
                        }
                        if (typeof initSocialRecognition === 'function') {
                            initSocialRecognition();
                        }
                    }
                )
                .on(
                    'postgres_changes',
                    { event: 'INSERT', schema: 'public', table: 'shift_sentiments' },
                    (payload) => {
                        const newRow = payload.new || {};
                        if (newRow && newRow.id) {
                            if (typeof window.addRealtimeShiftSentiment === 'function') {
                                window.addRealtimeShiftSentiment(newRow);
                            }
                        }
                    }
                )
                .subscribe();
        }

        // 5. LMS Documents & Learning Prescriptions Realtime Channel
        if (!realtimeChannels.lms_documents) {
            realtimeChannels.lms_documents = supabaseClient
                .channel('realtime_lms_documents_hub')
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'lms_documents' },
                    (payload) => {
                        const newRow = payload.new || {};
                        const oldRow = payload.old || {};

                        // Clear local cache for instant freshness
                        try {
                            sessionStorage.removeItem('lms_documents_cache');
                        } catch (e) {}

                        // In-memory instant sync for window.dynamicLmsState.documents
                        if (window.dynamicLmsState && Array.isArray(window.dynamicLmsState.documents)) {
                            if (payload.eventType === 'INSERT' && newRow.id) {
                                const exists = window.dynamicLmsState.documents.some(d => d.id == newRow.id);
                                if (!exists) {
                                    window.dynamicLmsState.documents.unshift(newRow);
                                }
                            } else if (payload.eventType === 'UPDATE' && newRow.id) {
                                const idx = window.dynamicLmsState.documents.findIndex(d => d.id == newRow.id);
                                if (idx >= 0) {
                                    window.dynamicLmsState.documents[idx] = Object.assign({}, window.dynamicLmsState.documents[idx], newRow);
                                } else {
                                    window.dynamicLmsState.documents.unshift(newRow);
                                }
                            } else if (payload.eventType === 'DELETE' && oldRow.id) {
                                window.dynamicLmsState.documents = window.dynamicLmsState.documents.filter(d => d.id != oldRow.id);
                            }
                        }

                        // Trigger UI re-renders across LMS views
                        if (typeof renderLmsBooks === 'function') {
                            renderLmsBooks();
                        }
                        if (typeof fetchDynamicLmsDocuments === 'function') {
                            fetchDynamicLmsDocuments();
                        }
                        if (typeof fetchNeedsAnalysisData === 'function') {
                            fetchNeedsAnalysisData();
                        }
                    }
                )
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'lms_prescribed' },
                    (payload) => {
                        const newRow = payload.new || {};
                        const oldRow = payload.old || {};

                        try {
                            sessionStorage.removeItem('lms_prescribed_cache');
                        } catch (e) {}

                        // In-memory instant sync for window.dynamicLmsState.prescribed
                        if (window.dynamicLmsState && Array.isArray(window.dynamicLmsState.prescribed)) {
                            if (payload.eventType === 'INSERT' && newRow.id) {
                                const exists = window.dynamicLmsState.prescribed.some(p => p.id == newRow.id);
                                if (!exists) {
                                    window.dynamicLmsState.prescribed.unshift(newRow);
                                }
                            } else if (payload.eventType === 'UPDATE' && newRow.id) {
                                const idx = window.dynamicLmsState.prescribed.findIndex(p => p.id == newRow.id);
                                if (idx >= 0) {
                                    window.dynamicLmsState.prescribed[idx] = Object.assign({}, window.dynamicLmsState.prescribed[idx], newRow);
                                } else {
                                    window.dynamicLmsState.prescribed.unshift(newRow);
                                }
                            } else if (payload.eventType === 'DELETE' && oldRow.id) {
                                window.dynamicLmsState.prescribed = window.dynamicLmsState.prescribed.filter(p => p.id != oldRow.id);
                            }
                        }

                        // Re-render bookshelf and prescribed status
                        if (typeof renderLmsBooks === 'function') {
                            renderLmsBooks();
                        }
                        if (typeof fetchPrescribedLms === 'function') {
                            fetchPrescribedLms();
                        }
                        if (typeof fetchNeedsAnalysisData === 'function') {
                            fetchNeedsAnalysisData();
                        }

                        // Re-render Department Execution Matrix when LMS prescription changes
                        if (typeof fetchAndRenderDepartmentExecutionMatrix === 'function') {
                            fetchAndRenderDepartmentExecutionMatrix(true);
                        }
                    }
                )
                .subscribe();
        }

        // 6. Succession Planning & Department Execution Matrix Realtime Channel
        if (!realtimeChannels.succession_and_matrix) {
            realtimeChannels.succession_and_matrix = supabaseClient
                .channel('realtime_succession_and_matrix_hub')
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'succession_candidates' },
                    (payload) => {
                        if (typeof fetchAndRenderDepartmentExecutionMatrix === 'function') {
                            fetchAndRenderDepartmentExecutionMatrix(true);
                        }
                        if (typeof loadSuccessionOverview === 'function') {
                            loadSuccessionOverview();
                        }
                        if (typeof window.scheduleSuccessionBackgroundSync === 'function') {
                            window.scheduleSuccessionBackgroundSync('succession_candidates');
                        }
                    }
                )
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'succession_positions' },
                    (payload) => {
                        if (typeof fetchAndRenderDepartmentExecutionMatrix === 'function') {
                            fetchAndRenderDepartmentExecutionMatrix(true);
                        }
                        if (typeof window.scheduleSuccessionBackgroundSync === 'function') {
                            window.scheduleSuccessionBackgroundSync('succession_positions');
                        }
                    }
                )
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'employees' },
                    (payload) => {
                        if (typeof fetchAndRenderDepartmentExecutionMatrix === 'function') {
                            fetchAndRenderDepartmentExecutionMatrix(true);
                        }
                    }
                )
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'departments' },
                    (payload) => {
                        if (typeof fetchAndRenderDepartmentExecutionMatrix === 'function') {
                            fetchAndRenderDepartmentExecutionMatrix(true);
                        }
                    }
                )
                .subscribe((status) => {
                    if (status === 'SUBSCRIBED') {
                        console.log('[Supabase Realtime] Succession & Department Matrix channel active');
                    }
                });
        }

        // 7. Training Operations & Deficits Realtime Channel
        if (!realtimeChannels.training_management) {
            realtimeChannels.training_management = supabaseClient
                .channel('realtime_training_ops_hub')
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'training_needs' },
                    (payload) => {
                        const newRow = payload.new || {};
                        const oldRow = payload.old || {};
                        console.log('[Supabase Realtime] training_needs event:', payload.eventType);

                        if (Array.isArray(window.trainingNeedsState) && typeof window.normalizeTrainingNeed === 'function') {
                            if (payload.eventType === 'INSERT' && newRow.id) {
                                const norm = window.normalizeTrainingNeed(newRow);
                                if (!window.trainingNeedsState.some(n => n.id === norm.id)) {
                                    window.trainingNeedsState.unshift(norm);
                                }
                            } else if (payload.eventType === 'UPDATE' && newRow.id) {
                                const norm = window.normalizeTrainingNeed(newRow);
                                const idx = window.trainingNeedsState.findIndex(n => n.id === norm.id);
                                if (idx >= 0) window.trainingNeedsState[idx] = Object.assign({}, window.trainingNeedsState[idx], norm);
                                else window.trainingNeedsState.unshift(norm);
                            } else if (payload.eventType === 'DELETE' && oldRow.id) {
                                window.trainingNeedsState = window.trainingNeedsState.filter(n => n.id != oldRow.id);
                            }
                        }

                        if (typeof window.renderTrainingNeeds === 'function') {
                            window.renderTrainingNeeds();
                        }
                        if (typeof window.updateTrainingStats === 'function') {
                            window.updateTrainingStats();
                        }
                        if (typeof window.appendLiveAuditLog === 'function') {
                            window.appendLiveAuditLog(
                                'Training Management',
                                'TRAINING_NEED_MUTATION',
                                'Training Operations',
                                `Training need record ${newRow.title || oldRow.title || ''} synchronized.`,
                                'SUCCESS'
                            );
                        }
                    }
                )
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'training_programs' },
                    (payload) => {
                        if (typeof window.renderTrainingPrograms === 'function') {
                            window.renderTrainingPrograms();
                        }
                    }
                )
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'training_sessions' },
                    (payload) => {
                        if (typeof window.renderTrainingSessions === 'function') {
                            window.renderTrainingSessions();
                        }
                        if (typeof window.renderAttendanceConsole === 'function') {
                            window.renderAttendanceConsole();
                        }
                        if (typeof window.updateTrainingStats === 'function') {
                            window.updateTrainingStats();
                        }
                    }
                )
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'training_evaluations' },
                    (payload) => {
                        if (typeof window.renderTrainingResults === 'function') {
                            window.renderTrainingResults();
                        }
                        if (typeof window.updateTrainingStats === 'function') {
                            window.updateTrainingStats();
                        }
                        if (typeof window.renderCertsTable === 'function') {
                            window.renderCertsTable();
                        }
                        if (typeof window.scheduleSuccessionBackgroundSync === 'function') {
                            window.scheduleSuccessionBackgroundSync('training_evaluations');
                        }
                    }
                )
                .subscribe((status) => {
                    if (status === 'SUBSCRIBED') {
                        console.log('[Supabase Realtime] Training Operations Hub channel active');
                    }
                });
        }

        // 8. Notifications & Alerts Realtime Channel
        if (!realtimeChannels.notifications) {
            realtimeChannels.notifications = supabaseClient
                .channel('realtime_notifications_hub')
                .on(
                    'postgres_changes',
                    { event: '*', schema: 'public', table: 'notifications' },
                    (payload) => {
                        console.log('[Supabase Realtime] notifications event:', payload.eventType);
                        if (typeof window.handleRealtimeNotification === 'function') {
                            window.handleRealtimeNotification(payload);
                        }
                    }
                )
                .subscribe((status) => {
                    if (status === 'SUBSCRIBED') {
                        console.log('[Supabase Realtime] Notifications & Alerts channel active');
                    }
                });
        }

    } catch (e) {
        console.warn('[Supabase Realtime] Error initializing channels:', e);
    }
}
window.initSupabaseRealtime = initSupabaseRealtime;

// Auto-boot Realtime
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(initSupabaseRealtime, 200));
} else {
    setTimeout(initSupabaseRealtime, 200);
}

// Seed _liveCompScores from already-loaded competency state so the first
// competency_assessments realtime event has a full baseline to average against.
function seedLiveCompScores() {
    window._liveCompScores = window._liveCompScores || {};
    const employees = window.dynamicCompetencyState?.employees || [];
    employees.forEach(emp => {
        if (!emp.id || !emp.scores) return;
        window._liveCompScores[emp.id] = window._liveCompScores[emp.id] || {};
        Object.entries(emp.scores).forEach(([compId, val]) => {
            const s = typeof val === 'object' ? (val.score ?? 0) : val;
            window._liveCompScores[emp.id][compId] = parseFloat(s) || 0;
        });
    });
}
window.seedLiveCompScores = seedLiveCompScores;

// Run after competency state is populated (competencies.js fires this event)
document.addEventListener('competencyStateReady', seedLiveCompScores);
// Fallback: seed 2s after DOM ready in case the event already fired
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(seedLiveCompScores, 2000));
} else {
    setTimeout(seedLiveCompScores, 2000);
}
