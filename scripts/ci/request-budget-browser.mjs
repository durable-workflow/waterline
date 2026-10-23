import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {createRequire} from 'node:module';
const require = createRequire(path.join(process.cwd(),'package.json'));
const {chromium} = require('playwright');
const output = 'request-budget-evidence';
fs.mkdirSync(output,{recursive:true});
const mode = (value, extra = {}) => fs.writeFileSync('mode.json',JSON.stringify({mode:value,service_budget:0.2,browser_budget:value==='browser-stall'?0.8:2,...extra}));
const browser = await chromium.launch({args:['--no-sandbox']});
const report = {source:process.env.EXPECTED_CANDIDATE_SOURCE,checks:[],errors:[]};
try {
    const page = await browser.newPage({viewport:{width:390,height:900}});
    page.on('pageerror',error=>report.errors.push(String(error)));
    const base = 'http://127.0.0.1:8080';
    for (const scenario of ['backend-stall','browser-stall']) {
        mode(scenario);
        let requestedAt;
        const onRequest = request => {if (request.url().includes('/api/flows/running')) requestedAt=Date.now();};
        page.on('request',onRequest);
        const api = [];
        const onResponse = async response => {
            if (response.url().includes('/api/flows/running')) api.push({http:response.status(),body:await response.json().catch(()=>null)});
        };
        page.on('response',onResponse);
        await page.goto(base+'/waterline/running');
        const alert = page.locator('[role="alert"]').filter({has:page.getByRole('button',{name:'Retry',exact:true})});
        await alert.waitFor();
        const elapsed = (Date.now()-requestedAt)/1000;
        assert.ok(elapsed>=0.15 && elapsed<1.8,`${scenario} exceeded its selected budget: ${elapsed}s`);
        if (scenario==='backend-stall') {
            await page.waitForTimeout(100);
            assert.equal(api[0]?.http,502);
            assert.equal(api[0]?.body?.reason,'request_timeout');
        }
        await page.screenshot({path:`${output}/${scenario}-error.png`,fullPage:true});
        // The synthetic PHP server must finish its abandoned read before the
        // next explicit read; changing health does not replay a mutation.
        await page.waitForTimeout(3300);
        mode('healthy');
        await alert.getByRole('button',{name:'Retry',exact:true}).click();
        await page.locator('.flow-row').first().waitFor();
        assert.equal(await page.locator('.flow-row').count(),2);
        assert.equal(await page.getByRole('button',{name:'Retry',exact:true}).count(),0);
        await page.screenshot({path:`${output}/${scenario}-recovered.png`,fullPage:true});
        report.checks.push({scenario,elapsed,serviceBudget:0.2,browserBudget:scenario==='browser-stall'?0.8:2,api,recoveredRows:2});
        page.off('request',onRequest); page.off('response',onResponse);
    }
    mode('healthy');
    await page.goto(base+'/waterline/flows/instances/smoke-order/runs/smoke-run#collapseArguments');
    await page.locator('.wl-flow-detail__status').waitFor();
    const full = page.getByRole('button',{name:'Inspect full details',exact:true});
    if (await full.count()) await full.click();
    await page.getByRole('button',{name:'Cancel',exact:true}).first().waitFor();
    mode('write-stall');
    await page.getByRole('button',{name:'Cancel',exact:true}).first().click();
    await page.locator('.swal2-confirm').click();
    await page.getByText('The command outcome is unknown. Inspect the run before sending it again.',{exact:true}).waitFor();
    assert.equal(await page.locator('.swal2-success').count(),0);
    await page.waitForTimeout(2300);
    const writes = fs.readFileSync('writes.jsonl','utf8').trim().split('\n').map(line=>JSON.parse(line));
    assert.equal(writes.length,1,'A timed-out write must never be silently retried.');
    await page.screenshot({path:`${output}/write-outcome-unknown.png`,fullPage:true});
    report.checks.push({scenario:'write-stall',writes,outcome:'unknown'});

    const listResponse = {data:[],current_page:1,last_page:1,per_page:50,total:0};
    const views = [
        {name:'schedules',endpoint:'/api/v2/schedules',selector:'.schedule-view',response:listResponse},
        {name:'workers',endpoint:'/api/v2/health',selector:'.worker-health',response:{status:'ok',checks:[],operator_metrics:{workers:{registrations:[]}}}},
        {name:'services',endpoint:'/api/v2/services/calls',selector:'.service-catalog-view',response:listResponse},
    ];
    // One fixture server is shared. Drain its abandoned request before changing
    // mode, and keep the viewport/theme phases ordered to preserve attribution.
    for (const view of views) {
        for (const theme of ['dark','light']) {
            for (const width of [390,1440]) {
                await page.setViewportSize({width,height:900});
                await page.evaluate(value => localStorage.setItem('waterline-theme',value),theme);
                const fixture = {browser_path:'/waterline'+view.endpoint,browser_response:view.response};
                mode('browser-stall',fixture);
                let requestedAt;
                const onRequest = request => {if (new URL(request.url()).pathname===fixture.browser_path) requestedAt=Date.now();};
                page.on('request',onRequest);
                await page.goto(base+'/waterline/'+view.name);
                const error = page.locator(view.selector+'__state--error');
                await error.waitFor({timeout:2000});
                const elapsed = (Date.now()-requestedAt)/1000;
                assert.ok(elapsed>=0.6 && elapsed<1.8,`${view.name}/${theme}/${width} bypassed its browser budget: ${elapsed}s`);
                await page.screenshot({path:`${output}/${view.name}-${theme}-${width}-error.png`,fullPage:true});
                await page.waitForTimeout(3300);
                mode('healthy',fixture);
                await error.getByRole('button',{name:'Retry',exact:true}).click();
                await page.locator(view.selector+'__content').waitFor();
                assert.equal(await error.count(),0);
                await page.screenshot({path:`${output}/${view.name}-${theme}-${width}-recovered.png`,fullPage:true});
                report.checks.push({scenario:'affected-view-browser-stall',view:view.name,theme,width,elapsed,browserBudget:0.8,recovered:true,transport:'actual HTTP fixture'});
                page.off('request',onRequest);
            }
        }
    }
    const scheduleFixture = {browser_budget:0.8,browser_path:'/waterline/api/v2/schedules',
        browser_response:{...listResponse,total:1,data:[{schedule_id:'budget-schedule',id:'budget-schedule',namespace:'smoke',status:'active',workflow_type:'smoke.order'}]},
        schedule_write_stall:true};
    mode('healthy',scheduleFixture);
    await page.goto(base+'/waterline/schedules');
    await page.locator('[data-row-action="pause"]').click();
    await page.getByText('The command outcome is unknown. Inspect the schedule before sending it again.',{exact:true}).waitFor();
    assert.equal(await page.locator('.swal2-success').count(),0);
    await page.waitForTimeout(3300);
    const scheduleWrites = fs.readFileSync('schedule-writes.jsonl','utf8').trim().split('\n').map(line=>JSON.parse(line));
    assert.equal(scheduleWrites.length,1,'An uncertain schedule write must never be resubmitted.');
    assert.equal(scheduleWrites[0].status,'paused');
    await page.screenshot({path:`${output}/schedule-write-outcome-unknown.png`,fullPage:true});
    report.checks.push({scenario:'schedule-write-stall',writes:scheduleWrites,outcome:'unknown',transport:'actual HTTP fixture'});
    assert.deepEqual(report.errors,[]);
    await page.close();
} finally {
    fs.writeFileSync(`${output}/browser.json`,JSON.stringify(report,null,2));
    await browser.close();
}
