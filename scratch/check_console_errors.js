const { spawn } = require('child_process');
const http = require('http');

async function test() {
    const chrome = spawn('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', [
        '--headless=new',
        '--remote-debugging-port=9250',
        '--disable-gpu',
        '--no-first-run'
    ]);
    await new Promise(r => setTimeout(r, 1200));

    const tabs = await new Promise(res => http.get('http://127.0.0.1:9250/json/list', r => {
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

    ws.addEventListener('message', e => {
        const m = JSON.parse(e.data);
        if (m.method === 'Runtime.consoleAPICalled') {
            console.log(`[CONSOLE ${m.params.type}]:`, m.params.args.map(a => a.value || a.description || JSON.stringify(a)).join(' '));
        }
        if (m.method === 'Runtime.exceptionThrown') {
            console.log('[EXCEPTION]:', m.params.exceptionDetails.exception?.description || JSON.stringify(m.params.exceptionDetails));
        }
    });

    await send('Page.addScriptToEvaluateOnNewDocument', {
        source: `
            localStorage.setItem('oxford_session_auth', 'true');
            localStorage.setItem('oxford_session_role', 'supervisor');
            localStorage.setItem('oxford_session_user', JSON.stringify({id:'emp-102', role:'Supervisor'}));
            localStorage.setItem('oxford_active_tab', 'overview');
            localStorage.setItem('oxford_active_subtab_dashboard', 'system');
        `
    });

    await send('Page.navigate', { url: 'http://localhost:8080/index.php' });
    await new Promise(r => setTimeout(r, 4000));

    // Inspect overlay
    const inspect = await send('Runtime.evaluate', {
        returnByValue: true,
        expression: `
            (function() {
                const overlay = document.getElementById('dept-matrix-loading-overlay');
                const canvas = document.getElementById('chart-system-dept-progress');
                return {
                    renderFnType: typeof window.renderSystemChartsOnDemand,
                    canvasExists: !!canvas,
                    canvasParentDisplay: canvas ? window.getComputedStyle(canvas.parentElement).display : null,
                    chartInstance: !!window.chartSystemDeptProgressInstance,
                    chartOnCanvas: !!(canvas && Chart.getChart(canvas)),
                    chartGlobalExists: typeof Chart !== 'undefined',
                    tryRender: (function() {
                        try {
                            if (typeof window.renderSystemChartsOnDemand === 'function') {
                                window.renderSystemChartsOnDemand();
                                return 'called renderSystemChartsOnDemand';
                            }
                            return 'renderSystemChartsOnDemand not a function';
                        } catch(e) {
                            return 'Error: ' + e.message;
                        }
                    })(),
                    chartOnCanvasAfter: !!(canvas && Chart.getChart(canvas))
                };
            })()
        `
    });
    console.log('Inspection:', inspect.result.value);

    ws.close();
    chrome.kill();
}

test().catch(console.error);
