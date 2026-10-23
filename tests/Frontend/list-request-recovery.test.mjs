import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test, {before} from 'node:test';

// Execute the production list method, rather than a second implementation of
// request ordering, with only its HTTP boundary and surrounding Vue state faked.
const source = fs.readFileSync(new URL('../../resources/js/screens/flows/index.vue',import.meta.url),'utf8');
const method = source.match(/loadFlows\(page = 1, refreshing = false\) \{[\s\S]*?\n            \},(?=\n\n            changeClassification)/)?.[0];
let loadFlows;
before(()=>{
    assert.ok(method,'The production list loading method must be exercised.');
    loadFlows = vm.runInNewContext(`({${method}}).loadFlows`,{Waterline:{basePath:'/waterline'}});
});

function fixture() {
    const requests = [];
    const context = {
        page:1,listRequest:0,flows:[],ready:false,
        $route:{params:{type:'running'}},$root:{autoLoadsNewEntries:true},$t:value=>value,
        apiQueryString:page=>`page=${page}&instance_id=invoice`,
        $http:{get:url=>new Promise((resolve,reject)=>requests.push({url,resolve,reject}))},
    };
    return {context,requests};
}
const response = id=>({data:{data:[{id}],last_page:4}});

test('explicit read recovery keeps the selected page and filters',async()=>{
    const {context,requests} = fixture();
    const failed = loadFlows.call(context,3);
    requests[0].reject({response:{data:{message:'Timed out'}}});
    await failed;
    assert.equal(context.listLoadError,'Timed out');
    assert.equal(context.page,3);
    const retried = loadFlows.call(context,context.page);
    assert.equal(requests[1].url,requests[0].url);
    requests[1].resolve(response('recovered'));
    await retried;
    assert.equal(context.listLoadError,null);
    assert.equal(context.flows[0].id,'recovered');
});

test('late success or failure cannot replace a newer read result',async()=>{
    for (const outcome of ['resolve','reject']) {
        const {context,requests} = fixture();
        const old = loadFlows.call(context,3);
        const latest = loadFlows.call(context,4);
        requests[1].resolve(response('newer'));
        await latest;
        requests[0][outcome](outcome==='resolve'?response('stale'):{response:{data:{message:'stale failure'}}});
        await old;
        assert.equal(context.page,4);
        assert.equal(context.flows[0].id,'newer');
        assert.equal(context.listLoadError,null);
    }
});
