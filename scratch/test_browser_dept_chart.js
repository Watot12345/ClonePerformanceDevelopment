const { spawn } = require('child_process');
const http = require('http');

async function main() {
    const port = 9260;
    const chrome = spawn('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', [
        '--headless=new',
        `--remote-debugging-port=${port}`,
        '--disable-gpu',
        '--no-first-run',
        '--no-default-browser-check'
    ]);
    
    await new Promise(r => setTimeout(r, 1500));

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

        let id = 1;
        const send = (m, p = {}) => new Promise(r => {
            const i = id++;
            const handler = (e) => {
                const d = JSON.parse(e.data);
                if (d.id === i) {
                    ws.removeEventListener('message', handler);
                    r(d.result);
                }
            };
            ws.addEventListener('message', handler);
            ws.send(JSON.stringify({ id: i, method: m, params: p }));
        });

        await send('Page.enable');
        await send('Runtime.enable');

        await send('Page.addScriptToEvaluateOnNewDocument', {
            source: `
                localStorage.setItem('oxford_session_auth', 'true');
                localStorage.setItem('oxford_session_role', 'supervisor');
                localStorage.setItem('oxford_session_user', JSON.stringify({id:'emp-102', role:'Supervisor', department:'Front Office'}));
                localStorage.setItem('oxford_active_tab', 'overview');
                localStorage.setItem('oxford_active_subtab_dashboard', 'system');
            `
        });

        await send('Page.navigate', { url: 'http://localhost:8080/index.php' });
        await new Promise(r => setTimeout(r, 2500));

        const res = await send('Runtime.evaluate', {
            returnByValue: true,
            awaitPromise: true,
            expression: `
                (function() {
                    const canvas = document.getElementById('chart-system-dept-progress');
                    const chartInst = window.chartSystemDeptProgressInstance || (canvas && typeof Chart !== 'undefined' ? Chart.getChart(canvas) : null);
                    const subSystem = document.getElementById('sub-dashboard-system');
                    const subPulse = document.getElementById('sub-dashboard-pulse');
                    const tableRows = document.querySelectorAll('#table-dept-execution-matrix-body tr');

                    return {
                        subSystemClasses: subSystem ? subSystem.className : null,
                        subSystemDisplay: subSystem ? window.getComputedStyle(subSystem).display : null,
                        canvasPresent: !!canvas,
                        canvasRenderedWidth: canvas ? canvas.clientWidth : 0,
                        canvasRenderedHeight: canvas ? canvas.clientHeight : 0,
                        chartInstPresent: !!chartInst,
                        chartLabels: chartInst ? chartInst.data.labels : [],
                        chartDatasetsCount: chartInst ? chartInst.data.datasets.length : 0,
                        chartDatasetsData: chartInst ? chartInst.data.datasets.map(d => ({ label: d.label, data: d.data })) : [],
                        tableRowCount: tableRows.length
                    };
                })()
            `
        });

        console.log('BROWSER EVALUATION RESULT:', JSON.stringify(res.result.value, null, 2));
        ws.close();
    } finally {
        chrome.kill();
    }
}

main().catch(err => {
    console.error('Error:', err);
    process.exit(1);
});
