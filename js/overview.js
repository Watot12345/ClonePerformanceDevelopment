/**
 * js/overview.js
 * Oxford Suites — Overview Hub Interactive Cards, Realtime Caching & On-Demand Rendering
 */

(function(window) {
    'use strict';

    // ─── 1. REALTIME CLIENT-SIDE CACHE WITH TTL & STALE-WHILE-REVALIDATE ───
    const OVERVIEW_CACHE_TTL_MS = 45000; // 45 seconds TTL
    const _overviewCache = {};
    let _activeModalMetric = null;
    let _overviewModalChartInstance = null;

    function getCachedMetric(metricKey, roleKey = 'Associate') {
        const fullKey = `${metricKey}_${roleKey}`;
        const now = Date.now();
        // 1. Check memory cache
        const memEntry = _overviewCache[fullKey];
        if (memEntry && now - memEntry.timestamp < OVERVIEW_CACHE_TTL_MS) {
            return { data: memEntry.data, isStale: false, age: Math.round((now - memEntry.timestamp) / 1000) };
        }
        if (memEntry) {
            return { data: memEntry.data, isStale: true, age: Math.round((now - memEntry.timestamp) / 1000) };
        }

        // 2. Check sessionStorage
        try {
            const raw = sessionStorage.getItem(`oxford_overview_${fullKey}`);
            if (raw) {
                const parsed = JSON.parse(raw);
                if (parsed && parsed.data && parsed.timestamp) {
                    const age = Math.round((now - parsed.timestamp) / 1000);
                    const isStale = (now - parsed.timestamp >= OVERVIEW_CACHE_TTL_MS);
                    _overviewCache[fullKey] = parsed;
                    return { data: parsed.data, isStale, age };
                }
            }
        } catch (e) {}

        return null;
    }

    function setCachedMetric(metricKey, data, roleKey = 'Associate') {
        const fullKey = `${metricKey}_${roleKey}`;
        const entry = { data, timestamp: Date.now() };
        _overviewCache[fullKey] = entry;
        try {
            sessionStorage.setItem(`oxford_overview_${fullKey}`, JSON.stringify(entry));
        } catch (e) {}
    }

    function invalidateOverviewCache(metricKey) {
        if (metricKey) {
            Object.keys(_overviewCache).forEach(k => {
                if (k.startsWith(metricKey)) delete _overviewCache[k];
            });
            try {
                for (let i = 0; i < sessionStorage.length; i++) {
                    const key = sessionStorage.key(i);
                    if (key && key.startsWith(`oxford_overview_${metricKey}`)) {
                        sessionStorage.removeItem(key);
                    }
                }
            } catch (e) {}
        } else {
            Object.keys(_overviewCache).forEach(k => delete _overviewCache[k]);
            try {
                for (let i = 0; i < sessionStorage.length; i++) {
                    const key = sessionStorage.key(i);
                    if (key && key.startsWith('oxford_overview_')) {
                        sessionStorage.removeItem(key);
                    }
                }
            } catch (e) {}
        }
    }

    // ─── 2. ON-DEMAND MODAL CONTROLLER ───
    async function openOverviewDrilldown(metricKey, options = {}) {
        _activeModalMetric = metricKey;
        const modal = document.getElementById('modal-overview-drilldown');
        if (!modal) return;

        let sessionUser = null;
        try {
            const raw = localStorage.getItem('oxford_session_user');
            if (raw) sessionUser = JSON.parse(raw);
        } catch (e) {}

        const storageRole = (localStorage.getItem('oxford_session_role') || document.documentElement.getAttribute('data-user-role') || '').toLowerCase().trim();
        const activeRole = String(window.activePersonaRole || sessionUser?.role || storageRole || 'Associate').toLowerCase().trim();
        const isSupervisor = ['supervisor', 'manager', 'hradmin', 'generalmanager', 'depthead', 'director'].includes(activeRole) || ['supervisor', 'manager', 'hradmin', 'generalmanager', 'depthead', 'director'].includes(storageRole);

        const empId = options.employeeId || window.currentUser?.id || sessionUser?.id || '';
        const role  = isSupervisor ? 'Supervisor' : 'Associate';

        // 1. Instant Stale-While-Revalidate Display from Cache (0ms latency!)
        const cached = getCachedMetric(metricKey, role);
        let hasShownData = false;

        if (cached && !options.forceRefresh) {
            renderModalData(cached.data, cached.isStale, cached.age);
            hasShownData = true;
        }

        // Show Modal immediately
        if (typeof openModal === 'function') {
            openModal('modal-overview-drilldown');
        } else {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        // 2. If no cache was available, show a lightweight shimmer
        if (!hasShownData) {
            showModalLoadingState();
        }

        // 3. If stale or no cache or force refresh, fetch live data in background
        if (!cached || cached.isStale || options.forceRefresh) {
            try {
                const cacheBuster = options.forceRefresh ? `&_t=${Date.now()}` : '';
                const res = await fetch(`api/overview.php?action=get_drilldown&metric=${encodeURIComponent(metricKey)}&employee_id=${encodeURIComponent(empId)}&role=${encodeURIComponent(role)}${cacheBuster}`);
                const json = await res.json();

                if (json && json.success && json.data) {
                    setCachedMetric(metricKey, json.data, role);
                    // Only update UI if user is still looking at this metric
                    if (_activeModalMetric === metricKey) {
                        renderModalData(json.data, false, 0);
                    }
                } else if (!hasShownData) {
                    showModalError(json?.error || 'Unable to load drilldown metric from database.');
                }
            } catch (err) {
                console.warn('[Overview Drilldown] Fetch error:', err);
                if (!hasShownData) {
                    showModalError('Network error connecting to telemetry service.');
                }
            }
        }
    }

    function showModalLoadingState() {
        const bodyEl = document.getElementById('overview-modal-body');
        if (!bodyEl) return;
        bodyEl.innerHTML = `
            <div class="flex flex-col items-center justify-center py-16 space-y-3 text-center">
                <div class="w-10 h-10 rounded-full border-3 border-primary/20 border-t-primary animate-spin"></div>
                <div class="space-y-1">
                    <p class="font-bold text-sm text-slate-800">Querying Database...</p>
                    <p class="text-xs text-slate-400">Loading</p>
                </div>
            </div>
        `;
    }

    function showModalError(msg) {
        const bodyEl = document.getElementById('overview-modal-body');
        if (!bodyEl) return;
        bodyEl.innerHTML = `
            <div class="p-8 text-center space-y-3">
                <div class="w-12 h-12 rounded-full bg-rose-50 text-rose-500 mx-auto flex items-center justify-center text-xl">
                    <i class="fas fa-triangle-exclamation"></i>
                </div>
                <h4 class="font-heading font-bold text-sm text-slate-800">Unable to Load Telemetry</h4>
                <p class="text-xs text-slate-500 max-w-sm mx-auto">${escapeHtml(msg)}</p>
                <button type="button" onclick="refreshCurrentOverviewModal()" class="btn-primary px-4 py-1.5 text-xs font-bold inline-flex items-center space-x-1.5">
                    <i class="fas fa-rotate text-xs"></i>
                    <span>Try Again</span>
                </button>
            </div>
        `;
    }

    // Render structured modal content
    function renderModalData(payload, isStale = false, ageSec = 0) {
        if (!payload) return;

        // Header elements
        const titleEl = document.getElementById('overview-modal-title');
        const subEl   = document.getElementById('overview-modal-subtitle');
        const badgeEl = document.getElementById('overview-modal-cache-badge');
        const timeEl  = document.getElementById('overview-modal-timestamp');
        const infoEl  = document.getElementById('overview-modal-cache-info');
        const iconEl  = document.getElementById('overview-modal-icon');
        const iconBg  = document.getElementById('overview-modal-icon-bg');
        const actBtn  = document.getElementById('overview-modal-action-btn');

        if (titleEl) titleEl.textContent = payload.title || 'Metric Detail';
        if (subEl)   subEl.textContent   = payload.subtitle || 'Telemetry & granular drilldown';

        // Set theme colors & icons
        const theme = payload.theme || 'primary';
        let iconClass = 'fa-chart-line';
        let colorBg = 'bg-primary/10 text-primary';

        if (theme === 'gold') {
            iconClass = 'fa-trophy';
            colorBg = 'bg-amber-100 text-amber-700';
        } else if (theme === 'sage') {
            iconClass = 'fa-bullseye';
            colorBg = 'bg-emerald-100 text-emerald-800';
        } else if (theme === 'dusty') {
            iconClass = 'fa-sitemap';
            colorBg = 'bg-sky-100 text-sky-800';
        } else if (theme === 'terracotta') {
            iconClass = 'fa-scale-balanced';
            colorBg = 'bg-orange-100 text-orange-800';
        }

        if (iconEl) iconEl.className = `fas ${iconClass}`;
        if (iconBg) iconBg.className = `w-10 h-10 rounded-2xl ${colorBg} flex items-center justify-center text-lg font-bold shrink-0`;

        // Cache Status Badges
        if (badgeEl) {
            if (isStale) {
                badgeEl.className = 'badge-dusty text-[10px]';
                badgeEl.innerHTML = `<i class="fas fa-rotate mr-1 animate-spin"></i>Refreshing...`;
            } else if (ageSec > 0) {
                badgeEl.className = 'badge-sage text-[10px]';
                badgeEl.innerHTML = `<i class="fas fa-bolt mr-1"></i>Cached (${ageSec}s ago)`;
            } else {
                badgeEl.className = 'badge-sage text-[10px]';
                badgeEl.innerHTML = `<i class="fas fa-circle-check mr-1"></i>Live Data`;
            }
        }

        if (timeEl) {
            timeEl.textContent = ageSec > 0 ? `Cached ${ageSec}s ago` : 'Synced just now';
        }
        if (infoEl) {
            infoEl.textContent = 'Realtime TTL 45s';
        }

        // Action button to jump to relevant Pillar
        if (actBtn) {
            const roleName = String(window.currentUser?.role || window.activePersonaRole || JSON.parse(localStorage.getItem('oxford_session_user') || '{}').role || '').toLowerCase().trim();
            const isAssociateRole = (roleName === 'associate' || roleName === 'employee' || roleName === 'staff');
            const targetPillar = payload.target_pillar || 'pillar-perf';
            const actionLabel  = payload.action_label || '';

            if (!actionLabel || (isAssociateRole && (payload.metric === 'active_objectives' || payload.metric === 'goals_progress' || actionLabel.toLowerCase().includes('performance planning')))) {
                actBtn.classList.add('hidden');
                actBtn.style.display = 'none';
            } else {
                actBtn.classList.remove('hidden');
                actBtn.style.display = 'inline-flex';
                actBtn.innerHTML = `<span>${escapeHtml(actionLabel)}</span><i class="fas fa-arrow-right text-[10px] ml-1.5"></i>`;
                actBtn.onclick = function() {
                    if (typeof closeModal === 'function') closeModal('modal-overview-drilldown');
                    if (typeof switchPillar === 'function') switchPillar(targetPillar);
                };
            }
        }

        // Construct Body HTML
        const bodyEl = document.getElementById('overview-modal-body');
        if (!bodyEl) return;

        let html = '';

        // 1. Quick KPI Metric Summary Grid (4 Tiles)
        if (Array.isArray(payload.summary) && payload.summary.length > 0) {
            html += `<div class="grid grid-cols-2 sm:grid-cols-4 gap-3">`;
            payload.summary.forEach(item => {
                html += `
                    <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200/80 space-y-1">
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block truncate">${escapeHtml(item.label)}</span>
                        <p class="text-xl font-heading font-extrabold text-slate-900 leading-tight truncate">${escapeHtml(String(item.value))}</p>
                        <p class="text-[10px] text-slate-500 truncate">${escapeHtml(item.sub || '')}</p>
                    </div>
                `;
            });
            html += `</div>`;
        }

        // 2. Interactive Chart Canvas Container (Rendered on demand with Empty State)
        if (payload.chart) {
            const chartData = payload.chart.data || (payload.chart.datasets ? payload.chart.datasets.flatMap(d => d.data || []) : []);
            const hasData = chartData.some(v => parseFloat(v) > 0);

            if (!hasData) {
                html += `
                    <div class="p-6 rounded-2xl bg-white border border-slate-200 text-center space-y-3 shadow-2xs">
                        <div class="flex items-center justify-between border-b border-slate-100 pb-2.5">
                            <span class="font-bold text-xs text-slate-800 flex items-center space-x-1.5">
                                <i class="fas fa-chart-pie text-slate-400 text-xs"></i>
                                <span>Objectives Distribution &amp; Status</span>
                            </span>
                            <span class="badge-dusty text-[10px]">No Data Recorded</span>
                        </div>
                        <div class="py-6 flex flex-col items-center justify-center space-y-2.5">
                            <div class="w-12 h-12 rounded-2xl bg-slate-50 border border-slate-200/80 flex items-center justify-center text-slate-300 text-xl shadow-2xs">
                                <i class="fas fa-bullseye"></i>
                            </div>
                            <div class="space-y-1">
                                <p class="font-heading font-bold text-xs text-slate-800">No Performance Objectives Recorded</p>
                                <p class="text-[11px] text-slate-400 max-w-sm mx-auto leading-relaxed">You currently do not have any active or approved Q3 SMART objectives in this cycle. Set an objective to track progress velocity.</p>
                            </div>
                            <button type="button" onclick="if(typeof closeModal==='function') closeModal('modal-overview-drilldown'); if(typeof openModal==='function') openModal('modal-create-goal');" class="btn-primary px-3.5 py-1.5 text-xs font-bold inline-flex items-center space-x-1.5 mt-1.5 shadow-2xs">
                                <i class="fas fa-plus text-[10px]"></i>
                                <span>+ Set Q3 Objective</span>
                            </button>
                        </div>
                    </div>
                `;
            } else {
                html += `
                    <div class="p-4 rounded-2xl bg-white border border-slate-200 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-xs text-slate-800 flex items-center space-x-1.5">
                                <i class="fas fa-chart-simple text-primary text-xs"></i>
                                <span>Telemetry Visual Distribution</span>
                            </span>
                            <span class="text-[10px] text-slate-400 font-medium">Interactive Chart</span>
                        </div>
                        <div class="h-52 w-full relative">
                            <canvas id="overview-modal-chart"></canvas>
                        </div>
                    </div>
                `;
            }
        }

        // 3. Department Breakdown (if available)
        if (Array.isArray(payload.department_breakdown) && payload.department_breakdown.length > 0) {
            html += `
                <div class="p-4 rounded-2xl bg-slate-50/70 border border-slate-200 space-y-2.5">
                    <span class="font-bold text-xs text-slate-800 block">Departmental Execution Breakdown</span>
                    <div class="grid grid-cols-1 sm:grid-cols-5 gap-2">
            `;
            payload.department_breakdown.forEach(dept => {
                html += `
                    <div class="p-2.5 rounded-xl bg-white border border-slate-200 text-center space-y-1 shadow-2xs">
                        <span class="text-[10px] font-bold text-slate-700 block truncate" title="${escapeHtml(dept.department)}">${escapeHtml(dept.department)}</span>
                        <span class="text-base font-heading font-extrabold text-slate-900 block">${dept.rate_pct}%</span>
                        <div class="w-full bg-slate-100 h-1 rounded-full overflow-hidden">
                            <div class="bg-primary h-1 rounded-full" style="width: ${Math.min(100, dept.rate_pct)}%"></div>
                        </div>
                        <span class="text-[9px] text-slate-400 block">${dept.approved || 0} / ${dept.total || 0}</span>
                    </div>
                `;
            });
            html += `</div></div>`;
        }

        // 4. Granular Items Table / List
        if (Array.isArray(payload.items) && payload.items.length > 0) {
            html += `
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-xs text-slate-800">Granular Telemetry Records</span>
                        <span class="text-[10px] text-slate-400 font-medium">Showing ${payload.items.length} records</span>
                    </div>
                    <div class="rounded-2xl border border-slate-200 overflow-hidden">
                        <div class="max-h-64 overflow-y-auto custom-scrollbar">
                            <table class="w-full text-left text-xs divide-y divide-slate-100">
                                <tbody class="divide-y divide-slate-100 bg-white">
            `;

            payload.items.forEach(it => {
                html += renderDrilldownRow(payload.metric, it);
            });

            html += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            `;
        } else if (!payload.items || payload.items.length === 0) {
            html += `
                <div class="p-6 rounded-2xl bg-slate-50 border border-slate-200/80 text-center space-y-2">
                    <div class="w-9 h-9 rounded-xl bg-white border border-slate-200 flex items-center justify-center text-slate-300 text-sm mx-auto shadow-2xs">
                        <i class="fas fa-list-check"></i>
                    </div>
                    <p class="font-heading font-bold text-xs text-slate-700">No Records Found</p>
                    <p class="text-[11px] text-slate-400">There are no granular records matching this cycle filter.</p>
                </div>
            `;
        }

        bodyEl.innerHTML = html;

        // 5. Lazy-render Chart.js on the modal canvas AFTER DOM is visible!
        if (payload.chart) {
            const chartData = payload.chart.data || (payload.chart.datasets ? payload.chart.datasets.flatMap(d => d.data || []) : []);
            const hasData = chartData.some(v => parseFloat(v) > 0);
            if (hasData) {
                requestAnimationFrame(() => {
                    renderModalChart(payload.chart);
                });
            }
        }
    }

    // Helper to render individual row based on metric type
    function renderDrilldownRow(metric, item) {
        if (!item) return '';

        if (metric === 'goals_progress' || metric === 'sys_goals' || metric === 'active_objectives' || metric === 'shift_action') {
            const st = String(item.status || 'Active').toLowerCase();
            let badgeClass = 'badge-primary';
            if (st.includes('approved') || st.includes('completed') || st.includes('done')) badgeClass = 'badge-sage';
            else if (st.includes('review') || st.includes('pending')) badgeClass = 'badge-dusty';
            else if (st.includes('revise') || st.includes('failed')) badgeClass = 'badge-terracotta';

            return `
                <tr class="hover:bg-slate-50/70 transition-colors">
                    <td class="p-3">
                        <p class="font-bold text-slate-900 leading-tight">${escapeHtml(item.title || 'SMART Objective')}</p>
                        <p class="text-[10px] text-slate-500 mt-0.5">${escapeHtml(item.employee_name || 'Staff')} · <span class="text-slate-400">${escapeHtml(item.department || 'Operations')}</span></p>
                    </td>
                    <td class="p-3 text-right whitespace-nowrap">
                        <span class="${badgeClass}">${escapeHtml(item.status || 'Active')}</span>
                        <p class="text-[10px] text-slate-400 mt-1">${escapeHtml(item.weight || 'Standard Weight')}</p>
                    </td>
                </tr>
            `;
        }

        if (metric === 'competencies' || metric === 'competency_matrix' || metric === 'my_competencies') {
            const isAssessed = item.current_score !== null && item.current_score !== undefined && item.current_score !== '';
            const bench = parseFloat(item.benchmark_score || 4.5);
            let scoreDisplay = '';
            let badgeHtml = '';

            if (isAssessed) {
                const curr = parseFloat(item.current_score);
                const gap = curr - bench;
                const gapLabel = gap >= 0 ? `+${gap.toFixed(1)} Benchmark` : `${gap.toFixed(1)} Gap`;
                const badgeClass = gap >= 0 ? 'badge-sage' : (curr >= 3.8 ? 'badge-gold' : 'badge-terracotta');
                scoreDisplay = `
                    <span class="font-bold text-slate-800 text-xs">${curr.toFixed(1)}</span>
                    <span class="text-[10px] text-slate-400"> / ${bench.toFixed(1)} Target</span>
                `;
                badgeHtml = `<span class="${badgeClass}">${gapLabel}</span>`;
            } else {
                scoreDisplay = `
                    <span class="font-semibold text-slate-400 text-xs">—</span>
                    <span class="text-[10px] text-slate-400"> / ${bench.toFixed(1)} Target</span>
                `;
                badgeHtml = `<span class="badge-dusty text-[10px]">Not Rated Yet</span>`;
            }

            return `
                <tr class="hover:bg-slate-50/70 transition-colors">
                    <td class="p-3">
                        <p class="font-bold text-slate-900 leading-tight">${escapeHtml(item.name || 'Competency Standard')}</p>
                        <p class="text-[10px] text-slate-500 mt-0.5">${escapeHtml(item.category || item.scope || 'Core Hospitality')}${item.scope ? ` · <span class="font-semibold text-slate-400">${escapeHtml(item.scope)}</span>` : ''}</p>
                    </td>
                    <td class="p-3 text-center whitespace-nowrap">
                        ${scoreDisplay}
                    </td>
                    <td class="p-3 text-right whitespace-nowrap">
                        ${badgeHtml}
                    </td>
                </tr>
            `;
        }

        if (metric === 'xp_ledger' || metric === 'sys_xp' || metric === 'xp_trajectory') {
            const pts = parseInt(item.points || 0, 10);
            return `
                <tr class="hover:bg-slate-50/70 transition-colors">
                    <td class="p-3">
                        <div class="flex items-center space-x-2">
                            <span class="w-6 h-6 rounded-full bg-gold-50 text-gold-dark font-bold text-[10px] flex items-center justify-center border border-gold-200">
                                <i class="fas fa-medal text-[9px]"></i>
                            </span>
                            <div>
                                <p class="font-bold text-slate-900 leading-tight">${escapeHtml(item.recipient_name || 'Staff Member')}</p>
                                <p class="text-[10px] text-slate-500">${escapeHtml(item.description || item.source_type || 'XP Reward')}</p>
                            </div>
                        </div>
                    </td>
                    <td class="p-3 text-right whitespace-nowrap">
                        <span class="font-heading font-bold text-gold-dark text-xs">+${pts.toLocaleString()} XP</span>
                        <p class="text-[9px] text-slate-400 mt-0.5">${item.created_at ? new Date(item.created_at).toLocaleDateString() : 'Recent'}</p>
                    </td>
                </tr>
            `;
        }

        if (metric === 'champions_podium' || metric === 'leaderboard') {
            const rank = parseInt(item.rank || 1, 10);
            const xp = parseInt(item.total_xp || 0, 10);
            return `
                <tr class="hover:bg-slate-50/70 transition-colors">
                    <td class="p-3 flex items-center space-x-2.5">
                        <span class="w-6 h-6 rounded-full font-heading font-black text-xs flex items-center justify-center ${rank <= 3 ? 'bg-amber-400 text-white' : 'bg-slate-100 text-slate-600'}">
                            ${rank}
                        </span>
                        <div>
                            <p class="font-bold text-slate-900">${escapeHtml(item.name || 'Associate')}</p>
                            <p class="text-[10px] text-slate-500">${escapeHtml(item.role || 'Associate')} · ${escapeHtml(item.department || 'Operations')}</p>
                        </div>
                    </td>
                    <td class="p-3 text-right whitespace-nowrap">
                        <span class="font-heading font-extrabold text-gold-dark text-xs">${xp.toLocaleString()} XP</span>
                        <span class="badge-gold text-[9px] ml-2">${escapeHtml(item.tier || 'Associate')}</span>
                    </td>
                </tr>
            `;
        }

        if (metric === 'lms_compliance' || metric === 'sys_lms') {
            const prog = parseFloat(item.progress || 0);
            const st = String(item.status || 'Enrolled').toLowerCase();
            const badgeClass = (st.includes('passed') || prog >= 80) ? 'badge-sage' : 'badge-dusty';

            return `
                <tr class="hover:bg-slate-50/70 transition-colors">
                    <td class="p-3">
                        <p class="font-bold text-slate-900">${escapeHtml(item.lms_id || item.title || 'SOP Handbook Quiz')}</p>
                        <p class="text-[10px] text-slate-500">${escapeHtml(item.employee_name || item.employee || 'Associate')} · ${escapeHtml(item.department || 'Front Office')}</p>
                    </td>
                    <td class="p-3 text-right whitespace-nowrap">
                        <span class="${badgeClass}">${prog}% Score</span>
                        <p class="text-[9px] text-slate-400 mt-0.5">${prog >= 80 ? 'Certified' : 'In Progress'}</p>
                    </td>
                </tr>
            `;
        }

        if (metric === 'succession' || metric === 'sys_succession') {
            return `
                <tr class="hover:bg-slate-50/70 transition-colors">
                    <td class="p-3">
                        <p class="font-bold text-slate-900">${escapeHtml(item.title || 'Key Leadership Position')}</p>
                        <p class="text-[10px] text-slate-500">Incumbent: <strong>${escapeHtml(item.incumbent_name || 'Active Incumbent')}</strong> · ${escapeHtml(item.dept || 'Operations')}</p>
                    </td>
                    <td class="p-3 text-right whitespace-nowrap">
                        <span class="${item.primary_name && !item.primary_name.includes('No Primary') ? 'badge-sage' : 'badge-terracotta'}">
                            ${escapeHtml(item.primary_name || 'Successor Ready')}
                        </span>
                        <p class="text-[9px] text-slate-400 mt-0.5">Risk: ${escapeHtml(item.risk_of_loss || 'Moderate')}</p>
                    </td>
                </tr>
            `;
        }

        if (metric === 'shift_sentiment' || metric === 'shift_climate') {
            const sc = parseInt(item.sentiment_score || 3, 10);
            let emoji = '😊';
            let label = 'Smooth';
            let badge = 'badge-sage';
            if (sc === 3) { emoji = '😐'; label = 'Manageable'; badge = 'badge-dusty'; }
            else if (sc < 3) { emoji = '😟'; label = 'Friction'; badge = 'badge-terracotta'; }

            return `
                <tr class="hover:bg-slate-50/70 transition-colors">
                    <td class="p-3 flex items-center space-x-2.5">
                        <span class="text-xl">${emoji}</span>
                        <div>
                            <p class="font-bold text-slate-900">${escapeHtml(item.employee_name || 'Associate')}</p>
                            <p class="text-[10px] text-slate-500">${escapeHtml(item.note || item.shift_period || 'Shift check-in logged')}</p>
                        </div>
                    </td>
                    <td class="p-3 text-right whitespace-nowrap">
                        <span class="${badge}">${label}</span>
                        <p class="text-[9px] text-slate-400 mt-0.5">${item.created_at ? new Date(item.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : 'Today'}</p>
                    </td>
                </tr>
            `;
        }

        // Generic fallback row
        return `
            <tr class="hover:bg-slate-50/70 transition-colors">
                <td class="p-3 font-bold text-slate-800">${escapeHtml(item.department || item.rule || item.name || 'Operational Item')}</td>
                <td class="p-3 text-right text-slate-500">${escapeHtml(item.status || item.details || '')}</td>
            </tr>
        `;
    }

    // Lazy Chart Renderer inside the drilldown modal
    function renderModalChart(chartConfig) {
        const canvas = document.getElementById('overview-modal-chart');
        if (!canvas || typeof Chart === 'undefined') return;

        if (_overviewModalChartInstance) {
            _overviewModalChartInstance.destroy();
            _overviewModalChartInstance = null;
        }

        const type = chartConfig.type || 'doughnut';
        let datasets = [];

        if (chartConfig.datasets) {
            datasets = chartConfig.datasets;
        } else {
            datasets = [{
                data: chartConfig.data || [],
                backgroundColor: chartConfig.colors || ['#7A9A7E', '#C89B3C', '#9E1B20', '#6B8FA3', '#C47762'],
                borderRadius: type === 'bar' ? 4 : 0
            }];
        }

        _overviewModalChartInstance = new Chart(canvas, {
            type: type,
            data: {
                labels: chartConfig.labels || [],
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: type === 'doughnut' ? 'right' : 'top',
                        labels: { boxWidth: 10, font: { size: 10, family: 'Inter' } }
                    }
                },
                scales: (type === 'radar') ? {
                    r: {
                        min: 0,
                        max: 5,
                        ticks: { stepSize: 1, display: false },
                        pointLabels: { font: { size: 9, family: 'Inter', weight: '600' }, color: '#211A1A' }
                    }
                } : (type === 'bar' || type === 'line') ? {
                    y: { grid: { color: '#F1E9E7' }, ticks: { font: { size: 10, family: 'Inter' } } },
                    x: { grid: { display: false }, ticks: { font: { size: 10, family: 'Inter' } } }
                } : {}
            }
        });
    }

    function refreshCurrentOverviewModal() {
        if (_activeModalMetric) {
            openOverviewDrilldown(_activeModalMetric, { forceRefresh: true });
        }
    }

    // ─── 3. ON-DEMAND RENDERING FOR OVERVIEW TABS & CHARTS ───
    // Defer chart instantiation until the user actually requests to view that tab/pillar!
    let _hasRenderedPulseCharts = false;
    let _hasRenderedSystemCharts = false;

    function renderPulseChartsOnDemand() {
        if (_hasRenderedPulseCharts) {
            if (window.chartPerfTrendInstance) window.chartPerfTrendInstance.resize();
            return;
        }
        _hasRenderedPulseCharts = true;

        const ctxPerf = document.getElementById('chart-performance-trend');
        if (ctxPerf && typeof Chart !== 'undefined' && !window.chartPerfTrendInstance) {
            window.chartPerfTrendInstance = new Chart(ctxPerf, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'XP Received',
                        data: [],
                        borderColor: '#C89B3C',
                        backgroundColor: 'rgba(200, 155, 60, 0.12)',
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.35,
                        pointBackgroundColor: '#C89B3C',
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top', labels: { boxWidth: 10, font: { size: 10, family: 'Inter' } } },
                        tooltip: {
                            callbacks: {
                                label: function(context) { return ` ${context.dataset.label}: ${context.parsed.y} XP`; }
                            }
                        }
                    },
                    scales: {
                        y: { min: 0, grid: { color: '#F1E9E7' }, ticks: { font: { size: 10, family: 'Inter' }, callback: val => val + ' XP' } },
                        x: { grid: { display: false }, ticks: { font: { size: 10, family: 'Inter' } } }
                    }
                }
            });

            if (typeof updateXpTrajectoryFromLedger === 'function') {
                updateXpTrajectoryFromLedger();
            }
        }
    }

    function renderSystemChartsOnDemand() {
        // Chart: Department Execution Matrix
        if (typeof getOrCreateDeptProgressChart === 'function') {
            getOrCreateDeptProgressChart();
        } else {
            const ctxDeptProgress = document.getElementById('chart-system-dept-progress');
            if (ctxDeptProgress && typeof Chart !== 'undefined' && !window.chartSystemDeptProgressInstance) {
                let existing = typeof Chart.getChart === 'function' ? Chart.getChart(ctxDeptProgress) : null;
                if (existing) {
                    window.chartSystemDeptProgressInstance = existing;
                } else {
                    let initLabels = ['Front Office', 'Food & Beverage', 'Kitchen & Culinary', 'Banquet & Events', 'Housekeeping'];
                    let initGoals = [0, 0, 0, 0, 0];
                    let initLms = [0, 0, 0, 0, 0];
                    let initSucc = [0, 0, 0, 0, 0];

                    if (Array.isArray(window.initialDeptMatrixData) && window.initialDeptMatrixData.length > 0) {
                        initLabels = window.initialDeptMatrixData.map(r => r.department || '');
                        initGoals = window.initialDeptMatrixData.map(r => parseFloat(r.goals_approved_pct || 0));
                        initLms = window.initialDeptMatrixData.map(r => parseFloat(r.lms_rate_pct || 0));
                        initSucc = window.initialDeptMatrixData.map(r => parseFloat(r.succession_ready_pct || 0));
                    }

                    window.chartSystemDeptProgressInstance = new Chart(ctxDeptProgress, {
                        type: 'bar',
                        data: {
                            labels: initLabels,
                            datasets: [
                                { label: 'Goals Approved (%)', data: initGoals, backgroundColor: '#7A9A7E', borderRadius: 4 },
                                { label: 'LMS Completion (%)', data: initLms, backgroundColor: '#9E1B20', borderRadius: 4 },
                                { label: 'Succession Ready (%)', data: initSucc, backgroundColor: '#6B8FA3', borderRadius: 4 }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { position: 'top', labels: { boxWidth: 10, font: { size: 10, family: 'Inter' } } } },
                            scales: {
                                y: { min: 0, max: 100, grid: { color: '#F1E9E7' }, ticks: { font: { size: 10, family: 'Inter' }, callback: val => val + '%' } },
                                x: { grid: { display: false }, ticks: { font: { size: 10, family: 'Inter' } } }
                            }
                        }
                    });
                }
            }
        }

        if (window.chartSystemDeptProgressInstance) {
            try {
                window.chartSystemDeptProgressInstance.resize();
                window.chartSystemDeptProgressInstance.update();
            } catch (e) {}
        }

        if (typeof fetchAndRenderDepartmentExecutionMatrix === 'function') {
            fetchAndRenderDepartmentExecutionMatrix();
        }

        // Chart: Shift Climate Pulse Doughnut
        const ctxSentiment = document.getElementById('chart-sentiment-doughnut');
        if (ctxSentiment && typeof updateShiftClimatePulseFromSupabase === 'function') {
            updateShiftClimatePulseFromSupabase(window.shiftSentimentsState || null);
        }
        if (window.chartSentimentDoughnutInstance) {
            try { window.chartSentimentDoughnutInstance.resize(); } catch (e) {}
        }
    }

    // Helper: Escape HTML
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function destroyOverviewModalChart() {
        if (_overviewModalChartInstance) {
            _overviewModalChartInstance.destroy();
            _overviewModalChartInstance = null;
        }
    }

    // Expose API to Global Window
    window.openOverviewDrilldown = openOverviewDrilldown;
    window.refreshCurrentOverviewModal = refreshCurrentOverviewModal;
    window.invalidateOverviewCache = invalidateOverviewCache;
    window.renderPulseChartsOnDemand = renderPulseChartsOnDemand;
    window.renderSystemChartsOnDemand = renderSystemChartsOnDemand;
    window.destroyOverviewModalChart = destroyOverviewModalChart;

    // Attach listeners on load: initialize visible subtab and observe modal dismissal
    document.addEventListener('DOMContentLoaded', () => {
        const pulsePanel = document.getElementById('sub-dashboard-pulse');
        const systemPanel = document.getElementById('sub-dashboard-system');

        if (pulsePanel && pulsePanel.classList.contains('active')) {
            renderPulseChartsOnDemand();
        } else if (systemPanel && systemPanel.classList.contains('active')) {
            renderSystemChartsOnDemand();
        }

        // Auto-cleanup modal chart memory whenever the drilldown modal is closed
        const modalEl = document.getElementById('modal-overview-drilldown');
        if (modalEl && window.MutationObserver) {
            const observer = new MutationObserver(() => {
                if (modalEl.classList.contains('hidden')) {
                    destroyOverviewModalChart();
                    _activeModalMetric = null;
                }
            });
            observer.observe(modalEl, { attributes: true, attributeFilter: ['class'] });
        }
    });

})(window);
