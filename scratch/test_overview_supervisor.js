const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');

async function main() {
    const chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    const port = 9235;
    const chrome = spawn(chromePath, [
        '--headless=new',
        `--remote-debugging-port=${port}`,
        '--disable-gpu',
        '--no-first-run',
        '--no-default-browser-check',
        '--window-size=1400,900'
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

        ws.addEventListener('message', (evt) => {
            const msg = JSON.parse(evt.data);
            if (msg.method === 'Runtime.consoleAPICalled') {
                console.log(`[Browser Console ${msg.params.type}]:`, msg.params.args.map(a => a.value || JSON.stringify(a)).join(' '));
            }
            if (msg.method === 'Runtime.exceptionThrown') {
                console.error('[Browser Exception]:', JSON.stringify(msg.params.exceptionDetails));
            }
        });

        console.log('1. Adding script to evaluate on new document...');
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
                        console.log('Navigation timeout reached, continuing...');
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

        console.log('2. Navigating to index.php...');
        await navigateWithTimeout('http://localhost:8080/index.php');

        console.log('3. Waiting for UI stabilization & data hydration...');
        await new Promise(r => setTimeout(r, 2500));

        console.log('4. Inspecting Overview DOM & visibility in Supervisor mode...');
        const res = await send('Runtime.evaluate', {
            returnByValue: true,
            expression: `
                (function() {
                    const panelDashboard = document.getElementById('panel-dashboard');
                    const pulseSub = document.getElementById('sub-dashboard-pulse');
                    const systemSub = document.getElementById('sub-dashboard-system');
                    const pulseBtn = document.querySelector('button[data-sub="pulse"]');
                    const systemBtn = document.querySelector('button[data-sub="system"]');
                    const kpiCards = systemSub ? Array.from(systemSub.querySelectorAll('.card-hero, .card-surface, [onclick*="openOverviewDrilldown"]')).length : 0;

                    return {
                        htmlClass: document.documentElement.className,
                        dataUserRole: document.documentElement.getAttribute('data-user-role'),
                        panelDashboard: {
                            exists: !!panelDashboard,
                            classes: panelDashboard ? panelDashboard.className : null,
                            display: panelDashboard ? window.getComputedStyle(panelDashboard).display : null,
                            offsetHeight: panelDashboard ? panelDashboard.offsetHeight : null
                        },
                        pulseSub: {
                            exists: !!pulseSub,
                            display: pulseSub ? window.getComputedStyle(pulseSub).display : null,
                            offsetHeight: pulseSub ? pulseSub.offsetHeight : null
                        },
                        systemSub: {
                            exists: !!systemSub,
                            classes: systemSub ? systemSub.className : null,
                            display: systemSub ? window.getComputedStyle(systemSub).display : null,
                            offsetHeight: systemSub ? systemSub.offsetHeight : null,
                            kpiCardsCount: kpiCards,
                            titles: systemSub ? Array.from(systemSub.querySelectorAll('h3, h4, .font-heading, span.font-bold')).map(h => h.textContent.trim()).filter(t => t.length > 3).slice(0, 8) : []
                        },
                        pulseBtn: {
                            exists: !!pulseBtn,
                            display: pulseBtn ? window.getComputedStyle(pulseBtn).display : null
                        },
                        chartDeptMatrix: {
                            canvasExists: !!document.getElementById('chart-system-dept-progress'),
                            chartOnCanvas: !!(document.getElementById('chart-system-dept-progress') && Chart.getChart('chart-system-dept-progress')),
                            windowInstance: !!window.chartSystemDeptProgressInstance,
                            overlayDisplay: document.getElementById('dept-matrix-loading-overlay') ? window.getComputedStyle(document.getElementById('dept-matrix-loading-overlay')).display : null,
                            overlayClasses: document.getElementById('dept-matrix-loading-overlay') ? document.getElementById('dept-matrix-loading-overlay').className : null,
                            labels: (window.chartSystemDeptProgressInstance || Chart.getChart('chart-system-dept-progress'))?.data?.labels || null
                        }
                    };
                })()
            `
        });

        console.log('DOM Inspection Result:');
        console.log(JSON.stringify(res.result ? res.result.value : res, null, 2));

        // Take a screenshot
        const screenshot = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync('scratch/supervisor_overview.png', Buffer.from(screenshot.data, 'base64'));
        console.log('Screenshot saved to scratch/supervisor_overview.png');

        ws.close();
    } finally {
        chrome.kill();
    }
}

main().catch(err => {
    console.error(err);
    process.exit(1);
});
