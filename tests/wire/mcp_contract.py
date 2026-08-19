#!/usr/bin/env python3
"""Real HTTP MCP contract gate for WPNerve.

Uses only the Python standard library. Credentials come from environment
variables and are never printed.
"""

from __future__ import annotations

import base64
import json
import os
import ssl
import sys
import urllib.error
import urllib.request
from dataclasses import dataclass
from typing import Any

MODERN = "2026-07-28"
LEGACY = ("2025-11-25", "2025-06-18")
TOOL = "wp_nerve_site_status"


@dataclass
class Response:
    status: int
    headers: dict[str, str]
    raw: bytes

    def json(self) -> Any:
        return json.loads(self.raw.decode("utf-8")) if self.raw else None


def fail(message: str, detail: Any = None) -> None:
    suffix = "" if detail is None else f"\n  detail={detail!r}"
    raise AssertionError(message + suffix)


def check(condition: bool, message: str, detail: Any = None) -> None:
    if not condition:
        fail(message, detail)
    print(f"PASS: {message}")


def env(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"Missing required environment variable: {name}")
    return value


BASE_URL = env("WP_NERVE_BASE_URL").rstrip("/")
USER = env("WP_NERVE_USER")
PASSWORD = env("WP_NERVE_APPLICATION_PASSWORD")
ENDPOINT = BASE_URL + "/wp-json/wp-nerve/v1/mcp"
INSECURE = os.environ.get("WP_NERVE_INSECURE_TLS", "0") == "1"

if not ENDPOINT.lower().startswith("https://") and os.environ.get("WP_NERVE_ALLOW_HTTP", "0") != "1":
    raise RuntimeError("Refusing non-HTTPS wire test. Set WP_NERVE_ALLOW_HTTP=1 only for a disposable local site.")

SSL_CONTEXT = ssl.create_default_context()
if INSECURE:
    SSL_CONTEXT.check_hostname = False
    SSL_CONTEXT.verify_mode = ssl.CERT_NONE

AUTH = "Basic " + base64.b64encode(f"{USER}:{PASSWORD}".encode("utf-8")).decode("ascii")


def request(
    method: str = "POST",
    *,
    body: dict[str, Any] | bytes | None = None,
    headers: dict[str, str] | None = None,
    authenticated: bool = True,
) -> Response:
    data: bytes | None
    if isinstance(body, bytes):
        data = body
    elif body is None:
        data = None
    else:
        data = json.dumps(body, separators=(",", ":")).encode("utf-8")

    actual_headers = {"User-Agent": "wpnerve-wire-gate/1.0"}
    if data is not None:
        actual_headers["Content-Type"] = "application/json"
    if authenticated:
        actual_headers["Authorization"] = AUTH
    if headers:
        actual_headers.update(headers)

    req = urllib.request.Request(ENDPOINT, data=data, headers=actual_headers, method=method)
    try:
        with urllib.request.urlopen(req, context=SSL_CONTEXT, timeout=30) as response:
            return Response(
                response.status,
                {k.lower(): v for k, v in response.headers.items()},
                response.read(),
            )
    except urllib.error.HTTPError as error:
        return Response(
            error.code,
            {k.lower(): v for k, v in error.headers.items()},
            error.read(),
        )


def modern_meta() -> dict[str, Any]:
    return {
        "io.modelcontextprotocol/protocolVersion": MODERN,
        "io.modelcontextprotocol/clientCapabilities": {},
        "io.modelcontextprotocol/clientInfo": {"name": "wpnerve-wire-gate", "version": "1.0"},
    }


def modern_message(request_id: int, method: str, params: dict[str, Any] | None = None) -> dict[str, Any]:
    actual = dict(params or {})
    actual["_meta"] = modern_meta()
    return {"jsonrpc": "2.0", "id": request_id, "method": method, "params": actual}


def modern_headers(method: str, name: str | None = None, version: str = MODERN) -> dict[str, str]:
    headers = {"MCP-Protocol-Version": version, "Mcp-Method": method}
    if name is not None:
        headers["Mcp-Name"] = name
    return headers


def assert_private_headers(response: Response) -> None:
    cache = response.headers.get("cache-control", "").lower()
    check("no-store" in cache, "MCP responses are no-store", cache)
    check(response.headers.get("x-content-type-options", "").lower() == "nosniff", "MCP responses send nosniff")


def test_auth_boundary() -> None:
    message = modern_message(1, "server/discover")
    response = request(body=message, headers=modern_headers("server/discover"), authenticated=False)
    check(response.status == 401, "unauthenticated MCP request is rejected", response.status)


def test_origin_boundary() -> None:
    message = modern_message(2, "server/discover")
    headers = modern_headers("server/discover")
    headers["Origin"] = "https://attacker.invalid"
    response = request(body=message, headers=headers)
    check(response.status == 403, "hostile browser Origin is rejected", response.status)


