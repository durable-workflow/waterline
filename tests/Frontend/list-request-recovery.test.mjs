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

test('periodic refresh cannot supersede a pending read and hide its timeout',async()=>{
    const {context,requests} = fixture();
    const first = loadFlows.call(context,1);
    const periodic = loadFlows.call(context,1,true);
    assert.equal(requests.length,1,'Only one periodic read may be outstanding.');
    requests[0].reject({response:{data:{message:'Timed out'}}});
    await first;
    await periodic;
    assert.equal(context.listLoadError,'Timed out');
    assert.equal(context.listRequestPending,false);
    const recovery = loadFlows.call(context,1,true);
    assert.equal(requests.length,2);
    requests[1].resolve(response('recovered'));
    await recovery;
    assert.equal(context.flows[0].id,'recovered');
});

test('the service-mode page limit is surfaced and cleared with the list it belongs to',async()=>{
    const {context,requests} = fixture();
    const limited = loadFlows.call(context,100);
    requests[0].resolve({data:{data:[{id:'last'}],current_page:100,last_page:100,page_limit:100,page_limit_reached:true}});
    await limited;
    assert.equal(context.pageLimitReached,true);
    assert.equal(context.pageLimit,100);
    assert.equal(context.totalPages,100);

    const ordinary = loadFlows.call(context,2);
    requests[1].resolve({data:{data:[{id:'second'}],current_page:2,last_page:3,page_limit:100,page_limit_reached:false}});
    await ordinary;
    assert.equal(context.pageLimitReached,false);

    const olderServer = loadFlows.call(context,100);
    requests[2].resolve(response('embedded'));
    await olderServer;
    assert.equal(context.pageLimitReached,false);
    assert.equal(context.pageLimit,null);

    const limitedAgain = loadFlows.call(context,100);
    requests[3].resolve({data:{data:[{id:'last'}],last_page:100,page_limit:100,page_limit_reached:true}});
    await limitedAgain;
    const failed = loadFlows.call(context,100);
    requests[4].reject({response:{data:{message:'Timed out'}}});
    await failed;
    assert.equal(context.listLoadError,'Timed out');
    assert.equal(context.pageLimitReached,false);
});

test('a past-end run page adopts the returned page so Previous follows it',async()=>{
    const previous = source.match(/previous\(\) \{[\s\S]*?\n            \},/)?.[0];
    assert.ok(previous,'The production Previous method must be exercised.');
    const {context,requests} = fixture();
    context.loadFlows = loadFlows;
    context.previous = vm.runInNewContext(`({${previous}}).previous`);
    const pastEnd = loadFlows.call(context,9);
    assert.match(requests[0].url,/[?&]page=9(&|$)/);
    requests[0].resolve({data:{data:[{id:'last'}],current_page:2,last_page:2}});
    await pastEnd;
    assert.equal(context.page,2);
    assert.equal(context.totalPages,2);
    context.previous();
    assert.match(requests[1].url,/[?&]page=1(&|$)/);
});

test('a past-end schedule page adopts the returned page so Refresh repeats it',async()=>{
    const schedules = fs.readFileSync(new URL('../../resources/js/components/ScheduleView.vue',import.meta.url),'utf8');
    const methods = ['async loadData() {','refresh() {','goToPage(page) {'].map(start=>{
        const at = schedules.indexOf('\n        '+start);
        assert.ok(at>0,`The production ${start} method must be exercised.`);
        return schedules.slice(at+1,schedules.indexOf('\n        },',at)+11);
    });
    const requests = [];
    const axios = {get:(url,options)=>new Promise(resolve=>requests.push({url,params:{...options.params},resolve}))};
    const component = vm.runInNewContext(`({${methods.join('\n')}})`,{axios,console});
    const context = {
        ...component,schedules:[],currentPage:9,statusFilter:'',pagination:null,
        resolvedApiEndpoint:()=>'/waterline/api/v2/schedules',$t:value=>value,responseMessage:(error,fallback)=>fallback,
    };
    for (const name of Object.keys(component)) context[name] = component[name].bind(context);
    const pastEnd = context.loadData();
    assert.equal(requests[0].params.page,9);
    requests[0].resolve({data:{data:[{id:'last'}],current_page:2,last_page:2,per_page:25,total:26}});
    await pastEnd;
    assert.equal(context.currentPage,2);
    assert.equal(context.pagination.current_page,2);
    const refreshed = context.refresh();
    assert.equal(requests[1].params.page,2);
    requests[1].resolve({data:{data:[{id:'last'}],current_page:2,last_page:2,per_page:25,total:26}});
    await refreshed;
    context.goToPage(context.pagination.current_page-1);
    assert.equal(requests[2].params.page,1);
});
