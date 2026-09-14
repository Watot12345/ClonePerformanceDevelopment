/**
 * js/performance/stage4_appraisal.js
 * Stage 4: Formal Performance Appraisal
 */

// ============================================================================
// Unified Helper Functions
// ============================================================================

function getEmployeeTaskStats(empId) {
    const empGoals = (window.dbGoals || []).filter(g => (g.status === 'Approved' || g.status === 'Done' || g.status === 'In Progress' || g.status === 'Completed') && isSameEmployee(g.employee_id, empId));
    let total = 0;
    let completed = 0;
    empGoals.forEach(g => {
        (g.tasks || []).forEach(t => {
            total++;
            if (t.status === 'completed') completed++;
        });
    });
    return {
        total,
        completed,
        allDone: total > 0 && completed === total,
        progressPct: total > 0 ? Math.round((completed / total) * 100) : 0,
        goals: empGoals
    };
}
window.getEmployeeTaskStats = getEmployeeTaskStats;

/**
 * Resolves evaluation data (record, supervisor score, self score) for an employee.
 */
function getEmployeeEvalData(emp, goalId = null) {
    if (!emp) return { record: null, supervisorRating: null, selfRating: null, isRated: false };
    const empId = typeof emp === 'object' ? emp.id : emp;
    const activeGoal = goalId ? { id: goalId } : (typeof getEmployeeActiveGoal === 'function' ? getEmployeeActiveGoal(empId) : null);
    const activeGoalId = activeGoal ? activeGoal.id : null;

    let evalRec = typeof getEmployeeGoalEvaluation === 'function'
        ? getEmployeeGoalEvaluation(empId, activeGoalId)
        : (activeGoalId ? getDbEvaluations().find(ev => isSameEmployee(ev.employee_id, empId) && String(ev.goal_id) === String(activeGoalId)) : null);

    if (!evalRec && emp && typeof emp === 'object' && emp.evaluationRecord) {
        evalRec = emp.evaluationRecord;
    }

    const rawSup = evalRec && evalRec.supervisor_rating !== undefined && evalRec.supervisor_rating !== null && parseFloat(evalRec.supervisor_rating) > 0
        ? parseFloat(evalRec.supervisor_rating)
        : ((emp && typeof emp === 'object' && emp.supervisorRating && parseFloat(emp.supervisorRating) > 0) ? parseFloat(emp.supervisorRating) : null);

    const rawSelf = evalRec && evalRec.self_evaluation !== undefined && evalRec.self_evaluation !== null && parseFloat(evalRec.self_evaluation) > 0
        ? parseFloat(evalRec.self_evaluation)
        : ((emp && typeof emp === 'object' && emp.selfRating && parseFloat(emp.selfRating) > 0) ? parseFloat(emp.selfRating) : null);

    return {
        record: evalRec || (emp && typeof emp === 'object' ? emp.evaluationRecord : null),
        supervisorRating: rawSup,
        selfRating: rawSelf,
        isRated: rawSup !== null && rawSup > 0
    };
}
window.getEmployeeEvalData = getEmployeeEvalData;

/**
 * Returns tier label, benchmark status, and style classes for a score.
 */
function getTierInfo(score) {
    const s = parseFloat(score);
    if (isNaN(s) || s <= 0) {
        return { label: 'Pending Evaluation', isBelow: false, badgeClass: 'bg-amber-50 text-amber-700 border-amber-200' };
    }
    if (s >= 4.5) return { label: 'Master Tier', isBelow: false, badgeClass: 'bg-emerald-50 text-emerald-700 border-emerald-200' };
    if (s >= 3.5) return { label: 'Advanced Tier', isBelow: false, badgeClass: 'bg-blue-50 text-blue-700 border-blue-200' };
    if (s >= 3.0) return { label: 'Proficient', isBelow: false, badgeClass: 'bg-amber-50 text-amber-700 border-amber-200' };
    return { label: 'Below 3.0 Benchmark', isBelow: true, badgeClass: 'bg-rose-50 text-rose-700 border-rose-200' };
}
window.getTierInfo = getTierInfo;

/**
 * Determines action lock status and button labels for appraisal operations.
 */
function getAppraisalActionState(empId, allTasksDone, goalId = null) {
    const targetGoal = goalId ? { id: goalId } : (typeof getEmployeeActiveGoal === 'function' ? getEmployeeActiveGoal(empId) : null);
    const targetGoalId = targetGoal ? targetGoal.id : null;

    const isGoalFailed = isEmployeeGoalFailed(empId, targetGoalId);
    const retryCount = getEmployeeRetryCount(empId, targetGoalId);
    const inTraining = isEmployeeInTraining(empId, targetGoalId);
    const isScored = isEmployeeTrainingScored(empId, targetGoalId);

    if (isGoalFailed || retryCount >= 4) {
        return { state: 'locked_failed', label: 'Locked (Goal Failed)', canOpen: false, reason: 'Goal Failed. Performance appraisal locked - final score is in Phase 7.' };
    }
    if (inTraining && !isScored) {
        return { state: 'in_training', label: 'In Training (Locked)', canOpen: false, reason: 'Associate is currently in mandatory formal training. Appraisal locked until training score is recorded.' };
    }
    if (inTraining && isScored) {
        return { state: 'post_training', label: 'Evaluate (After Training)', canOpen: true, isPostTraining: true, reason: 'Training score recorded! Ready for re-evaluation.' };
    }
    if (!allTasksDone) {
        return { state: 'tasks_incomplete', label: 'Tasks Incomplete', canOpen: false, reason: 'All monitoring tasks in Stage 3 Continuous Monitoring must be completed first.' };
    }
    return { state: 'ready', label: 'Conduct Appraisal', canOpen: true, isPostTraining: false, reason: 'Ready for formal appraisal.' };
}
window.getAppraisalActionState = getAppraisalActionState;

// ============================================================================
// Roster Table & Search
// ============================================================================

