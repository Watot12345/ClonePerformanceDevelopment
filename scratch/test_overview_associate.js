const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');

async function main() {
    const chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    const port = 9240;
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

        console.log('1. Testing Associate mode in Overview...');
        await send('Page.addScriptToEvaluateOnNewDocument', {
            source: `
                try {
                    localStorage.setItem('oxford_session_auth', 'true');
                    localStorage.setItem('oxford_session_role', 'associate');
                    localStorage.setItem('oxford_session_user', JSON.stringify({
                        id: 'emp-101',
                        employee_code: 'EMP-101',
                        name: 'Maria Santos',
                        full_name: 'Maria Santos',
                        role: 'Associate',
                        department: 'Front Office',
                        title: 'Front Desk Host'
                    }));
                    localStorage.setItem('oxford_active_tab', 'overview');
                    localStorage.setItem('oxford_active_subtab_dashboard', 'pulse');
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

        await navigateWithTimeout('http://localhost:8080/index.php');
        await new Promise(r => setTimeout(r, 2500));

        const res = await send('Runtime.evaluate', {
            returnByValue: true,
            expression: `
                (function() {
                    const pulseSub = document.getElementById('sub-dashboard-pulse');
                    const systemSub = document.getElementById('sub-dashboard-system');
                    const systemBtn = document.querySelector('button[data-sub="system"]');
                    return {
                        pulseVisible: pulseSub && window.getComputedStyle(pulseSub).display !== 'none',
                        systemHidden: systemSub && window.getComputedStyle(systemSub).display === 'none',
                        systemBtnHidden: systemBtn && window.getComputedStyle(systemBtn).display === 'none'
                    };
                })()
            `
        });

        console.log('Associate checks:', JSON.stringify(res.result.value, null, 2));

        const screenshot = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync('scratch/associate_overview.png', Buffer.from(screenshot.data, 'base64'));
        console.log('Screenshot saved to scratch/associate_overview.png');

        ws.close();
    } finally {
        chrome.kill();
    }
}

main().catch(err => {
    console.error(err);
    process.exit(1);
});
