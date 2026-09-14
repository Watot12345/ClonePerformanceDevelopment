const { spawn } = require('child_process');
const http = require('http');

async function test() {
    const chrome = spawn('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', [
        '--headless=new',
        '--remote-debugging-port=9247',
        '--disable-gpu',
        '--no-first-run'
    ]);
    await new Promise(r => setTimeout(r, 1200));

    const tabs = await new Promise(res => http.get('http://127.0.0.1:9247/json/list', r => {
        let d = '';
        r.on('data', c => d += c);
        r.on('end', () => res(JSON.parse(d)));
    }));

    const targetTab = tabs.find(t => t.type === 'page' && !t.url.startsWith('chrome-extension')) || tabs.find(t => t.type === 'page') || tabs[0];
    const ws = new WebSocket(targetTab.webSocketDebuggerUrl);
    await new Promise(r => ws.onopen = r);

    let id = 1;
    const send = (m, p = {}) => new Promise(r => {
        const i = id++;
        ws.addEventListener('message', function h(e) {
            const d = JSON.parse(e.data);
            if (d.id === i) {
                ws.removeEventListener('message', h);
                r(d.result);
            }
        });
        ws.send(JSON.stringify({ id: i, method: m, params: p }));
    });

    await send('Page.enable');
    await send('Runtime.enable');

    await send('Page.addScriptToEvaluateOnNewDocument', {
        source: `
            localStorage.setItem('oxford_session_auth', 'true');
            localStorage.setItem('oxford_session_role', 'supervisor');
            localStorage.setItem('oxford_session_user', JSON.stringify({id:'emp-102', role:'Supervisor'}));
            localStorage.setItem('oxford_active_tab', 'overview');
            localStorage.setItem('oxford_active_subtab_dashboard', 'system');
        `
    });

    await send('Page.navigate', { url: 'http://[::1]:8080/index.php' });
    await new Promise(r => setTimeout(r, 3000));

    // Check chart and overlay status in browser
    const evalRes = await send('Runtime.evaluate', {
        returnByValue: true,
        awaitPromise: true,
        expression: `
            (async function() {
                const overlay = document.getElementById('dept-matrix-loading-overlay');
                const canvas = document.getElementById('chart-system-dept-progress');
                const chartInst = window.chartSystemDeptProgressInstance;
                
                let fetchResult = null;
                let fetchError = null;
                try {
                    const r = await fetch('api/reports.php?action=get_dept_execution_matrix');
                    fetchResult = await r.json();
                } catch(e) {
                    fetchError = e.message;
                }

                return {
                    url: window.location.href,
                    overlayClasses: overlay ? overlay.className : null,
                    overlayDisplay: overlay ? window.getComputedStyle(overlay).display : null,
                    canvasExists: !!canvas,
                    canvasWidth: canvas ? canvas.width : null,
                    canvasHeight: canvas ? canvas.height : null,
                    chartInstanceExists: !!chartInst,
                    chartDataLabels: chartInst ? chartInst.data.labels : null,
                    chartDatasets: chartInst ? chartInst.data.datasets.map(d => ({ label: d.label, data: d.data })) : null,
                    fetchResult: fetchResult ? { success: fetchResult.success, matrixCount: fetchResult.matrix?.length } : null,
                    fetchError: fetchError
                };
            })()
        `
    });

    console.log('Result:', JSON.stringify(evalRes.result.value, null, 2));

    ws.close();
    chrome.kill();
}

test().catch(console.error);
