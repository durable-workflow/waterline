"""Read-only loopback proxy for the Waterline schedule acceptance cell."""
import hashlib
import http.client
import http.server
import re
import threading
import time
import urllib.parse

MAX_BODY = 4 * 1024 * 1024
HOP = {'connection', 'transfer-encoding', 'content-length', 'keep-alive',
       'proxy-authenticate', 'proxy-authorization', 'te', 'trailer', 'upgrade'}


def make_proxy(port, listen_port=0):
    if type(port) is not int or not 1024 <= port <= 65535:
        raise ValueError('owned loopback port required')
    if type(listen_port) is not int or listen_port not in {0, 8082}:
        raise ValueError('fixed fixture listener required')
    records = []
    lock = threading.Lock()

    class Handler(http.server.BaseHTTPRequestHandler):
        def log_message(self, *_args):
            pass

        def do_GET(self):
            parsed = urllib.parse.urlsplit(self.path)
            decoded = urllib.parse.unquote(parsed.path, errors='strict')
            length = self.headers.get('Content-Length', '0')
            if (parsed.scheme or parsed.netloc or parsed.fragment or len(self.path) > 12288
                or re.fullmatch(r'/api/[A-Za-z0-9_./-]+', decoded) is None
                or any(x in {'.', '..'} for x in decoded.split('/'))
                or '//' in decoded or length != '0' or self.headers.get('Transfer-Encoding')):
                self.send_error(400)
                return
            with lock:
                if len(records) >= 500:
                    self.send_error(429)
                    return
                record = {'method': 'GET', 'path': parsed.path,
                          'query_sha256': hashlib.sha256(parsed.query.encode()).hexdigest(),
                          'started': time.monotonic(), 'status': None}
                records.append(record)
            connection = http.client.HTTPConnection('127.0.0.1', port, timeout=4)
            try:
                headers = {k: v for k, v in self.headers.items()
                           if k.lower() not in HOP | {'host'}}
                headers['Host'] = '127.0.0.1:' + str(port)
                connection.request('GET', self.path, headers=headers)
                upstream = connection.getresponse()
                body = upstream.read(MAX_BODY + 1)
                if len(body) > MAX_BODY:
                    raise ValueError('bounded response exceeded')
                record['status'] = upstream.status
                self.send_response(upstream.status)
                for key, value in upstream.getheaders():
                    if key.lower() not in HOP:
                        self.send_header(key, value)
                self.send_header('Content-Length', str(len(body)))
                self.end_headers()
                self.wfile.write(body)
            except (OSError, ValueError, http.client.HTTPException):
                record['proxy_error'] = True
                if record['status'] is None:
                    self.send_error(502)
            finally:
                connection.close()
                record['finished'] = time.monotonic()

        def do_POST(self):
            self.send_error(405)
        do_PUT = do_POST
        do_PATCH = do_POST
        do_DELETE = do_POST

    server = http.server.ThreadingHTTPServer(('127.0.0.1', listen_port), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    return server, thread, records
