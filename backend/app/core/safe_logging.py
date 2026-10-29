"""HTTP logging without query strings, headers, bodies or exception payloads."""

import logging
import os
import re
from copy import copy


class SafeFormatter(logging.Formatter):
    def formatException(self, exc_info):
        return "exception_type=" + exc_info[0].__name__

    def format(self, record):
        record = copy(record)
        record.exc_text = None  # Never reuse another formatter's unsanitized traceback.
        record.stack_info = None
        message = super().format(record)
        for key, value in os.environ.items():
            if len(value) >= 8 and any(
                word in key.upper()
                for word in (
                    "SECRET",
                    "TOKEN",
                    "PASSWORD",
                    "DATABASE_URL",
                )
            ):
                message = message.replace(value, "[redacted]")
        message = re.sub(r"(?i)(https?://[^\s?]+)\?[^\s]*", r"\1?[redacted]", message)
        message = re.sub(
            r"(?i)((?:GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\s+[^\s?]+)\?[^\s]*",
            r"\1?[redacted]",
            message,
        )
        message = re.sub(
            r"(?i)\b(authorization|cookie|set-cookie)\s*[:=][^\r\n]*", r"\1=[redacted]", message
        )
        message = re.sub(
            r"(?i)\b(hub\.verify_token|access_token|app_secret|password|token)\s*[:=]\s*[^\s&;,]+",
            r"\1=[redacted]",
            message,
        )
        return message.replace("\r", r"\r").replace("\n", r"\n")


class SafeAccessFormatter(SafeFormatter):
    def format(self, record):
        record = copy(record)
        if isinstance(record.args, tuple) and len(record.args) == 5:
            client, method, path, _protocol, status = record.args
            record.msg = "client=%s method=%s path=%s status=%s"
            record.args = (client, method, str(path).split("?", 1)[0], status)
        else:
            record.msg, record.args = "HTTP access event", ()
        return super().format(record)
