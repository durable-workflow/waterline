import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(path.join(process.cwd(), 'package.json'));
const { chromium } = require('playwright');
const phase = process.argv[2] || 'inspect';
const base = 'http://127.0.0.1:8080';
const directory = 'uncertain-outcomes-evidence';
fs.mkdirSync(directory, {recursive:true});
const browser = await chromium.launch({args:['--no-sandbox']});
const report = {generatedAt:new Date().toISOString(), phase, browser:await browser.version(), playwright:require('playwright/package.json').version, runner:process.env.GITHUB_SHA, waterline:process.env.EXPECTED_WATERLINE_VERSION, workflow:process.env.EXPECTED_WORKFLOW_VERSION, actions:[], observations:[], errors:[]};

async function contextFor(role, options={}) {
    const context = await browser.newContext(options);
    await context.addCookies([{name:'wl-test-role',value:role,url:base}]);
    return context;
}

async function detail(context, id) {
    const response = await context.request.get(`${base}/waterline/api/instances/${id}?observation=complete`);
    assert.equal(response.status(),200);
    const body = await response.json();
    assert.equal(typeof body.run_id,'string');
    return body;
}

async function post(context, id, runId, action, data) {
    const response = await context.request.post(`${base}/waterline/api/instances/${id}/runs/${runId}/${action}`,{data});
    const body = await response.json();
    const role = (await context.cookies(base)).find(cookie=>cookie.name==='wl-test-role')?.value;
    report.actions.push({role,id,runId,action,http:response.status(),body});
    return response;
}

async function inspectPage(context, id, runId, name) {
    const page = await context.newPage();
    page.on('pageerror',error=>report.errors.push(String(error)));
    await page.goto(`${base}/waterline/flows/instances/${id}/runs/${runId}`);
    await page.locator('.wl-flow-detail__title').waitFor();
    const inspect = page.getByRole('button',{name:'Inspect full details',exact:true});
    if (await inspect.count()) await inspect.click();
    await page.locator('#collapseArguments').waitFor({state:'attached'});
    await page.waitForLoadState('networkidle');
    const payloads = page.locator('.payload-inspector button[aria-expanded="false"]:visible');
    for (let remaining=await payloads.count(); remaining>0; --remaining) {
        const button = payloads.first();
        if (!await button.isVisible()) break;
        await button.click();
    }
    let text = await page.locator('body').innerText();
    fs.writeFileSync(`${directory}/${name}.txt`,text);
    await page.screenshot({path:`${directory}/${name}.png`,fullPage:true});
    const buttons = await page.locator('.wl-flow-detail__header-actions button').allTextContents();
    const resultButtons = page.locator('#collapseActivities button[title="View Result"]');
    const activityResults = [];
    for (let index=0; index<await resultButtons.count(); ++index) {
        await resultButtons.nth(index).click();
        const dialog = page.getByRole('dialog');
        await dialog.waitFor({state:'visible'});
        const resultText = await dialog.innerText();
        activityResults.push(resultText);
        text += '\n'+resultText;
        fs.writeFileSync(`${directory}/${name}-activity-${index}.txt`,resultText);
        await page.screenshot({path:`${directory}/${name}-activity-${index}.png`,fullPage:true});
        await dialog.getByRole('button',{name:'Okay',exact:true}).click();
        await dialog.waitFor({state:'hidden'});
    }
    await page.close();
    return {id,runId,name,buttons,activityResults,operationKeyVisible:text.includes(id.replace('uncertain-','operation-')),recordedReceiptVisible:text.includes('synthetic-operation-processing-failure-1'),authoredReceiptVisible:text.includes('synthetic-operation-authored-reconciliation-1'),operatorNoteVisible:text.includes('operator requested lookup'),lostResponseVisible:text.includes('Response lost after submission'),confirmedRejectionVisible:text.includes('confirmed rejection'),processingFailureVisible:text.includes('local processing failure')};
}

try {
    const operator = await contextFor('operator');
    const controlId = 'uncertain-authored-reconciliation';
    if (phase === 'inspect') {
        const viewer = await contextFor('viewer');
        assert.equal((await viewer.request.get(base+'/provider-oracle.json')).status(),404,'Provider oracle must not be readable through the application.');
        const selections = [];
        for (const scenario of ['lost-response','confirmed-failure','processing-failure']) {
            const id = 'uncertain-'+scenario;
            const selected = await detail(operator,id);
            fs.writeFileSync(`${directory}/${scenario}-api.json`,JSON.stringify(selected,null,2));
            assert.equal((await detail(viewer,id)).run_id,selected.run_id);
            for (const [action,data] of [['repair',{}],['signals/reconcile',{arguments:[]}],['updates/note',{arguments:['investigating'],wait_for:'accepted'}]]) {
                assert.equal((await post(viewer,id,selected.run_id,action,data)).status(),403);
                assert.equal((await post(operator,id,selected.run_id,action,data)).status(),409);
            }
            selections.push({scenario,id,runId:selected.run_id});
        }
        const viewerControl = await detail(viewer,controlId);
        assert.equal((await post(viewer,controlId,viewerControl.run_id,'updates/note',{arguments:['unauthorized note'],wait_for:'accepted'})).status(),403);
        assert.equal((await post(viewer,controlId,viewerControl.run_id,'signals/reconcile',{arguments:[]})).status(),403);
        await viewer.close();
        for (const theme of ['light','dark']) {
            for (const width of [1440,390]) {
                const context = await contextFor('operator',{viewport:{width,height:900},colorScheme:theme});
                await context.addInitScript(value=>localStorage.setItem('waterline-theme',value),theme);
                for (const selected of selections) report.observations.push(await inspectPage(context,selected.id,selected.runId,`${selected.scenario}-${theme}-${width}`));
                await context.close();
            }
        }
        const control = await detail(operator,controlId);
        report.observations.push(await inspectPage(operator,controlId,control.run_id,'authored-control-waiting'));
        const accepted = await post(operator,controlId,control.run_id,'updates/note',{arguments:['operator requested lookup'],wait_for:'accepted'});
        assert.ok([200,202].includes(accepted.status()));
    } else if (phase === 'resolve') {
        const control = await detail(operator,controlId);
        assert.equal((await post(operator,controlId,control.run_id,'signals/reconcile',{arguments:[]})).status(),200);
    } else if (phase === 'resolved') {
        const control = await detail(operator,controlId);
        assert.equal(String(control.status_bucket || control.status).toLowerCase(),'completed');
        fs.writeFileSync(`${directory}/authored-control-api.json`,JSON.stringify(control,null,2));
        report.observations.push(await inspectPage(operator,controlId,control.run_id,'authored-control-completed'));
    } else {
        throw new Error('Unknown investigation phase '+phase);
    }
    assert.deepEqual(report.errors,[]);
    await operator.close();
} finally {
    fs.writeFileSync(`${directory}/${phase}.json`,JSON.stringify(report,null,2));
    await browser.close();
}
console.log(`Investigation ${phase}: ${report.actions.length} HTTP action observations, ${report.observations.length} actual UI observations.`);
