const { spawn } = require('child_process');
const http = require('http');

async function runTest(role) {
    console.log(`\n=== TESTING ROLE: ${role.toUpperCase()} ===`);
    const port = role === 'supervisor' ? 9271 : 9272;
    const chrome = spawn('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', [
        '--headless=new',
        `--remote-debugging-port=${port}`,
        '--disable-gpu',
        '--no-first-run',
        '--no-default-browser-check',
        '--window-size=1440,900'
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

        const targetTab = tabs[0];
        const ws = new WebSocket(targetTab.webSocketDebuggerUrl);
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

        await send('Page.addScriptToEvaluateOnNewDocument', {
            source: `
                localStorage.setItem('oxford_session_auth', 'true');
                localStorage.setItem('oxford_session_role', '${role}');
                localStorage.setItem('oxford_session_user', JSON.stringify({
                    id: '${role === 'supervisor' ? 'emp-102' : 'emp-101'}',
                    role: '${role === 'supervisor' ? 'Supervisor' : 'Associate'}',
                    name: '${role === 'supervisor' ? 'Chef Marco Rossi' : 'Juan Dela Cruz'}',
                    department: 'Food & Beverage'
                }));
                localStorage.setItem('oxford_active_tab', 'overview');
                localStorage.setItem('oxford_active_subtab_dashboard', 'system');
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

        await navigateWithTimeout('http://localhost:8080/index.php');
        await new Promise(r => setTimeout(r, 2500));

        // Switch to Sub-tab 2 "system" explicitly to test tab switcher
        await send('Runtime.evaluate', {
            expression: `
                if (typeof switchSubTab === 'function') {
                    switchSubTab('dashboard', 'system');
                }
            `
        });
        await new Promise(r => setTimeout(r, 1000));

        const res = await send('Runtime.evaluate', {
            returnByValue: true,
            expression: `
                (function() {
                    const canvas = document.getElementById('chart-system-dept-progress');
                    const chart = window.chartSystemDeptProgressInstance || (canvas && typeof Chart !== 'undefined' ? Chart.getChart(canvas) : null);
                    const subSystem = document.getElementById('sub-dashboard-system');
                    const overlay = document.getElementById('dept-matrix-loading-overlay');
                    const rows = document.querySelectorAll('#table-dept-execution-matrix-body tr');

                    return {
                        subSystemDisplay: subSystem ? window.getComputedStyle(subSystem).display : null,
                        subSystemActive: subSystem ? subSystem.classList.contains('active') : false,
                        canvasFound: !!canvas,
                        canvasWidth: canvas ? canvas.clientWidth : 0,
                        canvasHeight: canvas ? canvas.clientHeight : 0,
                        chartInstFound: !!chart,
                        chartLabels: chart ? chart.data.labels : [],
                        datasets: chart ? chart.data.datasets.map(d => ({ label: d.label, data: d.data })) : [],
                        overlayHidden: overlay ? (overlay.classList.contains('hidden') || window.getComputedStyle(overlay).display === 'none') : true,
                        tableRowCount: rows.length
                    };
                })()
            `
        });

        console.log('Result:', JSON.stringify(res.result.value, null, 2));
        ws.close();
    } finally {
        chrome.kill();
    }
}

async function main() {
    await runTest('supervisor');
    await runTest('associate');
}

main().catch(err => {
    console.error(err);
    process.exit(1);
});
