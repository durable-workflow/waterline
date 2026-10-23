import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
const require = createRequire(path.join(process.cwd(), 'package.json'));
const { chromium } = require('playwright');

const base = process.env.WATERLINE_ACCEPTANCE_URL || 'http://127.0.0.1:8080';
const browser = await chromium.launch({...(process.env.CHROMIUM_EXECUTABLE_PATH ? {executablePath:process.env.CHROMIUM_EXECUTABLE_PATH} : {}),headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
const evidence = {source:process.env.EXPECTED_WATERLINE_SOURCE,version:process.env.EXPECTED_WATERLINE_VERSION,playwright:require('playwright/package.json').version,browser: await browser.version(), node: process.version, checks: [], errors: []};
const directory = process.env.WATERLINE_EVIDENCE_DIRECTORY || 'published-embedded-evidence/controls';
fs.mkdirSync(directory,{recursive:true});

try {
    for (const role of ['viewer', 'readonly']) {
        const context = await browser.newContext();
        await context.addCookies([{name:'wl-test-role',value:role,url:base}]);
        const endpoints = [];
        for (const target of ['flows/missing','instances/missing','instances/missing/runs/missing']) {
            for (const suffix of ['signals/name','updates/name','repair','cancel','terminate','archive']) {
                endpoints.push(['POST',`/waterline/api/${target}/${suffix}`]);
            }
        }
        for (const action of ['pause','resume','trigger','backfill']) endpoints.push(['POST',`/waterline/api/v2/schedules/missing/${action}`]);
        endpoints.push(['DELETE','/waterline/api/v2/schedules/missing']);
        for (const [method,url] of endpoints) {
            const response = await context.request.fetch(base+url,{method,data:{arguments:[]}});
            assert.equal(response.status(),403,`${role} ${method} ${url}`);
            assert.equal((await response.json()).reason,role==='viewer'?'waterline_action_forbidden':'waterline_read_only');
        }
        evidence.checks.push({role,protectedMutationAliases:endpoints.length});
        await context.close();
    }
    for (const viewport of [{width:1440,height:900},{width:390,height:844}]) {
        for (const theme of ['light','dark']) {
            for (const role of ['viewer','readonly','operator']) {
                const context = await browser.newContext({viewport});
                await context.addCookies([{name:'wl-test-role',value:role,url:base}]);
                await context.addInitScript((value)=>localStorage.setItem('waterline-theme',value),theme);
                const page = await context.newPage();
                page.on('pageerror', error => evidence.errors.push(String(error)));
                page.on('console', message => { if(message.type()==='error') console.log('console',message.text()); });
                page.on('response', response => { if(response.status()>=400) console.log('HTTP',response.status(),response.url()); });
                page.on('requestfailed', request => console.log('request failed',request.url(),request.failure()));
                await page.goto(base+'/waterline/schedules');
                await page.waitForFunction(()=>document.getElementById('waterline')?.dataset.waterlineMounted==='true');
                const row = page.locator('tr[data-schedule-id="authorization-browser-schedule"]');
                await row.waitFor();
                const scheduleButton = row.getByRole('button',{name:/^(Pause|Resume)$/,exact:true});
                assert.equal(await scheduleButton.isEnabled(),role==='operator');
                if(role!=='operator') {
                    const message=role==='viewer'?'You are not authorized to change schedules.':'This Waterline is read-only.';
                    await page.getByText(message,{exact:false}).waitFor();
                    const response=await page.request.post(base+'/waterline/api/v2/schedules/authorization-browser-schedule/pause');
                    assert.equal(response.status(),403);
                    assert.equal((await response.json()).reason,role==='viewer'?'waterline_action_forbidden':'waterline_read_only');
                }
                const schedules=await (await page.request.get(base+'/waterline/api/v2/schedules')).json();
                const detailResponse=await page.request.get(base+'/waterline/api/instances/authorization-browser');
                assert.equal(detailResponse.status(),200);
                const detail=await detailResponse.json();
                await page.goto(base+'/waterline/flows/instances/authorization-browser/runs/'+detail.run_id);
                await page.getByRole('button',{name:'Inspect full details',exact:true}).click();
                await page.locator('.wl-flow-detail__title').filter({hasText:'TestOperatorCommandWorkflow'}).waitFor();
                await page.getByText('Actions',{exact:true}).first().waitFor();
                if(role!=='operator') {
                    const reason=role==='viewer'?'You are not authorized to perform this Waterline action.':'Waterline is configured read-only; this action is unavailable.';
                    await page.getByText(reason,{exact:false}).first().waitFor();
                    for(const action of ['Signal','Update','Repair','Cancel','Terminate','Archive']) {
                        assert.equal(await page.locator('.wl-flow-detail__header-actions').getByRole('button',{name:action,exact:true}).count(),0);
                    }
                    const write=await page.request.post(base+'/waterline/api/instances/authorization-browser/runs/'+detail.run_id+'/cancel');
                    assert.equal(write.status(),403);
                } else {
                    await page.locator('.wl-flow-detail__header-actions').getByRole('button',{name:'Signal',exact:true}).waitFor();
                    await page.locator('.wl-flow-detail__header-actions').getByRole('button',{name:'Cancel',exact:true}).waitFor();
                }
                await page.screenshot({path:`${directory}/${role}-${theme}-${viewport.width}.png`,fullPage:true});
                evidence.checks.push({role,theme,width:viewport.width,schedules:schedules.data.length,run_id:detail.run_id});
                console.log('ok',role,theme,viewport.width);
                await context.close();
            }
        }
    }
    const context=await browser.newContext();
    await context.addCookies([{name:'wl-test-role',value:'operator',url:base}]);
    const page=await context.newPage();
    await page.goto(base+'/waterline/schedules');
    const row=page.locator('tr[data-schedule-id="authorization-browser-schedule"]');
    await row.getByRole('button',{name:'Pause',exact:true}).click();
    await row.getByRole('button',{name:'Resume',exact:true}).waitFor();
    const stored=await (await page.request.get(base+'/waterline/api/v2/schedules')).json();
    assert.equal(stored.data[0].status,'paused');
    evidence.checks.push({action:'operator-pause',stored_status:'paused'});
    const run = await (await page.request.get(base+'/waterline/api/instances/authorization-browser')).json();
    const signal = await page.request.post(base+'/waterline/api/instances/authorization-browser/runs/'+run.run_id+'/signals/name-provided',{data:{arguments:'Operator'}});
    assert.equal(signal.status(),200);
    assert.equal((await signal.json()).command_status,'accepted');
    evidence.checks.push({action:'operator-signal',command_status:'accepted'});
    assert.deepEqual(evidence.errors,[]);
    console.log('ok operator clicked Pause; stored state is paused');
    await context.close();
} finally {
    fs.writeFileSync(directory+'/evidence.json',JSON.stringify(evidence,null,2));
    await browser.close();
}
