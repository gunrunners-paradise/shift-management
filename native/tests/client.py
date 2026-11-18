import concurrent.futures
import datetime as dt
import http.client
import http.cookies
import json
import os
from pathlib import Path
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import threading
import time
import unittest


ROOT = Path(__file__).resolve().parents[1]
BINARY = Path(os.environ.get("MISHMERET_BIN", ROOT / "build" / "mishmeret")).resolve()
SUNDAY = dt.date(2099, 1, 1)
SUNDAY += dt.timedelta(days=(6 - SUNDAY.weekday()) % 7)
WEEK = SUNDAY.isoformat()
DAYS = [(SUNDAY + dt.timedelta(days=i)).isoformat() for i in range(7)]


class Client:
    def __init__(self, port):
        self.port = port
        self.origin = f"http://127.0.0.1:{port}"
        self.cookies = {}
        self.csrf = None

    def request(self, method, path, data=None, *, origin=True, csrf=True, raw=None, chunks=None, forwarded=None):
        headers = {}
        if forwarded is not None:
            headers["X-Forwarded-For"] = forwarded
        if origin is not False:
            headers["Origin"] = self.origin if origin is True else origin
        if self.cookies:
            headers["Cookie"] = "; ".join(f"{k}={v}" for k, v in self.cookies.items())
        if csrf is not False and self.csrf:
            headers["X-CSRF-Token"] = self.csrf if csrf is True else csrf
        body = chunks if chunks is not None else raw
        if data is not None:
            body = json.dumps(data, ensure_ascii=False).encode("utf-8")
        if body is not None:
            headers["Content-Type"] = "application/json"
        connection = http.client.HTTPConnection("127.0.0.1", self.port, timeout=10)
        try:
            connection.request(method, path, body=body, headers=headers, encode_chunked=chunks is not None)
            response = connection.getresponse()
            content = response.read()
            pairs = response.getheaders()
            for name, value in pairs:
                if name.lower() == "set-cookie":
                    parsed = http.cookies.SimpleCookie()
                    parsed.load(value)
                    for key, morsel in parsed.items():
                        if morsel.value and morsel["max-age"] != "0":
                            self.cookies[key] = morsel.value
                        else:
                            self.cookies.pop(key, None)
            response_headers = {k.lower(): v for k, v in pairs}
            if "application/json" in response_headers.get("content-type", ""):
                payload = json.loads(content)
            else:
                payload = content.decode("utf-8", errors="replace")
            if isinstance(payload, dict) and payload.get("csrf"):
                self.csrf = payload["csrf"]
            return response.status, payload, response_headers
        finally:
            connection.close()
