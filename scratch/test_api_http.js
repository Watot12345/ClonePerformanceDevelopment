const http = require('http');

http.get('http://localhost:8080/api/reports.php?action=get_dept_execution_matrix', (res) => {
    let rawData = '';
    res.on('data', (chunk) => { rawData += chunk; });
    res.on('end', () => {
        try {
            const parsedData = JSON.parse(rawData);
            console.log('SUCCESS: API get_dept_execution_matrix returned:');
            console.log('Success:', parsedData.success);
            console.log('Matrix items count:', parsedData.matrix?.length);
            console.log('Departments:', parsedData.matrix?.map(d => `${d.department}: Goals=${d.goals_approved_pct}%, LMS=${d.lms_rate_pct}%, Succ=${d.succession_ready_pct}%`).join(' | '));
        } catch (e) {
            console.error('Error parsing JSON:', e.message, rawData);
        }
    });
}).on('error', (e) => {
    console.error('Got error:', e.message);
});
