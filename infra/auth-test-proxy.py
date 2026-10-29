"""Loopback-only HTTPS gateway for the disposable auth test database/backend.

Never points at the host PostgreSQL or published application. No request logging.
"""

import http.client
import mimetypes
import socket
import ssl
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

PRIVATE = Path("/run/salvadindin-auth-test")
STATIC = Path(__file__).resolve().parents[1] / "frontend/dist"


class UnixConnection(http.client.HTTPConnection):
    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(15)
        self.sock.connect(str(PRIVATE / "shared/backend.sock"))


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def handle_request(self):
        if self.path.startswith("/api/"):
            length = int(self.headers.get("Content-Length", 0))
            if length > 100000:
                self.send_error(413)
                return
            body = self.rfile.read(length) if length else None
            upstream = UnixConnection("localhost")
            upstream.request(self.command, self.path, body, dict(self.headers))
            result = upstream.getresponse()
            data = result.read()
            self.send_response(result.status)
            for key, value in result.getheaders():
                if key.lower() not in (
                    "transfer-encoding",
                    "connection",
                    "content-length",
                    "server",
                    "date",
                ):
                    self.send_header(key, value)
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)
            upstream.close()
        else:
            candidate = (STATIC / self.path.split("?", 1)[0].lstrip("/")).resolve()
            if not candidate.is_relative_to(STATIC) or not candidate.is_file():
                candidate = STATIC / "index.html"
            data = candidate.read_bytes()
            self.send_response(200)
            self.send_header(
                "Content-Type",
                mimetypes.guess_type(str(candidate))[0] or "application/octet-stream",
            )
            self.send_header("Content-Length", str(len(data)))
            self.send_header(
                "Content-Security-Policy",
                "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
            )
            self.end_headers()
            self.wfile.write(data)

    do_GET = handle_request
    do_POST = handle_request
    do_PATCH = handle_request
    do_DELETE = handle_request


if __name__ == "__main__":
    server = ThreadingHTTPServer(("127.0.0.1", 15443), Handler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(PRIVATE / "tls.crt", PRIVATE / "tls.key")
    server.socket = context.wrap_socket(server.socket, server_side=True)
    server.serve_forever()