function renderEvaluationRosterTable() {
    const tbody = document.getElementById('eval-roster-tbody') || document.getElementById('perf-evaluation-roster-tbody');
    if (!tbody) return;

    if (!window.perfRoster || window.perfRoster.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" class="py-12 text-center text-slate-400">
                    <div class="w-12 h-12 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center text-lg mx-auto font-bold mb-2">
                        <i class="fas fa-clipboard-user"></i>
                    </div>
                    <p class="text-sm font-semibold text-slate-600">No Associates in Appraisal Roster</p>
                    <p class="text-xs text-slate-400 mt-1">Associates with active objectives in Stage 1 &amp; 2 will appear here for performance appraisals.</p>
                </td>
            </tr>
        `;
        return;
    }

    const searchInput = document.getElementById('search-eval-emp') || document.getElementById('eval-search-input');
    const searchQuery = ((typeof window.evalSearchQuery !== 'undefined' && window.evalSearchQuery !== null) ? window.evalSearchQuery : (searchInput ? searchInput.value : '')).toLowerCase().trim();

    let roster = (window.perfRoster || []).filter(emp => {
        return typeof employeeHasApprovedGoal === 'function' ? employeeHasApprovedGoal(emp) : false;
    });

    if (searchQuery) {
        roster = roster.filter(emp => {
            const name = (emp.name || '').toLowerCase();
            const pos = (emp.position || '').toLowerCase();
            const dept = (emp.department || '').toLowerCase();
            const id = (emp.id || '').toLowerCase();
            return name.includes(searchQuery) || pos.includes(searchQuery) || dept.includes(searchQuery) || id.includes(searchQuery);
        });
    }

    if (roster.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" class="py-12 text-center text-slate-400">
                    <div class="w-12 h-12 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center text-lg mx-auto font-bold mb-2">
                        <i class="fas fa-clipboard-user"></i>
                    </div>
                    <p class="text-sm font-semibold text-slate-600">No Associates with Active Approved Objectives</p>
                    <p class="text-xs text-slate-400 mt-1">Associates will appear in Appraisal Roster once their goals have status 'Approved' in Stage 1 &amp; 2.</p>
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = roster.map((emp, idx) => {
        const evalData = getEmployeeEvalData(emp);
        const taskStats = getEmployeeTaskStats(emp.id);
        const actionState = getAppraisalActionState(emp.id, taskStats.allDone);
        const tier = getTierInfo(evalData.supervisorRating);

        const selfScoreDisplay = (evalData.selfRating !== null && evalData.selfRating > 0)
            ? `${evalData.selfRating.toFixed(2)} / 5.0`
            : `<span class="text-slate-400 italic">Pending</span>`;

        let tierBadge = '<span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">Awaiting Rating</span>';
        if (!taskStats.allDone && !evalData.isRated) {
            tierBadge = `<span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600 border border-slate-200">Tasks Incomplete (${taskStats.completed}/${taskStats.total})</span>`;
        } else if (evalData.isRated) {
            tierBadge = tier.isBelow
                ? `<span class="px-2.5 py-1 rounded-full text-[10px] font-bold ${tier.badgeClass} border inline-flex items-center space-x-1"><i class="fas fa-triangle-exclamation text-[9px]"></i><span>Below 3.0</span></span>`
                : `<span class="px-2.5 py-1 rounded-full text-[10px] font-bold ${tier.badgeClass} border">${tier.label}</span>`;
        }

        let actionBtnHtml = '';
        if (!actionState.canOpen) {
            actionBtnHtml = `
                <button disabled title="${actionState.reason}" class="px-3 py-1.5 bg-slate-100 text-slate-400 border border-slate-200 text-xs font-bold rounded-xl shadow-none cursor-not-allowed inline-flex items-center space-x-1.5">
                    <i class="fas fa-lock text-[10px]"></i>
                    <span>${actionState.label}</span>
                </button>
            `;
        } else if (actionState.isPostTraining) {
            actionBtnHtml = `
                <button onclick="openAppraisalModal('${emp.id}', true)" title="${actionState.reason}" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl shadow-xs transition inline-flex items-center space-x-1.5">
                    <i class="fas fa-star-half-stroke text-[10px]"></i>
                    <span>${actionState.label}</span>
                </button>
            `;
        } else if (evalData.isRated) {
            actionBtnHtml = `
                <button onclick="showEmployeeEvalDetail('${emp.id}', true)" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl border border-slate-200 transition inline-flex items-center space-x-1.5">
                    <i class="fas fa-eye text-[10px]"></i>
                    <span>View Record</span>
                </button>
                <button onclick="openAppraisalModal('${emp.id}')" class="px-3 py-1.5 bg-white hover:bg-slate-50 text-primary text-xs font-bold rounded-xl border border-primary/30 transition inline-flex items-center space-x-1.5">
                    <i class="fas fa-pen text-[10px]"></i>
                    <span>Re-Evaluate</span>
                </button>
            `;
        } else {
            actionBtnHtml = `
                <button onclick="openAppraisalModal('${emp.id}')" class="px-3 py-1.5 btn-primary text-xs font-bold rounded-xl shadow-xs transition inline-flex items-center space-x-1.5">
                    <i class="fas fa-star-half-stroke text-[10px]"></i>
                    <span>Conduct Appraisal</span>
                </button>
            `;
        }

        const rowBg = idx % 2 === 0 ? 'bg-white' : 'bg-slate-50/40';

        return `
            <tr class="${rowBg} hover:bg-brand-canvas transition border-b border-slate-100 text-xs">
                <td class="px-3 py-4 text-center text-slate-400 font-mono text-[11px]">${idx + 1}</td>
                <td class="px-5 py-4">
                    <div class="flex items-center space-x-3">
                        <div class="w-9 h-9 rounded-xl bg-primary/10 text-primary flex items-center justify-center font-bold text-xs shrink-0">
                            ${emp.avatar || emp.name.charAt(0)}
                        </div>
                        <div>
                            <div class="font-bold text-slate-900 text-xs hover:text-primary cursor-pointer" onclick="showEmployeeEvalDetail('${emp.id}', true)">${emp.name}</div>
                            <div class="text-[10px] text-slate-400 font-mono">${emp.position}</div>
                        </div>
                    </div>
                </td>
                <td class="px-5 py-4">
                    <span class="text-xs font-medium text-slate-600">${emp.department}</span>
                </td>
                <td class="px-5 py-4">
                    <div class="flex items-center justify-between text-[11px] mb-1">
                        <span class="font-bold text-slate-700">${taskStats.progressPct}%</span>
                        <span class="text-slate-400 text-[10px]">${taskStats.total > 0 ? taskStats.completed + '/' + taskStats.total + ' Tasks' : 'No Tasks'}</span>
                    </div>
                    <div class="w-24 bg-slate-100 h-1.5 rounded-full overflow-hidden">
                        <div class="h-full rounded-full transition-all duration-300 ${taskStats.progressPct >= 100 ? 'bg-emerald-500' : 'bg-primary'}" style="width: ${taskStats.progressPct}%"></div>
                    </div>
                </td>
                <td class="px-5 py-4">
                    <span class="text-xs font-mono text-slate-700">${selfScoreDisplay}</span>
                </td>
                <td class="px-5 py-4">
                    <span class="text-xs font-mono font-bold ${tier.isBelow ? 'text-rose-600' : 'text-slate-900'}">${evalData.isRated ? evalData.supervisorRating.toFixed(2) + ' / 5.0' : '<span class="text-slate-400 font-normal italic">--</span>'}</span>
                </td>
                <td class="px-5 py-4 text-center">
                    ${tierBadge}
                </td>
                <td class="px-5 py-4 text-right">
                    <div class="flex items-center justify-end space-x-2">
                        ${actionBtnHtml}
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}
window.renderEvaluationRosterTable = renderEvaluationRosterTable;

window.onEvalEmployeeSearch = function(query) {
    window.evalSearchQuery = query;
    renderEvaluationRosterTable();
};

function filterEvaluationRoster() {
    const search = (document.getElementById('search-eval-emp')?.value || document.getElementById('eval-search-input')?.value || '').toLowerCase();
    const dept = document.getElementById('eval-dept-filter')?.value || 'all';

    const rows = document.querySelectorAll('#eval-roster-tbody tr, #perf-eval-roster-tbody tr');
    rows.forEach(r => {
        const text = r.textContent.toLowerCase();
        const matchesSearch = !search || text.includes(search);
        const matchesDept = dept === 'all' || text.includes(dept.toLowerCase());
        r.style.display = (matchesSearch && matchesDept) ? '' : 'none';
    });
}
window.filterEvaluationRoster = filterEvaluationRoster;

// ============================================================================
// Evaluation Detail View Modal
// ============================================================================

function showEmployeeEvalDetail(empId, openModalImmediately = false) {
    const emp = (window.perfRoster || []).find(e => isSameEmployee(e.id, empId));
    if (!emp) return;

    window.selectedEvalEmpId = emp.id;

    // Header Details
    const nameEl = document.getElementById('eval-detail-emp-name') || document.getElementById('eval-modal-emp-title');
    const posEl = document.getElementById('eval-detail-emp-pos') || document.getElementById('eval-modal-emp-subtitle');
    const idEl = document.getElementById('eval-detail-emp-id');
    const avatarEl = document.getElementById('eval-detail-emp-avatar');
    if (nameEl) nameEl.textContent = `Formal Appraisal: ${emp.name}`;
    if (posEl) posEl.textContent = `${emp.position} · ${emp.department}`;
    if (idEl) idEl.textContent = `EMP #${emp.id}`;
    if (avatarEl) avatarEl.textContent = emp.avatar || emp.name.charAt(0);

    const evalData = getEmployeeEvalData(emp);
    const taskStats = getEmployeeTaskStats(emp.id);
    const actionState = getAppraisalActionState(emp.id, taskStats.allDone);
    const tier = getTierInfo(evalData.supervisorRating);

    const statusBadge = document.getElementById('eval-detail-status-badge') || document.getElementById('eval-modal-status-badge');
    if (statusBadge) {
        if (evalData.isRated) {
            statusBadge.className = 'px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200';
            statusBadge.textContent = '✓ Formal Appraisal Completed';
        } else {
            statusBadge.className = 'px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-200';
            statusBadge.textContent = 'Pending Formal Appraisal';
        }
    }

    // Monitoring Progress Summary in Detail
    const monProgressEl = document.getElementById('eval-detail-mon-progress');
    const taskCountEl = document.getElementById('eval-detail-task-count');
    if (monProgressEl) monProgressEl.innerHTML = `${taskStats.progressPct}% <span class="text-sm font-normal text-slate-400">Shift Execution</span>`;
    if (taskCountEl) taskCountEl.textContent = `${taskStats.completed} of ${taskStats.total} monitoring tasks verified in database`;

    // Render Approved Goals & Tasks in Evaluation Detail
    const goalsContainer = document.getElementById('eval-detail-goals-container');
    if (goalsContainer) {
        if (taskStats.goals.length === 0) {
            goalsContainer.innerHTML = `<div class="p-6 text-center text-slate-400 italic bg-white rounded-2xl border border-slate-200">No approved objectives found in database for this associate.</div>`;
        } else {
            goalsContainer.innerHTML = taskStats.goals.map((g, idx) => {
                const tasks = g.tasks || [];
                const done = tasks.filter(t => t.status === 'completed').length;
                const total = tasks.length;
                const pct = total > 0 ? Math.round((done / total) * 100) : 0;
                return `
                    <div class="p-3 bg-white rounded-2xl border border-slate-200 space-y-1.5 text-xs shadow-2xs">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-slate-900 line-clamp-1">${idx + 1}. ${g.title}</span>
                            <span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-primary/10 text-primary">${g.weight ? g.weight.split(' ')[0] : '25%'}</span>
                        </div>
                        <p class="text-[10px] text-slate-500 font-mono font-bold">${g.target_metric}</p>
                        <div class="space-y-1 pt-1">
                            <div class="flex items-center justify-between text-[9px] font-bold text-slate-600">
                                <span>Tasks: ${done}/${total} Done</span>
                                <span class="text-primary">${pct}%</span>
                            </div>
                            <div class="w-full bg-slate-100 h-1.5 rounded-full overflow-hidden">
                                <div class="${pct >= 100 ? 'bg-emerald-500' : 'bg-primary'} h-1.5 rounded-full" style="width: ${pct}%"></div>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }
    }

    // Configure Open Appraisal Button
    const btnOpenAppraisal = document.getElementById('btn-open-eval-appraisal');
    if (btnOpenAppraisal) {
        if (!actionState.canOpen) {
            btnOpenAppraisal.disabled = true;
            btnOpenAppraisal.className = 'btn-secondary px-4 py-2 text-xs font-bold bg-slate-100 text-slate-400 border border-slate-200 cursor-not-allowed shadow-none inline-flex items-center space-x-1.5';
            btnOpenAppraisal.innerHTML = `<i class="fas fa-lock mr-1"></i><span>${actionState.label}</span>`;
            btnOpenAppraisal.title = actionState.reason;
            btnOpenAppraisal.onclick = null;
        } else if (actionState.isPostTraining) {
            btnOpenAppraisal.disabled = false;
            btnOpenAppraisal.className = 'btn-primary px-4 py-2 text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white shadow-xs inline-flex items-center space-x-1.5';
            btnOpenAppraisal.innerHTML = `<i class="fas fa-star-half-stroke mr-1"></i><span>${actionState.label}</span>`;
            btnOpenAppraisal.title = actionState.reason;
            btnOpenAppraisal.onclick = () => openAppraisalModal(emp.id, true);
        } else {
            btnOpenAppraisal.disabled = false;
            btnOpenAppraisal.className = 'btn-primary px-4 py-2 text-xs font-bold shadow-xs inline-flex items-center space-x-1.5';
            btnOpenAppraisal.innerHTML = `<i class="fas fa-star-half-stroke mr-1"></i><span>${evalData.isRated ? 'Re-Evaluate Appraisal' : 'Open Appraisal Form'}</span>`;
            btnOpenAppraisal.title = 'Open Appraisal Form';
            btnOpenAppraisal.onclick = () => openAppraisalModal(emp.id);
        }
    }

    // Supervisor Assessment Score from Database
    const superScoreEl = document.getElementById('eval-detail-super-score');
    if (superScoreEl) {
        superScoreEl.innerHTML = evalData.isRated
            ? `${evalData.supervisorRating.toFixed(2)} <span class="text-sm font-normal text-slate-400">/ 5.0 (${tier.label})</span>`
            : `0.00 <span class="text-sm font-normal text-slate-400">/ 5.0 (Pending Evaluation)</span>`;
    }

    const tierBadgeContainer = document.getElementById('eval-detail-tier-badge-container');
    if (tierBadgeContainer) {
        if (evalData.isRated) {
            tierBadgeContainer.innerHTML = `<span class="px-3 py-1 rounded-xl text-xs font-bold ${tier.badgeClass} border inline-flex items-center space-x-1.5"><i class="fas ${tier.isBelow ? 'fa-triangle-exclamation' : 'fa-award'}"></i><span>${tier.label}</span></span>`;
        } else {
            tierBadgeContainer.innerHTML = `<span class="px-3 py-1 rounded-xl text-xs font-bold bg-amber-100 text-amber-800 border border-amber-200">Evaluation Pending</span>`;
        }
    }

    // Evaluated Criteria Breakdown from Database
    const criteriaBreakdownEl = document.getElementById('eval-detail-criteria-breakdown');
    if (criteriaBreakdownEl) {
        const list = evalData.record && Array.isArray(evalData.record.criteria_scores) && evalData.record.criteria_scores.length > 0 ? evalData.record.criteria_scores : [];
        if (list.length > 0) {
            criteriaBreakdownEl.innerHTML = list.map((c, i) => `
                <div class="p-3 bg-white rounded-xl border ${c.rating < 3.0 ? 'border-rose-200 bg-rose-50/20' : 'border-slate-200'} space-y-1 shadow-2xs">
                    <div class="flex justify-between items-center text-xs flex-wrap gap-1">
                        <span class="font-bold text-slate-900">${i + 1}. ${c.title} <span class="text-[10px] text-slate-400 font-normal">(${c.metric || 'Standard Benchmark'})</span></span>
                        <span class="font-bold font-mono px-2 py-0.5 rounded text-[11px] ${c.rating < 3.0 ? 'bg-rose-100 text-rose-800 border border-rose-200' : 'bg-emerald-100 text-emerald-800'}">
                            ${c.rating < 3.0 ? '<i class="fas fa-triangle-exclamation mr-1 text-rose-600"></i>' : ''}<i class="fas fa-star text-amber-500 mr-0.5 text-[10px]"></i>${parseFloat(c.rating || 0).toFixed(1)} / 5.0 (${c.weight || '33'}% wt)
                        </span>
                    </div>
                    ${c.rationale ? `<p class="text-[11px] text-slate-600 italic pl-2 border-l-2 ${c.rating < 3.0 ? 'border-rose-300' : 'border-purple-300'}">"${c.rationale}"</p>` : ''}
                </div>
            `).join('');
        } else {
            criteriaBreakdownEl.innerHTML = `<p class="text-slate-400 italic text-[11px] p-3 bg-white rounded-xl border border-slate-200">No specific criteria rubric recorded in database yet.</p>`;
        }
    }

    // Supervisor Notes & Recommendation from Database
    const superRecEl = document.getElementById('eval-detail-super-recommendation');
    if (superRecEl) {
        superRecEl.innerHTML = (evalData.record && evalData.record.supervisor_notes)
            ? `<p class="text-slate-800 leading-relaxed italic">"${evalData.record.supervisor_notes}"</p>`
            : `<p class="text-slate-400 italic">No formal supervisor endorsement notes entered in database yet.</p>`;
    }

    if (openModalImmediately) {
        openModal('modal-view-appraisal');
    }
}
window.showEmployeeEvalDetail = showEmployeeEvalDetail;

function hideEmployeeEvalDetail() {
    closeModal('modal-view-appraisal');
}
window.hideEmployeeEvalDetail = hideEmployeeEvalDetail;

// ============================================================================
// Formal Appraisal Modal & Form Submission
// ============================================================================

window.pendingEvalEmpId = null;

function openAppraisalModal(empId, isPostTraining = false) {
    const emp = (window.perfRoster || []).find(e => isSameEmployee(e.id, empId));
    if (!emp) return;

    window.selectedEvalEmpId = emp.id;

    const inTraining = isEmployeeInTraining(emp.id);
    const isScored = isEmployeeTrainingScored(emp.id);
    if (inTraining && !isScored && !isPostTraining) {
        if (typeof showToast === 'function') {
            showToast(` Cannot evaluate ${emp.name}: Associate is currently enrolled in Mandatory Formal Training. Re-evaluation is locked until training score is recorded.`, 'warning');
        }
        return;
    }

    const taskStats = getEmployeeTaskStats(emp.id);
    if (!taskStats.allDone && !isPostTraining && !inTraining) {
        if (typeof showToast === 'function') {
            showToast(` Cannot evaluate ${emp.name}: Monitoring tasks are still not done (${taskStats.completed}/${taskStats.total} completed). Complete all tasks in Stage 3 Continuous Monitoring first.`, 'warning');
        }
        return;
    }

    openAppraisalModalInternal(emp.id, isPostTraining);
}
window.openAppraisalModal = openAppraisalModal;

function proceedToAppraisalModal() {
    closeModal('modal-eval-no-tasks-confirm');
    if (window.pendingEvalEmpId) {
        openAppraisalModalInternal(window.pendingEvalEmpId);
    }
}
window.proceedToAppraisalModal = proceedToAppraisalModal;

function openAppraisalModalInternal(empId) {
    const emp = window.perfRoster.find(e => e.id === empId) || window.perfRoster[0];
    if (!emp) return;

    window.selectedEvalEmpId = emp.id;
    const targetInput = document.getElementById('eval-target-emp-id');
    const targetGoalInput = document.getElementById('eval-target-goal-id');
    const titleEl = document.getElementById('modal-eval-emp-title');
    if (targetInput) targetInput.value = emp.id;
    if (titleEl) titleEl.textContent = `Appraisal Review: ${emp.name} (${emp.position})`;

    const criteriaContainer = document.getElementById('appraisal-criteria-container');
    if (!criteriaContainer) return;

    // Load approved goals and DB evaluation criteria
    const evalData = getEmployeeEvalData(emp);
    const empGoals = (window.dbGoals || []).filter(g => g.status === 'Approved' && isSameEmployee(g.employee_id, emp.id));
    const targetGoal = empGoals[0];
    if (targetGoalInput) targetGoalInput.value = targetGoal ? targetGoal.id : '';

    let criteriaList = [];
    if (evalData.record && Array.isArray(evalData.record.criteria_scores) && evalData.record.criteria_scores.length > 0) {
        criteriaList = evalData.record.criteria_scores.map(c => ({
            title: c.title,
            metric: c.metric,
            weight: c.weight || 30,
            initialRating: c.rating || 4.5,
            rationale: c.rationale || 'Demonstrated high consistency in achieving target deliverables.'
        }));
    } else if (empGoals.length > 0) {
        criteriaList = empGoals.map(g => ({
            title: g.title,
            metric: g.target_metric || '100% SOP Compliance',
            weight: parseInt(g.weight || '30', 10) || 30,
            initialRating: 3.0,
            rationale: ''
        }));
    } else {
        criteriaList = [
            { title: 'Operational Excellence & Protocol Adherence', metric: '100% SOP Compliance', weight: 40, initialRating: 3.0, rationale: '' },
            { title: 'Guest Satisfaction & Service Speed', metric: 'CSAT >= 95%', weight: 30, initialRating: 3.0, rationale: '' },
            { title: 'Teamwork, Conflict De-escalation & Mentorship', metric: 'Zero Unresolved Escalations', weight: 30, initialRating: 3.0, rationale: '' }
        ];
    }

    criteriaContainer.innerHTML = criteriaList.map((c, idx) => `
        <div class="p-4 bg-brand-canvas rounded-2xl border border-brand-border space-y-2.5">
            <div class="flex justify-between items-center font-semibold text-xs">
                <span class="text-slate-900">${idx + 1}. ${c.title} <span class="text-primary font-bold">(Weight: ${c.weight}%)</span></span>
                <span id="criteria-val-display-${idx}" class="text-primary font-mono font-bold">${c.initialRating} / 5.0</span>
            </div>
            <p class="text-[10px] text-slate-500 font-medium font-mono">Target Metric: ${c.metric}</p>
            <input type="range" min="1" max="5" step="0.1" value="${c.initialRating}" data-title="${encodeURIComponent(c.title)}" data-metric="${encodeURIComponent(c.metric)}" data-weight="${c.weight}" id="criteria-slider-${idx}" oninput="updateAppraisalComputedScore()" class="w-full accent-[#9E1B20] appraisal-score-slider cursor-pointer">
            <textarea rows="2" placeholder="Provide performance evidence, KPI deliverables observed, and coaching notes..." class="w-full p-2.5 bg-white rounded-xl border border-brand-border text-xs text-slate-800 focus:ring-2 focus:ring-primary focus:outline-none custom-scrollbar">${c.rationale || ''}</textarea>
        </div>
    `).join('');

    // Prepopulate supervisor recommendation textarea if present
    const notesInput = document.getElementById('eval-supervisor-notes');
    if (notesInput && evalData.record && evalData.record.supervisor_notes) {
        notesInput.value = evalData.record.supervisor_notes;
    }

    updateAppraisalComputedScore();
    openModal('modal-self-assessment');
}
window.openAppraisalModalInternal = openAppraisalModalInternal;

function updateAppraisalComputedScore() {
    const sliders = document.querySelectorAll('.appraisal-score-slider');
    if (!sliders || sliders.length === 0) return;

    let totalWeight = 0;
    let weightedSum = 0;

    sliders.forEach((slider, idx) => {
        const val = parseFloat(slider.value) || 3.0;
        const weight = parseFloat(slider.dataset.weight) || 33.3;
        const display = document.getElementById(`criteria-val-display-${idx}`);
        if (display) {
            display.textContent = `${val.toFixed(1)} / 5.0`;
        }
        weightedSum += val * weight;
        totalWeight += weight;
    });

    const finalScore = totalWeight > 0 ? (weightedSum / totalWeight) : 4.5;
    const tier = getTierInfo(finalScore);
    const displayEl = document.getElementById('eval-overall-score-display');
    if (displayEl) {
        displayEl.textContent = `${finalScore.toFixed(2)} / 5.0 (${tier.label})`;
        displayEl.className = `font-mono font-bold text-sm ${tier.isBelow ? 'text-rose-600 bg-rose-50 border-rose-200' : 'text-emerald-700 bg-emerald-50 border-emerald-200'} px-2.5 py-1 rounded-lg border inline-block`;
    }
}
window.updateAppraisalComputedScore = updateAppraisalComputedScore;

async function handleAppraisalSubmit(e) {
    if (e && e.preventDefault) e.preventDefault();

    const empId = document.getElementById('eval-target-emp-id')?.value || window.selectedEvalEmpId || '';
    const emp = (window.perfRoster || []).find(e => isSameEmployee(e.id, empId));

    const submitBtn = document.getElementById('btn-submit-appraisal');
    const origBtnHtml = submitBtn ? submitBtn.innerHTML : '';
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1.5"></i><span>Saving Appraisal...</span>';
    }

    try {
        const sliders = document.querySelectorAll('.appraisal-score-slider');
        let totalWeight = 0;
        let weightedSum = 0;
        const criteriaScores = [];

        sliders.forEach((slider, idx) => {
            const val = parseFloat(slider.value) || 4.5;
            const weight = parseFloat(slider.dataset.weight) || 33.3;
            const title = decodeURIComponent(slider.dataset.title || `Criterion ${idx + 1}`);
            const metric = decodeURIComponent(slider.dataset.metric || 'Target >= 95%');
            const textarea = slider.parentElement?.querySelector('textarea');
            const rationale = textarea ? textarea.value.trim() : '';

            weightedSum += val * weight;
            totalWeight += weight;

            criteriaScores.push({
                title,
                metric,
                weight,
                rating: val,
                rationale
            });
        });

        const finalScore = totalWeight > 0 ? parseFloat((weightedSum / totalWeight).toFixed(2)) : 4.60;
        const supervisorNotes = document.getElementById('eval-supervisor-notes')?.value.trim() || 'Appraisal successfully endorsed with positive hospitality benchmarking.';

        const empGoals = (window.dbGoals || []).filter(g => isSameEmployee(g.employee_id, empId));
        const activeGoal = typeof getEmployeeActiveGoal === 'function' ? getEmployeeActiveGoal(empId) : empGoals[0];
        const targetGoal = activeGoal;
        const goalId = document.getElementById('eval-target-goal-id')?.value || (targetGoal ? targetGoal.id : null);
        const targetGoalId = goalId || (targetGoal ? targetGoal.id : null);

        const needsTraining = !!targetGoal?.needs_training;
        const inTrainingScored = isEmployeeInTraining(empId, targetGoalId) && isEmployeeTrainingScored(empId, targetGoalId);
        const isRetry = needsTraining || inTrainingScored;

        if (inTrainingScored) {
            try {
                await PerformanceAPI.setNeedsTraining({ employee_id: empId, goal_id: targetGoalId, needs_training: false, retry_count: 3 });
            } catch (err) {
                console.warn('Set retry_count error:', err);
            }
        }

        const saved = await PerformanceAPI.submitAppraisal({
            employee_id: empId,
            goal_id: targetGoalId ? (!isNaN(parseInt(targetGoalId, 10)) ? parseInt(targetGoalId, 10) : null) : undefined,
            supervisor_rating: finalScore,
            new_supervisor_rating: isRetry ? finalScore : undefined,
            is_retry: isRetry,
            criteria_scores: criteriaScores,
            supervisor_notes: supervisorNotes
        });

        if (emp) {
            emp.evaluationStatus = 'Rated';
            emp.supervisorRating = finalScore;
            emp.managerRating = finalScore;
            if (isRetry) {
                emp.newSupervisorRating = finalScore;
            }
            emp.tierLabel = saved.tier_label || getTierInfo(finalScore).label;
            emp.evaluationRecord = saved;
            emp.reviewStatus = 'Pending Calibration';
        }

        updateDbEvaluationRecord(saved);

        if (typeof showToast === 'function') {
            showToast(`✓ Formal appraisal saved for ${emp ? emp.name : 'Employee'} (${finalScore.toFixed(2)} / 5.0 · ${emp ? emp.tierLabel : 'Rated'})!`, 'success', { duration: 6000 });
        }

        closeModal('modal-self-assessment');
        renderEvaluationRosterTable();
        showEmployeeEvalDetail(empId);
        renderReviewRosterTable();
        renderIDPRosterTable();
        renderCycleRosterTable();
        updateAllPerfStepperBadges();

        if (typeof loadLiveNotifications === 'function') {
            loadLiveNotifications(window.activePersonaRole || 'Supervisor');
        }
    } catch (err) {
        console.error('Appraisal submission error:', err);
        if (typeof showToast === 'function') {
            showToast(err.message || 'Failed to save appraisal.', 'error');
        }
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = origBtnHtml;
        }
    }
}
window.handleAppraisalSubmit = handleAppraisalSubmit;

// ============================================================================
// Kudos & Phase Transitions
// ============================================================================

function getKudosXP(rating) {
    const r = parseFloat(rating) || 0;
    if (r >= 5.0) return 20;
    if (r >= 4.0) return 8;
    if (r >= 3.0) return 5;
    return 0;
}
window.getKudosXP = getKudosXP;

function triggerSendKudosForEmployee(empId) {
    const emp = (window.perfRoster || []).find(e => isSameEmployee(e.id, empId)) || { id: empId, name: 'Associate' };
    const evalData = getEmployeeEvalData(emp);
    const rating = evalData.record?.calibrated_score
        ? parseFloat(evalData.record.calibrated_score)
        : (evalData.supervisorRating || 4.5);
    const xpPoints = getKudosXP(rating);
    const evalId = evalData.record?.id || null;

    showActionConfirmModal({
        title: 'Send Colleague Kudos & Mark Goal Done',
        message: `Award +${xpPoints} Performance XP to ${emp.name} for achieving ⭐ ${rating.toFixed(2)} / 5.0 rating? This will log points in xp_ledger, disable further kudos, attach the XP transaction to the performance goal, and mark the goal as Done.`,
        confirmBtnText: `Award +${xpPoints} XP & Mark Done`,
        confirmBtnClass: 'btn-primary bg-amber-500 hover:bg-amber-600 text-white',
        iconClass: 'fas fa-award',
        iconContainerClass: 'bg-amber-100 text-amber-700',
        onConfirm: async () => {
            try {
                const res = await PerformanceAPI.awardPerformanceXP(emp.id, xpPoints, evalId, `Performance Kudos (+${xpPoints} XP)`);
                emp.kudosSent = true;
                const expId = res?.exp_id || res?.data?.id || (res?.id ? res.id : null);

                (window.dbGoals || []).forEach(g => {
                    if (isSameEmployee(g.employee_id, emp.id) && (g.status === 'Approved' || g.status === 'Done')) {
                        g.status = 'Done';
                        if (expId) g.exp_id = expId;
                    }
                });

                if (typeof showToast === 'function') {
                    showToast(` +${xpPoints} XP awarded to ${emp.name}! Performance goal marked as Done.`, 'success');
                }

                if (typeof renderIDPRosterTable === 'function') renderIDPRosterTable();
                if (typeof showIDPDetail === 'function') showIDPDetail(emp.id);
                if (typeof renderCycleRosterTable === 'function') renderCycleRosterTable();
                if (typeof loadAndRenderPlanningGoals === 'function') await loadAndRenderPlanningGoals();
            } catch (err) {
                console.error('Award XP error:', err);
                if (typeof showToast === 'function') {
                    showToast(err.message || 'Failed to award XP', 'error');
                }
            }
        }
    });
}
window.triggerSendKudosForEmployee = triggerSendKudosForEmployee;

function proceedFromPhase4ToPhase5(empId) {
    const targetEmpId = empId || window.selectedEvalEmpId || '';
    const emp = (window.perfRoster || []).find(e => isSameEmployee(e.id, targetEmpId));

    if (typeof closeModal === 'function') {
        closeModal('modal-view-appraisal');
        closeModal('modal-self-assessment');
    }

    if (typeof switchSubTab === 'function') {
        switchSubTab('perf', 'review');
    }

    if (emp) {
        const searchInput = document.getElementById('search-review-emp') || document.getElementById('review-search-input');
        if (searchInput) {
            searchInput.value = emp.name;
        }
        window.reviewSearchQuery = emp.name;
        if (typeof reviewCurrentPage !== 'undefined') reviewCurrentPage = 1;

        if (typeof renderReviewRosterTable === 'function') {
            renderReviewRosterTable();
        }
        if (typeof showCalibrationDetail === 'function') {
            showCalibrationDetail(emp.id, false);
        }
    }
}
window.proceedFromPhase4ToPhase5 = proceedFromPhase4ToPhase5;

// ============================================================================
// AI Appraisal Recommendation Engine
// Analyzes: Supervisor Coaching & Notes, Employee Feedback, Learnings, Milestones
// Features: LocalStorage Caching, Minimization Background Dock, Full Regeneration
// ============================================================================

window.currentAIAppraisalRecommendations = [];
window.selectedAIAppraisalEmpIds = new Set();
const AI_APPRAISAL_CACHE_KEY = 'oxf_ai_appraisal_cache_v2';

function getAIAppraisalCache() {
    try {
        const raw = localStorage.getItem(AI_APPRAISAL_CACHE_KEY);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        if (parsed && Array.isArray(parsed.recommendations)) {
            return parsed.recommendations;
        }
    } catch (e) {
        console.warn('Failed to read AI appraisal cache:', e);
    }
    return null;
}

function setAIAppraisalCache(recs) {
    try {
        localStorage.setItem(AI_APPRAISAL_CACHE_KEY, JSON.stringify({
            timestamp: Date.now(),
            recommendations: Array.isArray(recs) ? recs : []
        }));
    } catch (e) {
        console.warn('Failed to save AI appraisal cache:', e);
    }
}

function clearAIAppraisalCache() {
    try {
        localStorage.removeItem(AI_APPRAISAL_CACHE_KEY);
    } catch (e) {}
}

function updateAIAppraisalMinimizedDock(status = 'ready', message = '', count = null) {
    const dock = document.getElementById('ai-appraisal-minimized-dock');
    const badge = document.getElementById('ai-appraisal-dock-badge');
    const statusEl = document.getElementById('ai-appraisal-dock-status');
    const icon = document.getElementById('ai-appraisal-dock-icon');
    const spinner = document.getElementById('ai-appraisal-dock-spinner');
    const ring = document.getElementById('ai-appraisal-dock-pulse-ring');

    if (!dock) return;

    if (status === 'analyzing') {
        if (badge) {
            badge.textContent = 'Analyzing...';
            badge.className = 'px-2 py-0.5 rounded-full text-[9px] font-bold bg-amber-400 text-slate-900 border border-amber-300 animate-pulse';
        }
        if (statusEl) statusEl.textContent = message || 'Synthesizing shift evidence...';
        if (icon) icon.className = 'fas fa-wand-magic-sparkles text-amber-300 text-sm animate-pulse';
        if (spinner) spinner.classList.remove('hidden');
        if (ring) ring.classList.remove('hidden');
    } else if (status === 'ready') {
        const c = count !== null ? count : (window.currentAIAppraisalRecommendations?.length || 0);
        if (badge) {
            badge.textContent = `${c} Ready`;
            badge.className = 'px-2 py-0.5 rounded-full text-[9px] font-bold bg-white text-primary shadow-2xs';
        }
        if (statusEl) statusEl.textContent = message || (c > 0 ? 'Click to open & apply recommendations' : 'All candidates evaluated');
        if (icon) icon.className = 'fas fa-wand-magic-sparkles text-amber-300 text-sm';
        if (spinner) spinner.classList.add('hidden');
        if (ring) {
            if (c > 0) ring.classList.remove('hidden');
            else ring.classList.add('hidden');
        }
    }
}

function minimizeAIAppraisalModal() {
    if (typeof closeModal === 'function') {
        closeModal('modal-ai-appraisal-recommendations');
    }
    const dock = document.getElementById('ai-appraisal-minimized-dock');
    if (dock) {
        dock.classList.remove('hidden');
        dock.style.display = 'block';
        const count = window.currentAIAppraisalRecommendations?.length || 0;
        updateAIAppraisalMinimizedDock('ready', `${count} candidate recommendation${count === 1 ? '' : 's'} ready`, count);
    }
    if (typeof showToast === 'function') {
        showToast('AI Assistant minimized to bottom-right widget. Click anytime to restore.', 'info', { duration: 3500 });
    }
}
window.minimizeAIAppraisalModal = minimizeAIAppraisalModal;

function restoreAIAppraisalModal() {
    const dock = document.getElementById('ai-appraisal-minimized-dock');
    if (dock) {
        dock.classList.add('hidden');
        dock.style.display = 'none';
    }
    if (typeof openModal === 'function') {
        openModal('modal-ai-appraisal-recommendations');
    }
    renderAIAppraisalRecommendationsList();
}
window.restoreAIAppraisalModal = restoreAIAppraisalModal;

function closeAIAppraisalMinimizedDock() {
    const dock = document.getElementById('ai-appraisal-minimized-dock');
    if (dock) {
        dock.classList.add('hidden');
        dock.style.display = 'none';
    }
}
window.closeAIAppraisalMinimizedDock = closeAIAppraisalMinimizedDock;

function closeAIAppraisalRecommendationsModal() {
    if (typeof closeModal === 'function') {
        closeModal('modal-ai-appraisal-recommendations');
    }
    closeAIAppraisalMinimizedDock();
}
window.closeAIAppraisalRecommendationsModal = closeAIAppraisalRecommendationsModal;

async function openAIAppraisalRecommendationsModal(forceRegenerate = false, startMinimized = false) {
    const modal = document.getElementById('modal-ai-appraisal-recommendations');
    const container = document.getElementById('ai-appraisal-cards-container');
    const countEl = document.getElementById('ai-appraisal-pending-count');
    const cacheIndicator = document.getElementById('ai-appraisal-cache-indicator');
    const selectAllCb = document.getElementById('ai-appraisal-select-all');

    if (!modal || !container) return;

    // 1. Check LocalStorage Cache if not forced to regenerate
    if (!forceRegenerate) {
        const cached = getAIAppraisalCache();
        if (cached && cached.length > 0) {
            window.currentAIAppraisalRecommendations = cached;
            window.selectedAIAppraisalEmpIds = new Set(cached.map(r => String(r.employee_id)));
            if (selectAllCb) selectAllCb.checked = cached.length > 0;
            if (cacheIndicator) cacheIndicator.classList.remove('hidden');
            renderAIAppraisalRecommendationsList();
            updateAIAppraisalMinimizedDock('ready', `${cached.length} recommendations ready`, cached.length);
            
            if (startMinimized) {
                const dock = document.getElementById('ai-appraisal-minimized-dock');
                if (dock) {
                    dock.classList.remove('hidden');
                    dock.style.display = 'block';
                }
            } else {
                if (typeof openModal === 'function') {
                    openModal('modal-ai-appraisal-recommendations');
                }
                closeAIAppraisalMinimizedDock();
            }
            return;
        }
    }

    if (cacheIndicator) cacheIndicator.classList.add('hidden');

    if (startMinimized) {
        const dock = document.getElementById('ai-appraisal-minimized-dock');
        if (dock) {
            dock.classList.remove('hidden');
            dock.style.display = 'block';
        }
        updateAIAppraisalMinimizedDock('analyzing', 'Synthesizing shift evidence...');
    } else {
        if (typeof openModal === 'function') {
            openModal('modal-ai-appraisal-recommendations');
        }
        closeAIAppraisalMinimizedDock();
    }

    // 2. Loading / Analyzing State
    if (countEl) countEl.textContent = 'Analyzing shift evidence...';
    updateAIAppraisalMinimizedDock('analyzing', 'Synthesizing evidence across roster...');
    container.innerHTML = `
        <div class="py-14 text-center space-y-4">
            <div class="w-14 h-14 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-xl mx-auto border border-indigo-200 shadow-2xs">
                <i class="fas fa-wand-magic-sparkles fa-spin"></i>
            </div>
            <div>
                <h4 class="font-bold text-slate-800 text-sm">AI Copilot Analyzing Performance Evidence</h4>
                <p class="text-xs text-slate-500 mt-1 max-w-md mx-auto">Evaluating supervisor floor notes, associate reflection logs, milestone deliverables, and checklist progress...</p>
            </div>
            <div class="w-48 bg-slate-100 h-1.5 rounded-full overflow-hidden mx-auto">
                <div class="h-full bg-primary rounded-full animate-pulse w-full"></div>
            </div>
            <div class="pt-2">
                <button type="button" onclick="minimizeAIAppraisalModal()" class="px-3.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-xs font-bold transition inline-flex items-center space-x-1.5 cursor-pointer">
                    <i class="fas fa-window-minimize text-[10px] -translate-y-0.5"></i>
                    <span>Run in Background (Minimize)</span>
                </button>
            </div>
        </div>
    `;

    try {
        let recs = [];
        try {
            const apiRes = await PerformanceAPI.generateAppraisalRecommendations();
            recs = apiRes?.recommendations || apiRes?.data?.recommendations || [];
        } catch (apiErr) {
            console.warn('API generateAppraisalRecommendations fallback:', apiErr);
        }

        // Resilient client-side fallback if backend returns empty or offline
        if (!Array.isArray(recs) || recs.length === 0) {
            recs = generateClientSideAppraisalRecommendations();
        }

        // Save fresh results into LocalStorage cache
        setAIAppraisalCache(recs);

        window.currentAIAppraisalRecommendations = recs;
        window.selectedAIAppraisalEmpIds = new Set(recs.map(r => String(r.employee_id)));

        if (selectAllCb) {
            selectAllCb.checked = recs.length > 0;
        }

        renderAIAppraisalRecommendationsList();
        updateAIAppraisalMinimizedDock('ready', `${recs.length} recommendations ready - Click to view!`, recs.length);

        const isMinimized = !document.getElementById('ai-appraisal-minimized-dock')?.classList.contains('hidden');
        if (isMinimized && typeof showToast === 'function') {
            showToast(`✨ AI Appraisal Analysis Complete! (${recs.length} candidates ready). Click the bottom-right dock to review.`, 'success', { duration: 6000 });
        } else if (forceRegenerate && typeof showToast === 'function') {
            showToast('✓ AI Recommendations refreshed with latest shift evidence.', 'success', { duration: 4000 });
        }
    } catch (err) {
        console.error('Error generating AI appraisal recommendations:', err);
        container.innerHTML = `
            <div class="p-8 text-center bg-rose-50 rounded-2xl border border-rose-200 text-xs text-rose-800 space-y-2">
                <i class="fas fa-triangle-exclamation text-rose-600 text-base"></i>
                <p class="font-bold">Failed to generate AI recommendations</p>
                <p class="text-slate-600">${err.message || 'Please check connection and retry.'}</p>
                <button onclick="openAIAppraisalRecommendationsModal(true)" class="mt-2 btn-secondary px-3 py-1.5 text-xs font-bold">Retry Analysis</button>
            </div>
        `;
        updateAIAppraisalMinimizedDock('ready', 'Analysis interrupted');
    }
}
window.openAIAppraisalRecommendationsModal = openAIAppraisalRecommendationsModal;

function analyzeClientTextSubstanceAndSentiment(texts) {
    if (!texts || texts.length === 0) {
        return { count: 0, valid_count: 0, substance_level: 'empty', quality_score: 0.0, sentiment_score: 0.0, is_placeholder: false, clean_snippets: [], flagged_samples: [] };
    }

    const placeholderPatterns = [
        /^[a-z0-9\s]{1,4}$/i,
        /^(.)\1{2,}$/i,
        /^(na|n\/a|none|nil|null|test|testing|asd|asdf|zsdas|abc|xyz|sample|placeholder|todo)$/i
    ];

    const positiveKeywords = [
        'excellent', 'outstanding', 'exceeded', 'surpassed', 'mastered', 'stellar', 'exceptional',
        'diligent', 'proactive', 'resolved', 'improved', 'commendable', 'flawless', 'exemplary',
        'efficient', 'punctual', 'compliance', 'initiative', 'thorough', 'smooth',
        'great', 'good', 'satisfied', 'success', 'collaborative', 'mentor', 'leadership',
        'zero escalations', 'zero complaints', 'no complaints', 'zero incidents', 'resolved issues'
    ];
    const negativeKeywords = [
        'delayed', 'missed', 'failed', 'complaint', 'escalation',
        'struggled', 'needs improvement', 'incident', 'poor', 'absent',
        'incomplete', 'violation', 'dispute', 'warning'
    ];

    const validTexts = [];
    const flaggedSamples = [];
    let totalWords = 0;
    let sentimentSum = 0;

    texts.forEach(raw => {
        const trimmed = String(raw || '').trim();
        if (!trimmed) return;

        let isPlaceholder = false;
        for (const pat of placeholderPatterns) {
            if (pat.test(trimmed)) {
                isPlaceholder = true;
                break;
            }
        }

        const words = trimmed.split(/\s+/).filter(Boolean);
        const wordCount = words.length;

        if (isPlaceholder || (wordCount <= 1 && trimmed.length <= 4)) {
            flaggedSamples.push(trimmed);
        } else {
            validTexts.push(trimmed);
            totalWords += wordCount;

            const lower = trimmed.toLowerCase();
            positiveKeywords.forEach(kw => { if (lower.includes(kw)) sentimentSum += 0.25; });
            negativeKeywords.forEach(kw => {
                const reg = new RegExp('(zero|no|resolved|prevented)\\s+(guest\\s+|floor\\s+|client\\s+)?' + kw, 'i');
                if (reg.test(lower)) {
                    sentimentSum += 0.25;
                } else if (lower.includes(kw)) {
                    sentimentSum -= 0.30;
                }
            });
        }
    });

    const allCount = texts.length;
    const validCount = validTexts.length;
    const placeholderCount = flaggedSamples.length;

    if (validCount === 0 && placeholderCount > 0) {
        return { count: allCount, valid_count: 0, substance_level: 'placeholder', quality_score: 0.05, sentiment_score: 0.0, is_placeholder: true, clean_snippets: [], flagged_samples: flaggedSamples };
    }

    if (validCount === 0) {
        return { count: 0, valid_count: 0, substance_level: 'empty', quality_score: 0.0, sentiment_score: 0.0, is_placeholder: false, clean_snippets: [], flagged_samples: [] };
    }

    const avgWords = totalWords / Math.max(1, validCount);
    let substanceLevel = 'minimal';
    let qualityScore = 0.35;

    if (avgWords >= 20 || totalWords >= 30) {
        substanceLevel = 'comprehensive';
        qualityScore = 1.0;
    } else if (avgWords >= 10 || totalWords >= 15) {
        substanceLevel = 'moderate';
        qualityScore = 0.75;
    } else if (avgWords >= 4) {
        substanceLevel = 'minimal';
        qualityScore = 0.45;
    }

    const clampedSentiment = Math.max(-1.0, Math.min(1.0, sentimentSum));

    return {
        count: allCount,
        valid_count: validCount,
        substance_level: substanceLevel,
        quality_score: qualityScore,
        sentiment_score: clampedSentiment,
        is_placeholder: false,
        clean_snippets: validTexts,
        flagged_samples: flaggedSamples
    };
}

function generateClientSideAppraisalRecommendations() {
    const unrated = (window.perfRoster || []).filter(emp => {
        const evalData = getEmployeeEvalData(emp);
        const hasGoal = typeof employeeHasApprovedGoal === 'function' ? employeeHasApprovedGoal(emp) : (emp.goals && emp.goals.length > 0);
        return hasGoal && !evalData.isRated;
    });

    const allLogs = window.dbMonitoringLogs || [];

    return unrated.map(emp => {
        const goal = (emp.goals || []).find(g => (g.status || '').toLowerCase() === 'approved') || emp.goals[0] || {};
        const tasks = goal.tasks || [];
        const completedTasks = tasks.filter(t => t.status === 'completed');
        const taskRatio = tasks.length > 0 ? (completedTasks.length / tasks.length) : 1.0;

        const supervisorNotes = [];
        if (goal.supervisor_notes) supervisorNotes.push(goal.supervisor_notes);
        tasks.forEach(t => {
            if (t.supervisor_feedback) supervisorNotes.push(t.supervisor_feedback);
            if (t.supervisor_accomplishment) supervisorNotes.push(t.supervisor_accomplishment);
        });

        const employeeLearnings = [];
        tasks.forEach(t => {
            if (t.employee_learnings) employeeLearnings.push(t.employee_learnings);
        });

        const employeeFeedback = [];
        if (goal.feedback) employeeFeedback.push(goal.feedback);
        tasks.forEach(t => {
            if (t.employee_feedback) employeeFeedback.push(t.employee_feedback);
        });

        const empLogs = allLogs.filter(l => isSameEmployee(l.employee_id, emp.id));
        const milestoneTexts = [];
        empLogs.forEach(l => {
            if (l.milestone_title) milestoneTexts.push(l.milestone_title);
            if (l.actual_metric) milestoneTexts.push(l.actual_metric);
            if (l.notes) milestoneTexts.push(l.notes);
        });

        // Perform substance & sentiment analysis
        const supAnalysis = analyzeClientTextSubstanceAndSentiment(supervisorNotes);
        const learnAnalysis = analyzeClientTextSubstanceAndSentiment(employeeLearnings);
        const fbAnalysis = analyzeClientTextSubstanceAndSentiment(employeeFeedback);
        const msAnalysis = analyzeClientTextSubstanceAndSentiment(milestoneTexts);

        const hasPlaceholders = learnAnalysis.is_placeholder || supAnalysis.is_placeholder || msAnalysis.is_placeholder;
        const isNegative = (supAnalysis.sentiment_score < 0) || (learnAnalysis.sentiment_score < 0);

        const placeholderWarnings = [];
        if (learnAnalysis.is_placeholder) placeholderWarnings.push(`reflections ('${learnAnalysis.flagged_samples.join(', ')}')`);
        if (supAnalysis.is_placeholder) placeholderWarnings.push(`supervisor notes ('${supAnalysis.flagged_samples.join(', ')}')`);
        if (msAnalysis.is_placeholder) placeholderWarnings.push(`shift milestones ('${msAnalysis.flagged_samples.join(', ')}')`);

        let calculatedScore = 1.80;
        let recommendedScore = 2.50;
        let tierLabel = 'Below Benchmark (< 3.0)';

        if (hasPlaceholders || isNegative) {
            calculatedScore = 1.80 + (taskRatio * 0.80);
            if (isNegative) {
                calculatedScore += Math.min(0, (supAnalysis.sentiment_score + learnAnalysis.sentiment_score) * 0.40);
            }
            if (placeholderWarnings.length >= 2) {
                calculatedScore -= 0.15;
            }
            recommendedScore = Math.min(2.85, Math.max(1.20, parseFloat(calculatedScore.toFixed(2))));
            tierLabel = 'Below Benchmark (Needs Calibration)';
        } else {
            calculatedScore = 3.20;
            calculatedScore += (taskRatio * 0.50);
            calculatedScore += (supAnalysis.quality_score * 0.45);
            calculatedScore += (learnAnalysis.quality_score * 0.35);
            calculatedScore += (msAnalysis.quality_score * 0.35);
            calculatedScore += Math.max(0, supAnalysis.sentiment_score * 0.15);

            recommendedScore = Math.min(5.00, Math.max(3.00, parseFloat(calculatedScore.toFixed(2))));
            if (recommendedScore >= 4.50) tierLabel = 'Master Tier';
            else if (recommendedScore >= 3.75) tierLabel = 'Advanced Tier';
            else tierLabel = 'Proficient';
        }

        const tier = getTierInfo(recommendedScore);

        let aiNotes = '';
        if (placeholderWarnings.length > 0) {
            aiNotes = `AI Appraisal Review: Associate ${emp.name} completed task checklist (${completedTasks.length}/${tasks.length}). However, ${placeholderWarnings.join(' and ')} contain gibberish/placeholder text. Due to lack of qualitative floor evidence, a rating below 3.0 (${recommendedScore.toFixed(2)}/5.0 · ${tierLabel}) is assigned. Supervisor floor calibration required.`;
        } else if (isNegative) {
            aiNotes = `AI Appraisal Review: Critical performance/compliance concerns were flagged in supervisor notes or feedback. A below-benchmark rating of ${recommendedScore.toFixed(2)}/5.0 (${tierLabel}) is assigned pending formal remediation/coaching.`;
        } else {
            const snippets = [];
            if (supAnalysis.clean_snippets[0]) snippets.push(`Supervisor notes: "${supAnalysis.clean_snippets[0]}"`);
            if (learnAnalysis.clean_snippets[0]) snippets.push(`Associate reflections: "${learnAnalysis.clean_snippets[0]}"`);
            if (msAnalysis.clean_snippets[0]) snippets.push(`Shift milestone: "${msAnalysis.clean_snippets[0]}"`);

            aiNotes = `AI Appraisal Recommendation: Associate ${emp.name} demonstrated solid operational performance in "${goal.title || 'Shift Operations'}". `;
            if (snippets.length > 0) aiNotes += snippets.join('. ') + '. ';
            aiNotes += `Recommended for ${tierLabel} rating (${recommendedScore.toFixed(2)}/5.0) based on verified KPI deliverables and completed checklist matrix.`;
        }

        return {
            employee_id: emp.id,
            employee_name: emp.name,
            employee_position: emp.position,
            employee_department: emp.department,
            avatar: emp.avatar || emp.name.charAt(0),
            goal_id: goal.id || null,
            goal_title: goal.title || 'Operational Excellence Target',
            target_metric: goal.target_metric || '100% SOP Compliance',
            recommended_score: recommendedScore,
            tier_label: tier.label,
            supervisor_notes: aiNotes,
            criteria_scores: [
                {
                    title: 'Operational Excellence & Protocol Adherence',
                    metric: goal.target_metric || '100% SOP Compliance',
                    weight: 40,
                    rating: Math.min(5.0, Math.max(1.0, parseFloat((recommendedScore + 0.1).toFixed(1)))),
                    rationale: learnAnalysis.clean_snippets[0] 
                        ? `Demonstrated strong operational diligence: "${learnAnalysis.clean_snippets[0].substring(0, 80)}..."` 
                        : (learnAnalysis.is_placeholder 
                            ? `Checklist verified. Reflections contain minimal text (${learnAnalysis.flagged_samples[0] || 'n/a'}).`
                            : 'Verified shift checklist completion and operational compliance.')
                },
                {
                    title: 'Shift Execution, KPI Delivery & Diligence',
                    metric: empLogs.length > 0 ? (empLogs[0].actual_metric || 'Target >= 95% Deliverable') : 'Shift KPI Deliverables Met',
                    weight: 30,
                    rating: Math.min(5.0, Math.max(1.0, parseFloat(recommendedScore.toFixed(1)))),
                    rationale: empLogs.length > 0 
                        ? (!msAnalysis.is_placeholder
                            ? `Logged ${empLogs.length} shift milestone(s) with verified deliverables.`
                            : `Logged shift milestone contains minimal metric placeholder (${msAnalysis.flagged_samples[0] || 'n/a'}).`)
                        : 'Achieved checklist execution within scheduled shift timeline.'
                },
                {
                    title: 'Teamwork, Conflict De-escalation & Mentorship',
                    metric: 'Zero Unresolved Escalations / Positive Peer Collaboration',
                    weight: 30,
                    rating: Math.min(5.0, Math.max(1.0, parseFloat((recommendedScore - 0.1).toFixed(1)))),
                    rationale: supAnalysis.clean_snippets[0] 
                        ? `Supervisor coaching recorded: "${supAnalysis.clean_snippets[0].substring(0, 80)}..."` 
                        : (supAnalysis.is_placeholder
                            ? `Supervisor coaching note contains brief placeholder text (${supAnalysis.flagged_samples[0] || 'n/a'}).`
                            : 'Maintained proactive guest engagement and positive floor coordination.')
                }
            ],
            evidence_summary: {
                supervisor_notes_count: supervisorNotes.length,
                employee_feedback_count: employeeFeedback.length,
                employee_learnings_count: employeeLearnings.length,
                milestones_count: empLogs.length,
                completed_tasks_count: completedTasks.length,
                total_tasks_count: tasks.length,
                sample_supervisor_note: supervisorNotes[0] || null,
                sample_learning: employeeLearnings[0] || null,
                sample_milestone: empLogs[0]?.milestone_title || null,
                has_placeholders: placeholderWarnings.length > 0
            }
        };
    });
}

function renderAIAppraisalRecommendationsList() {
    const container = document.getElementById('ai-appraisal-cards-container');
    const countEl = document.getElementById('ai-appraisal-pending-count');
    const selectedLabel = document.getElementById('ai-appraisal-selected-label');
    const selectAllCb = document.getElementById('ai-appraisal-select-all');

    if (!container) return;

    const recs = window.currentAIAppraisalRecommendations || [];

    if (countEl) {
        countEl.textContent = `${recs.length} Candidate${recs.length === 1 ? '' : 's'} Analyzed`;
    }

    if (selectedLabel) {
        selectedLabel.textContent = `(${window.selectedAIAppraisalEmpIds.size} selected)`;
    }

    if (selectAllCb) {
        selectAllCb.checked = recs.length > 0 && window.selectedAIAppraisalEmpIds.size === recs.length;
    }

    if (recs.length === 0) {
        container.innerHTML = `
            <div class="py-12 text-center bg-white rounded-2xl border border-slate-200 shadow-2xs space-y-3">
                <div class="w-12 h-12 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center text-lg mx-auto border border-emerald-100">
                    <i class="fas fa-check-double"></i>
                </div>
                <div>
                    <h4 class="font-bold text-slate-900 text-sm">All Associates Formally Evaluated</h4>
                    <p class="text-xs text-slate-500 mt-1 max-w-sm mx-auto">All active associates currently have completed appraisal ratings. You can re-evaluate or calibrate individual scores in the Roster table.</p>
                </div>
            </div>
        `;
        return;
    }

    container.innerHTML = recs.map(rec => {
        const isSelected = window.selectedAIAppraisalEmpIds.has(String(rec.employee_id));
        const tier = getTierInfo(rec.recommended_score);
        const ev = rec.evidence_summary || {};

        return `
            <div class="p-4 bg-white rounded-2xl border ${isSelected ? 'border-purple-300 ring-2 ring-purple-100' : 'border-slate-200'} shadow-2xs space-y-3.5 transition hover:border-purple-300" id="ai-rec-card-${rec.employee_id}">
                <!-- Header -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 pb-2.5 border-b border-slate-100">
                    <div class="flex items-center space-x-3">
                        <input type="checkbox" onchange="toggleAIAppraisalSelection('${rec.employee_id}', this.checked)" ${isSelected ? 'checked' : ''} class="w-4 h-4 rounded text-purple-600 focus:ring-purple-500 border-slate-300 cursor-pointer">
                        <div class="w-9 h-9 rounded-xl bg-purple-100 text-purple-700 flex items-center justify-center font-bold text-xs shrink-0">
                            ${rec.avatar || rec.employee_name.charAt(0)}
                        </div>
                        <div>
                            <div class="font-bold text-slate-900 text-xs">${rec.employee_name}</div>
                            <div class="text-[10px] text-slate-500">${rec.employee_position} · ${rec.employee_department}</div>
                        </div>
                    </div>
                    <div class="flex items-center space-x-2 self-end sm:self-center">
                        <span class="px-2.5 py-1 rounded-xl text-xs font-bold ${tier.badgeClass} border inline-flex items-center space-x-1.5 shadow-2xs">
                            <i class="fas fa-star text-amber-500 text-[10px]"></i>
                            <span>⭐ ${rec.recommended_score.toFixed(2)} / 5.0</span>
                            <span class="text-[10px] font-normal">(${rec.tier_label})</span>
                        </span>
                        <button type="button" onclick="applySingleAIAppraisalRecommendation('${rec.employee_id}')" id="btn-apply-rec-${rec.employee_id}" class="btn-primary px-3.5 py-1.5 text-xs font-bold rounded-xl shadow-xs inline-flex items-center space-x-1.5 cursor-pointer transition">
                            <i class="fas fa-check text-[10px]"></i>
                            <span>Apply Recommendation</span>
                        </button>
                    </div>
                </div>

                <!-- Goal Context & Metric -->
                <div class="bg-slate-50/70 p-2.5 rounded-xl border border-slate-200/70 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs">
                    <div>
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Assessed Performance Target</span>
                        <span class="font-bold text-slate-900 text-xs">${rec.goal_title}</span>
                    </div>
                    <div class="text-right sm:text-right">
                        <span class="text-[10px] text-slate-400 font-mono">Target: <strong class="text-primary">${rec.target_metric}</strong></span>
                    </div>
                </div>

                <!-- 4 Evidence Chips -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2 text-[11px]">
                    <div class="p-2.5 bg-purple-50/70 rounded-xl border border-purple-100 space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-purple-950 flex items-center space-x-1 text-[10px]">
                                <i class="fas fa-user-check text-purple-600"></i>
                                <span>Supervisor Notes (${ev.supervisor_notes_count || 0})</span>
                            </span>
                            ${(ev.sample_supervisor_note && ev.sample_supervisor_note.length <= 4) ? '<span class="text-[9px] font-bold text-amber-700 bg-amber-100 px-1.5 py-0.5 rounded">Brief</span>' : ''}
                        </div>
                        <p class="text-slate-600 italic line-clamp-2 text-[10px]">"${ev.sample_supervisor_note || 'Positive operational adherence'}"</p>
                    </div>
                    <div class="p-2.5 bg-emerald-50/70 rounded-xl border border-emerald-100 space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-emerald-950 flex items-center space-x-1 text-[10px]">
                                <i class="fas fa-lightbulb text-emerald-600"></i>
                                <span>Learnings &amp; Reflections (${ev.employee_learnings_count || 0})</span>
                            </span>
                            ${(ev.sample_learning && ev.sample_learning.length <= 4) ? '<span class="text-[9px] font-bold text-amber-700 bg-amber-100 px-1.5 py-0.5 rounded">Minimal</span>' : ''}
                        </div>
                        <p class="text-slate-600 italic line-clamp-2 text-[10px]">"${ev.sample_learning || 'Completed all operational checklist items'}"</p>
                    </div>
                    <div class="p-2.5 bg-amber-50/70 rounded-xl border border-amber-100 space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-amber-950 flex items-center space-x-1 text-[10px]">
                                <i class="fas fa-flag text-amber-600"></i>
                                <span>Shift Milestones (${ev.milestones_count || 0})</span>
                            </span>
                            ${(ev.sample_milestone && ev.sample_milestone.length <= 4) ? '<span class="text-[9px] font-bold text-amber-700 bg-amber-100 px-1.5 py-0.5 rounded">Brief</span>' : ''}
                        </div>
                        <p class="text-slate-600 line-clamp-2 text-[10px]">${ev.sample_milestone || 'Verified KPI shift deliverable'}</p>
                    </div>
                    <div class="p-2.5 bg-indigo-50/70 rounded-xl border border-indigo-100 space-y-1">
                        <span class="font-bold text-indigo-950 flex items-center space-x-1 text-[10px]">
                            <i class="fas fa-tasks text-indigo-600"></i>
                            <span>Tasks Verified (${ev.completed_tasks_count || 0}/${ev.total_tasks_count || 0})</span>
                        </span>
                        <p class="text-slate-600 font-bold text-[10px] text-emerald-700">${ev.completed_tasks_count >= ev.total_tasks_count && ev.total_tasks_count > 0 ? '100% Checklist Done' : `${Math.round(((ev.completed_tasks_count || 0)/Math.max(1, ev.total_tasks_count || 1))*100)}% Complete`}</p>
                    </div>
                </div>

                <!-- Criteria Scores Preview -->
                <div class="space-y-1.5 pt-1">
                    <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Recommended Multi-Factor Criteria Rubrics:</span>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                        ${rec.criteria_scores.map((c, i) => `
                            <div class="p-2.5 bg-slate-50 rounded-xl border border-slate-200/80 space-y-1 text-xs">
                                <div class="flex items-center justify-between font-semibold text-[11px]">
                                    <span class="text-slate-800 line-clamp-1">${i + 1}. ${c.title}</span>
                                    <span class="font-bold font-mono text-purple-700 shrink-0 ml-1">${c.rating.toFixed(1)} / 5.0</span>
                                </div>
                                <p class="text-[10px] text-slate-500 leading-snug line-clamp-2">${c.rationale}</p>
                            </div>
                        `).join('')}
                    </div>
                </div>

                <!-- AI Endorsement Note -->
                <div class="p-2.5 bg-brand-canvas rounded-xl border border-brand-border text-xs text-slate-700 leading-relaxed italic">
                    <strong class="font-bold text-slate-900 not-italic mr-1"><i class="fas fa-sparkles text-amber-500 mr-1"></i>AI Recommendation Summary:</strong>
                    "${rec.supervisor_notes}"
                </div>
            </div>
        `;
    }).join('');
}

function toggleAllAIAppraisalSelections(isChecked) {
    const recs = window.currentAIAppraisalRecommendations || [];
    if (isChecked) {
        window.selectedAIAppraisalEmpIds = new Set(recs.map(r => String(r.employee_id)));
    } else {
        window.selectedAIAppraisalEmpIds.clear();
    }
    renderAIAppraisalRecommendationsList();
}
window.toggleAllAIAppraisalSelections = toggleAllAIAppraisalSelections;

function toggleAIAppraisalSelection(empId, isChecked) {
    const sId = String(empId);
    if (isChecked) {
        window.selectedAIAppraisalEmpIds.add(sId);
    } else {
        window.selectedAIAppraisalEmpIds.delete(sId);
    }
    const selectedLabel = document.getElementById('ai-appraisal-selected-label');
    const selectAllCb = document.getElementById('ai-appraisal-select-all');
    const recs = window.currentAIAppraisalRecommendations || [];

    if (selectedLabel) {
        selectedLabel.textContent = `(${window.selectedAIAppraisalEmpIds.size} selected)`;
    }
    if (selectAllCb) {
        selectAllCb.checked = recs.length > 0 && window.selectedAIAppraisalEmpIds.size === recs.length;
    }
    const card = document.getElementById(`ai-rec-card-${empId}`);
    if (card) {
        if (isChecked) {
            card.classList.add('border-purple-300', 'ring-2', 'ring-purple-100');
            card.classList.remove('border-slate-200');
        } else {
            card.classList.remove('border-purple-300', 'ring-2', 'ring-purple-100');
            card.classList.add('border-slate-200');
        }
    }
}
window.toggleAIAppraisalSelection = toggleAIAppraisalSelection;

async function applySingleAIAppraisalRecommendation(empId) {
    const rec = (window.currentAIAppraisalRecommendations || []).find(r => isSameEmployee(r.employee_id, empId));
    if (!rec) return;

    const btn = document.getElementById(`btn-apply-rec-${empId}`);
    const origHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Applying...';
    }

    try {
        const saved = await PerformanceAPI.submitAppraisal({
            employee_id: rec.employee_id,
            goal_id: rec.goal_id ? parseInt(rec.goal_id, 10) : undefined,
            supervisor_rating: rec.recommended_score,
            criteria_scores: rec.criteria_scores,
            supervisor_notes: rec.supervisor_notes
        });

        const emp = (window.perfRoster || []).find(e => isSameEmployee(e.id, rec.employee_id));
        if (emp) {
            emp.evaluationStatus = 'Rated';
            emp.supervisorRating = rec.recommended_score;
            emp.managerRating = rec.recommended_score;
            emp.tierLabel = saved?.tier_label || rec.tier_label;
            emp.evaluationRecord = saved || rec;
            emp.reviewStatus = 'Pending Calibration';
        }

        if (typeof updateDbEvaluationRecord === 'function' && saved) {
            updateDbEvaluationRecord(saved);
        }

        // Remove from pending recommendation list
        window.currentAIAppraisalRecommendations = (window.currentAIAppraisalRecommendations || []).filter(r => !isSameEmployee(r.employee_id, empId));
        window.selectedAIAppraisalEmpIds.delete(String(empId));

        // Update LocalStorage cache and minimized dock
        setAIAppraisalCache(window.currentAIAppraisalRecommendations);
        updateAIAppraisalMinimizedDock('ready', `${window.currentAIAppraisalRecommendations.length} recommendations remaining`, window.currentAIAppraisalRecommendations.length);

        if (typeof showToast === 'function') {
            showToast(`✓ Applied AI Appraisal for ${rec.employee_name} (${rec.recommended_score.toFixed(2)}/5.0 · ${rec.tier_label})!`, 'success', { duration: 6000 });
        }

        renderAIAppraisalRecommendationsList();
        renderEvaluationRosterTable();
        renderReviewRosterTable();
        renderIDPRosterTable();
        renderCycleRosterTable();
        if (typeof updateAllPerfStepperBadges === 'function') updateAllPerfStepperBadges();
    } catch (err) {
        console.error('Error applying AI recommendation:', err);
        if (typeof showToast === 'function') {
            showToast(err.message || 'Failed to apply recommendation.', 'error');
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    }
}
window.applySingleAIAppraisalRecommendation = applySingleAIAppraisalRecommendation;

async function applySelectedAIAppraisalRecommendations() {
    const selectedIds = Array.from(window.selectedAIAppraisalEmpIds || []);
    if (selectedIds.length === 0) {
        if (typeof showToast === 'function') {
            showToast('Please select at least one candidate recommendation to apply.', 'info');
        }
        return;
    }

    const selectedRecs = (window.currentAIAppraisalRecommendations || []).filter(r => selectedIds.some(sId => isSameEmployee(r.employee_id, sId)));
    if (selectedRecs.length === 0) return;

    const btn = document.getElementById('btn-apply-selected-ai-appraisals');
    const origHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Applying Selected...';
    }

    let appliedCount = 0;
    for (const rec of selectedRecs) {
        try {
            const saved = await PerformanceAPI.submitAppraisal({
                employee_id: rec.employee_id,
                goal_id: rec.goal_id ? parseInt(rec.goal_id, 10) : undefined,
                supervisor_rating: rec.recommended_score,
                criteria_scores: rec.criteria_scores,
                supervisor_notes: rec.supervisor_notes
            });

            const emp = (window.perfRoster || []).find(e => isSameEmployee(e.id, rec.employee_id));
            if (emp) {
                emp.evaluationStatus = 'Rated';
                emp.supervisorRating = rec.recommended_score;
                emp.managerRating = rec.recommended_score;
                emp.tierLabel = saved?.tier_label || rec.tier_label;
                emp.evaluationRecord = saved || rec;
                emp.reviewStatus = 'Pending Calibration';
            }

            if (typeof updateDbEvaluationRecord === 'function' && saved) {
                updateDbEvaluationRecord(saved);
            }
            appliedCount++;
        } catch (err) {
            console.error('Error applying recommendation for', rec.employee_name, err);
        }
    }

    window.currentAIAppraisalRecommendations = (window.currentAIAppraisalRecommendations || []).filter(r => !selectedIds.some(sId => isSameEmployee(r.employee_id, sId)));
    window.selectedAIAppraisalEmpIds.clear();

    // Update LocalStorage cache and minimized dock
    setAIAppraisalCache(window.currentAIAppraisalRecommendations);
    updateAIAppraisalMinimizedDock('ready', `${window.currentAIAppraisalRecommendations.length} recommendations remaining`, window.currentAIAppraisalRecommendations.length);

    if (typeof showToast === 'function') {
        showToast(`✓ Successfully applied AI Appraisal Recommendations for ${appliedCount} associate(s)!`, 'success', { duration: 6000 });
    }

    closeModal('modal-ai-appraisal-recommendations');
    renderEvaluationRosterTable();
    renderReviewRosterTable();
    renderIDPRosterTable();
    renderCycleRosterTable();
    if (typeof updateAllPerfStepperBadges === 'function') updateAllPerfStepperBadges();

    if (btn) {
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
}
window.applySelectedAIAppraisalRecommendations = applySelectedAIAppraisalRecommendations;

async function applyAllAIAppraisalRecommendations() {
    const recs = window.currentAIAppraisalRecommendations || [];
    if (recs.length === 0) {
        if (typeof showToast === 'function') {
            showToast('No pending AI appraisal recommendations to apply.', 'info');
        }
        return;
    }

    const btn = document.getElementById('btn-apply-all-ai-appraisals');
    const origHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Applying All Recommendations...';
    }

    let appliedCount = 0;
    for (const rec of recs) {
        try {
            const saved = await PerformanceAPI.submitAppraisal({
                employee_id: rec.employee_id,
                goal_id: rec.goal_id ? parseInt(rec.goal_id, 10) : undefined,
                supervisor_rating: rec.recommended_score,
                criteria_scores: rec.criteria_scores,
                supervisor_notes: rec.supervisor_notes
            });

            const emp = (window.perfRoster || []).find(e => isSameEmployee(e.id, rec.employee_id));
            if (emp) {
                emp.evaluationStatus = 'Rated';
                emp.supervisorRating = rec.recommended_score;
                emp.managerRating = rec.recommended_score;
                emp.tierLabel = saved?.tier_label || rec.tier_label;
                emp.evaluationRecord = saved || rec;
                emp.reviewStatus = 'Pending Calibration';
            }

            if (typeof updateDbEvaluationRecord === 'function' && saved) {
                updateDbEvaluationRecord(saved);
            }
            appliedCount++;
        } catch (err) {
            console.error('Error applying recommendation for', rec.employee_name, err);
        }
    }

    window.currentAIAppraisalRecommendations = [];
    window.selectedAIAppraisalEmpIds.clear();

    // Clear LocalStorage cache
    setAIAppraisalCache([]);
    updateAIAppraisalMinimizedDock('ready', '0 recommendations remaining', 0);

    if (typeof showToast === 'function') {
        showToast(`✓ Successfully applied AI Appraisal Recommendations for all ${appliedCount} associate(s)!`, 'success', { duration: 6000 });
    }

    closeModal('modal-ai-appraisal-recommendations');
    renderEvaluationRosterTable();
    renderReviewRosterTable();
    renderIDPRosterTable();
    renderCycleRosterTable();
    if (typeof updateAllPerfStepperBadges === 'function') updateAllPerfStepperBadges();

    if (btn) {
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
}
window.applyAllAIAppraisalRecommendations = applyAllAIAppraisalRecommendations;
