#!/usr/bin/env python3
"""Loopback-only Telegram API sink for isolated RC1 UAT; never calls Telegram."""
import argparse
import json
import pathlib
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

parser = argparse.ArgumentParser()
parser.add_argument('--port', type=int, default=0)
parser.add_argument('--ready-file', required=True)
parser.add_argument('--output', required=True)
args = parser.parse_args()
write_lock = threading.Lock()
output = pathlib.Path(args.output)
output.parent.mkdir(parents=True, exist_ok=True)

class Sink(BaseHTTPRequestHandler):
    def do_POST(self):
        if not self.path.startswith('/bot') or '/' not in self.path[4:]:
            self.send_error(404)
            return
        size = int(self.headers.get('Content-Length', '0'))
        if size < 0 or size > 1024 * 1024:
            self.send_error(413)
            return
        try:
            payload = json.loads(self.rfile.read(size))
        except (ValueError, UnicodeError):
            self.send_error(400)
            return
        method = self.path.rsplit('/', 1)[-1]
        # Never persist tokens or credentials: log only safe routing metadata.
        with write_lock:
            with output.open('a', encoding='utf-8') as f:
                f.write(json.dumps({'method': method, 'chatId': str(payload.get('chat_id', '')),
                                    'text': str(payload.get('text', ''))[:2048]}, ensure_ascii=False) + '\n')
        result = {'message_id': 1, 'chat': {'id': payload.get('chat_id')}, 'text': payload.get('text', '')}
        if method == 'getMe':
            result = {'id': 1, 'is_bot': True, 'username': 'tamasya_uat_only_bot'}
        elif method == 'getWebhookInfo':
            result = {'url': '', 'pending_update_count': 0}
        data = json.dumps({'ok': True, 'result': result}, ensure_ascii=False).encode('utf-8')
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def log_message(self, *_args):
        pass

with ThreadingHTTPServer(('127.0.0.1', args.port), Sink) as server:
    pathlib.Path(args.ready_file).write_text(str(server.server_port), encoding='ascii')
    server.serve_forever()