def test_modern_contract() -> None:
    discovery = request(body=modern_message(10, "server/discover"), headers=modern_headers("server/discover"))
    check(discovery.status == 200, "modern server/discover returns HTTP 200", discovery.status)
    payload = discovery.json()
    check(payload.get("jsonrpc") == "2.0", "modern discovery returns JSON-RPC 2.0")
    result = payload.get("result", {})
    check(MODERN in result.get("supportedVersions", []), "modern discovery advertises current protocol")
    for legacy in LEGACY:
        check(legacy in result.get("supportedVersions", []), f"modern discovery advertises legacy {legacy}")
    assert_private_headers(discovery)

    listing = request(body=modern_message(11, "tools/list"), headers=modern_headers("tools/list"))
    check(listing.status == 200, "modern tools/list returns HTTP 200", listing.status)
    tools = listing.json().get("result", {}).get("tools", [])
    names = [tool.get("name") for tool in tools if isinstance(tool, dict)]
    check(TOOL in names, "modern tools/list exposes wp_nerve_site_status", names[:10])

    call = request(
        body=modern_message(12, "tools/call", {"name": TOOL, "arguments": {}}),
        headers=modern_headers("tools/call", TOOL),
    )
    check(call.status == 200, "modern tools/call returns HTTP 200", call.status)
    tool_result = call.json().get("result", {})
    check(tool_result.get("isError") is False, "modern site-status call succeeds", tool_result)
    structured = tool_result.get("structuredContent", {})
    check(structured.get("wpnerve_version") == "0.1.0-alpha.10", "wire call reaches alpha.10", structured)
    check(structured.get("mcp_endpoint") == ENDPOINT, "wire call reports the tested MCP endpoint", structured.get("mcp_endpoint"))


def test_legacy_contract(version: str, request_id: int) -> None:
    initialize = {
        "jsonrpc": "2.0",
        "id": request_id,
        "method": "initialize",
        "params": {
            "protocolVersion": version,
            "capabilities": {},
            "clientInfo": {"name": "wpnerve-wire-gate", "version": "1.0"},
        },
    }
    init_response = request(body=initialize, headers={"MCP-Protocol-Version": version})
    check(init_response.status == 200, f"legacy {version} initialize returns HTTP 200", init_response.status)
    init_result = init_response.json().get("result", {})
    check(init_result.get("protocolVersion") == version, f"legacy {version} negotiates exact version", init_result)

    listing = request(
        body={"jsonrpc": "2.0", "id": request_id + 1, "method": "tools/list", "params": {}},
        headers={"MCP-Protocol-Version": version},
    )
    check(listing.status == 200, f"legacy {version} tools/list returns HTTP 200", listing.status)
    names = [tool.get("name") for tool in listing.json().get("result", {}).get("tools", []) if isinstance(tool, dict)]
    check(TOOL in names, f"legacy {version} exposes wp_nerve_site_status")

    call = request(
        body={
            "jsonrpc": "2.0",
            "id": request_id + 2,
            "method": "tools/call",
            "params": {"name": TOOL, "arguments": {}},
        },
        headers={"MCP-Protocol-Version": version},
    )
    check(call.status == 200, f"legacy {version} tools/call returns HTTP 200", call.status)
    check(call.json().get("result", {}).get("isError") is False, f"legacy {version} site-status call succeeds")


def test_mirrored_header_guards() -> None:
    method_mismatch = request(
        body=modern_message(30, "server/discover"),
        headers=modern_headers("tools/list"),
    )
    check(method_mismatch.status == 400, "modern Mcp-Method/body mismatch is rejected", method_mismatch.status)
    check(method_mismatch.json().get("error", {}).get("code") == -32020, "method mismatch returns the MCP header-mismatch code")

    name_mismatch = request(
        body=modern_message(31, "tools/call", {"name": TOOL, "arguments": {}}),
        headers=modern_headers("tools/call", "wp_nerve_not_the_requested_tool"),
    )
    check(name_mismatch.status == 400, "modern Mcp-Name/body mismatch is rejected", name_mismatch.status)
    check(name_mismatch.json().get("error", {}).get("code") == -32020, "name mismatch returns the MCP header-mismatch code")


def test_version_and_transport_guards() -> None:
    unsupported = "2026-07-29"
    message = modern_message(40, "server/discover")
    message["params"]["_meta"]["io.modelcontextprotocol/protocolVersion"] = unsupported
    response = request(body=message, headers=modern_headers("server/discover", version=unsupported))
    check(response.status == 400, "unsupported modern protocol version is rejected", response.status)
    check(response.json().get("error", {}).get("code") == -32022, "unsupported protocol returns the expected error code")

    oversized = modern_message(41, "server/discover")
    oversized["padding"] = "x" * (1024 * 1024 + 4096)
    response = request(body=oversized, headers=modern_headers("server/discover"))
    check(response.status == 413, "request body over 1 MiB is rejected", response.status)

    get_response = request(method="GET", authenticated=True)
    check(get_response.status == 405, "authenticated GET on MCP endpoint is rejected with 405", get_response.status)
    check("POST" in get_response.headers.get("allow", ""), "405 advertises POST as the allowed method")


def main() -> int:
    tests = [
        test_auth_boundary,
        test_origin_boundary,
        test_modern_contract,
        lambda: test_legacy_contract(LEGACY[0], 20),
        lambda: test_legacy_contract(LEGACY[1], 23),
        test_mirrored_header_guards,
        test_version_and_transport_guards,
    ]

    try:
        for test in tests:
            test()
    except Exception as exc:  # deliberate top-level gate reporting
        print(f"WPNERVE_MCP_WIRE_FAIL: {exc}", file=sys.stderr)
        return 1

    print("WPNERVE_MCP_WIRE_OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
