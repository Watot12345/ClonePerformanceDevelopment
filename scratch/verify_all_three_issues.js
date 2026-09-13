const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');

async function main() {
    const chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    const port = 9255;
    const chrome = spawn(chromePath, [
        '--headless=new',
        `--remote-debugging-port=${port}`,
        '--disable-gpu',
        '--no-first-run',
        '--no-default-browser-check',
        '--window-size=1440,960'
    ]);

    await new Promise(r => setTimeout(r, 1200));

    try {
        const tabs = await new Promise((resolve, reject) => {
            http.get(`http://127.0.0.1:${port}/json/list`, res => {
                let data = '';
                res.on('data', chunk => data += chunk);
                res.on('end', () => resolve(JSON.parse(data)));
            }).on('error', reject);
        });

        const targetTab = tabs.find(t => t.type === 'page') || tabs[0];
        const wsUrl = targetTab.webSocketDebuggerUrl;

        const ws = new WebSocket(wsUrl);
        await new Promise(r => ws.onopen = r);

        let msgId = 1;
        function send(method, params = {}) {
            return new Promise(resolve => {
                const id = msgId++;
                const handler = (evt) => {
                    const res = JSON.parse(evt.data);
                    if (res.id === id) {
                        ws.removeEventListener('message', handler);
                        resolve(res.result);
                    }
                };
                ws.addEventListener('message', handler);
                ws.send(JSON.stringify({ id, method, params }));
            });
        }

        await send('Page.enable');
        await send('Runtime.enable');

        // Set Supervisor user in localStorage
        await send('Page.addScriptToEvaluateOnNewDocument', {
            source: `
                try {
                    localStorage.setItem('oxford_session_auth', 'true');
                    localStorage.setItem('oxford_session_role', 'supervisor');
                    localStorage.setItem('oxford_session_user', JSON.stringify({
                        id: 'emp-102',
                        employee_code: 'EMP-102',
                        name: 'Chef Marco Rossi',
                        full_name: 'Chef Marco Rossi',
                        role: 'Supervisor',
                        department: 'Food & Beverage',
                        title: 'Executive Chef'
                    }));
                    localStorage.setItem('oxford_active_tab', 'overview');
                    localStorage.setItem('oxford_active_subtab_dashboard', 'system');
                } catch(e) {}
            `
        });

        function navigateWithTimeout(url, timeoutMs = 8000) {
            return new Promise((resolve) => {
                let resolved = false;
                const timer = setTimeout(() => {
                    if (!resolved) {
                        resolved = true;
                        resolve();
                    }
                }, timeoutMs);

                const handler = (evt) => {
                    const msg = JSON.parse(evt.data);
                    if (msg.method === 'Page.loadEventFired' && !resolved) {
                        resolved = true;
                        clearTimeout(timer);
                        ws.removeEventListener('message', handler);
                        resolve();
                    }
                };
                ws.addEventListener('message', handler);
                send('Page.navigate', { url });
            });
        }

        console.log('1. Navigating to index.php in Supervisor mode...');
        await navigateWithTimeout('http://localhost:8080/index.php');
        await new Promise(r => setTimeout(r, 2500));

        console.log('2. Verifying Overview tab & Department Execution Matrix...');
        const overviewRes = await send('Runtime.evaluate', {
            returnByValue: true,
            expression: `
                (function() {
                    const canvas = document.getElementById('chart-system-dept-progress');
                    const overlay = document.getElementById('dept-matrix-loading-overlay');
                    const chart = window.chartSystemDeptProgressInstance || (canvas && typeof Chart !== 'undefined' ? Chart.getChart(canvas) : null);
                    const tbody = document.getElementById('table-dept-execution-matrix-body');
                    const rowsCount = tbody ? tbody.querySelectorAll('tr').length : 0;

                    return {
                        canvasExists: !!canvas,
                        canvasWidth: canvas ? canvas.width : 0,
                        canvasHeight: canvas ? canvas.height : 0,
                        overlayDisplay: overlay ? window.getComputedStyle(overlay).display : null,
                        overlayClasses: overlay ? overlay.className : null,
                        chartExists: !!chart,
                        chartLabels: chart ? chart.data.labels : null,
                        chartDatasetsCount: chart ? chart.data.datasets.length : 0,
                        tableRowsCount: rowsCount
                    };
                })()
            `
        });
        console.log('Overview Matrix Status:', JSON.stringify(overviewRes.result.value, null, 2));

        const shot1 = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync('scratch/verified_dept_matrix.png', Buffer.from(shot1.data, 'base64'));

        console.log('3. Navigating/Switching to Social Recognition tab...');
        await send('Runtime.evaluate', {
            expression: `
                if (typeof switchTab === 'function') {
                    switchTab('social');
                } else {
                    const btn = document.querySelector('[data-tab="social"]');
                    if (btn) btn.click();
                }
            `
        });
        await new Promise(r => setTimeout(r, 2500));

        console.log('4. Verifying Social Recognition Champions, Employee Table, and Points Ledger...');
        const socialRes = await send('Runtime.evaluate', {
            returnByValue: true,
            expression: `
                (function() {
                    // Podium Champions
                    const podiumCards = Array.from(document.querySelectorAll('#kudos-champions-podium > div, .podium-card, [id*="champion"]')).map(el => el.textContent.trim());
                    
                    // All employees table
                    const empTbody = document.getElementById('supervisor-employees-xp-tbody');
                    const empRows = empTbody ? Array.from(empTbody.querySelectorAll('tr')).map(r => {
                        const cols = Array.from(r.querySelectorAll('td')).map(c => c.textContent.trim().replace(/\\s+/g, ' '));
                        return cols.join(' | ');
                    }) : [];

                    // Ledger table
                    const ledgerTbody = document.getElementById('points-ledger-tbody');
                    const ledgerRows = ledgerTbody ? Array.from(ledgerTbody.querySelectorAll('tr')).map(r => {
                        const cols = Array.from(r.querySelectorAll('td')).map(c => c.textContent.trim().replace(/\\s+/g, ' '));
                        return cols.join(' | ');
                    }) : [];

                    // Badge text
                    const staffBadge = document.getElementById('supervisor-staff-count-badge');

                    return {
                        isSupervisorState: typeof isSupervisorViewState !== 'undefined' ? isSupervisorViewState : null,
                        allEmployeesCount: typeof allEmployeesXpState !== 'undefined' ? allEmployeesXpState.length : 0,
                        allEmployees: (typeof allEmployeesXpState !== 'undefined' ? allEmployeesXpState : []).map(e => ({
                            name: e.name, id: e.employee_id, xp: e.total_xp, rank: e.rank
                        })),
                        staffBadgeText: staffBadge ? staffBadge.textContent.trim() : null,
                        empTableRowsCount: empRows.length,
                        empTableRows: empRows,
                        ledgerRowsCount: ledgerRows.length,
                        ledgerRows: ledgerRows
                    };
                })()
            `
        });
        console.log('Social Recognition Status:', JSON.stringify(socialRes.result.value, null, 2));

        const shot2 = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync('scratch/verified_social_view.png', Buffer.from(shot2.data, 'base64'));

        ws.close();
    } finally {
        chrome.kill();
    }
}

main().catch(err => {
    console.error(err);
    process.exit(1);
});
