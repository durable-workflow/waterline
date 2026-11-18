"""Finite loopback HTTP controls; no Docker or actual application."""
import http.client
import http.server
import importlib.util
import json
from pathlib import Path
import threading
import unittest

ROOT = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('schedule_proxy_control', ROOT / 'counting-proxy.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ProxyBoundaryTest(unittest.TestCase):
    def setUp(self):
        self.upstream_paths = []
        paths = self.upstream_paths
        class Upstream(http.server.BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass
            def do_GET(self):
                paths.append(self.path)
                data = b'{"schedules":[]}'
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Content-Length', str(len(data)))
                self.end_headers()
                self.wfile.write(data)
        self.upstream = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Upstream)
        self.upstream_thread = threading.Thread(target=self.upstream.serve_forever, daemon=True)
        self.upstream_thread.start()
        self.proxy, self.thread, self.records = module.make_proxy(self.upstream.server_port)
        self.thread.start()

    def tearDown(self):
        self.proxy.shutdown(); self.proxy.server_close(); self.thread.join()
        self.upstream.shutdown(); self.upstream.server_close(); self.upstream_thread.join()

    def request(self, method, target):
        client = http.client.HTTPConnection('127.0.0.1', self.proxy.server_port, timeout=3)
        try:
            client.request(method, target, headers={'Authorization': 'Bearer synthetic-control-marker'})
            response = client.getresponse()
            return response.status, response.read()
        finally:
            client.close()

    def test_closed_literal_route_and_structured_query_keep_authorization_out_of_record(self):
        status, data = self.request('GET', '/api/%73chedules?next_page_token=http%3A%2F%2Fexample.invalid%2F&page_size=50')
        self.assertEqual((status, data), (200, b'{"schedules":[]}'))
        self.assertEqual(self.upstream_paths, ['/api/schedules?next_page_token=http%3A%2F%2Fexample.invalid%2F&page_size=50'])
        self.assertNotIn('synthetic-control-marker', json.dumps(self.records))

    def test_unknown_routes_and_query_keys_never_reach_upstream(self):
        for target in ['/api/schedules/not-admitted', '/api/schedules?unexpected=value', '/api/schedules?page_size=50&page_size=1']:
            with self.subTest(target=target):
                self.assertEqual(self.request('GET', target)[0], 400)
        self.assertEqual(self.upstream_paths, [])

    def test_writes_absolute_targets_and_traversal_refuse(self):
        for method, target, status in [('POST', '/api/schedules', 405), ('GET', 'http://example.invalid/api/schedules', 400), ('GET', '/api/%2e%2e/private', 400)]:
            with self.subTest(method=method, target=target):
                self.assertEqual(self.request(method, target)[0], status)
        self.assertEqual(self.upstream_paths, [])


if __name__ == '__main__':
    unittest.main()
