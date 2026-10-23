import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {createRequire} from 'node:module';
const {chromium} = createRequire(path.join(process.cwd(),'package.json'))('playwright');
const output = 'request-budget-evidence';
fs.mkdirSync(output,{recursive:true});
const report = {source:process.env.EXPECTED_CANDIDATE_SOURCE,browserBudget:3.5,requests:[],errors:[]};
const mode = value => {
    fs.writeFileSync('mode.json.tmp',JSON.stringify({mode:value,service_budget:0.2,browser_budget:3.5,browser_stall_microseconds:5_000_000}));
    fs.renameSync('mode.json.tmp','mode.json');
};
const browser = await chromium.launch({args:['--no-sandbox']});
try {
    const page = await browser.newPage({viewport:{width:390,height:900}});
    page.on('pageerror',error=>report.errors.push(String(error)));
    await page.addInitScript(()=>localStorage.setItem('autoLoadsNewEntries','1'));
    let started;
    page.on('request',request=>{
        if (new URL(request.url()).pathname==='/waterline/api/flows/running') {
            started ??= Date.now();
            report.requests.push({method:request.method(),url:request.url()});
        }
    });
    mode('browser-stall');
    await page.goto('http://127.0.0.1:8080/waterline/running');
    assert.equal(await page.evaluate(()=>window.Waterline.request_timeout_ms),3500);
    const alert = page.locator('[role="alert"]').filter({has:page.getByRole('button',{name:'Retry',exact:true})});
    await alert.waitFor({timeout:5000});
    report.elapsed = (Date.now()-started)/1000;
    assert.ok(report.elapsed>=3 && report.elapsed<5,'Automatic refresh must preserve a bounded visible timeout.');
    assert.equal(report.requests.length,1,'A timer must not supersede an outstanding read.');
    await page.screenshot({path:`${output}/auto-refresh-timeout.png`,fullPage:true});
    await page.getByRole('button',{name:'Auto refresh',exact:true}).click();
    await page.waitForTimeout(2600);
    mode('healthy');
    await alert.getByRole('button',{name:'Retry',exact:true}).click();
    await page.locator('.flow-row').first().waitFor();
    assert.equal(await page.locator('.flow-row').count(),2);
    assert.equal(report.requests.length,2,'Recovery is one explicit read.');
    report.recoveredRows = 2;
    await page.screenshot({path:`${output}/auto-refresh-recovered.png`,fullPage:true});
    assert.deepEqual(report.errors,[]);
} finally {
    fs.writeFileSync(`${output}/browser-auto.json`,JSON.stringify(report,null,2));
    await browser.close();
}
