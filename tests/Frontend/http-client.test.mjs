import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import test from 'node:test';
import axios from 'axios';
import http, {configureWaterlineHttp} from '../../resources/js/http.mjs';

test('the shared client bounds a real stalled read, recovers explicitly and isolates host defaults', async () => {
    const originalTimeout = axios.defaults.timeout;
    const originalCsrf = axios.defaults.headers.common['X-CSRF-TOKEN'];
    let reads = 0;
    const server = createServer((_request, response) => {
        reads++;
        if (reads > 1) {
            response.end('recovered');
        }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        assert.equal(configureWaterlineHttp({request_timeout_ms:80}, 'waterline-only'), http);
        assert.equal(http.defaults.headers.common['X-CSRF-TOKEN'], 'waterline-only');
        assert.equal(axios.defaults.timeout, originalTimeout);
        assert.equal(axios.defaults.headers.common['X-CSRF-TOKEN'], originalCsrf);
        const url = `http://127.0.0.1:${server.address().port}/list`;
        await assert.rejects(http.get(url), error => error.code === 'ECONNABORTED');
        assert.equal(reads, 1, 'A timed-out read is not automatically retried.');
        assert.equal((await http.get(url)).data, 'recovered');
        assert.equal(reads, 2);
        configureWaterlineHttp({});
        assert.equal(http.defaults.timeout, 65_000);
        assert.equal(http.defaults.headers.common['X-CSRF-TOKEN'], undefined);
    } finally {
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
    }
});
