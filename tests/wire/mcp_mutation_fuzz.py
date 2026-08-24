#!/usr/bin/env python3
"""Deterministic real-HTTP mutation fuzz gate for WPNerve MCP.

This complements the unit corpus by exercising WordPress' HTTP/REST/JSON stack.
It deliberately stays below the default 120-request/minute MCP budget so a
normal disposable staging run does not need rate-limit overrides.
"""

from __future__ import annotations

import copy
import json
import random
import sys
from typing import Any

import mcp_contract as wire

SEED = 0x57504E45525645
CASES = 60


def fail(message: str, detail: Any = None) -> None:
    suffix = "" if detail is None else f"\n  detail={detail!r}"
    raise AssertionError(message + suffix)


def check(condition: bool, message: str, detail: Any = None) -> None:
    if not condition:
        fail(message, detail)


def base_message(request_id: int) -> dict[str, Any]:
    return wire.modern_message(request_id, "tools/list")


def mutate(rng: random.Random, index: int) -> tuple[dict[str, Any] | bytes, dict[str, str]]:
    message = base_message(1000 + index)
    headers = wire.modern_headers("tools/list")
    mode = index % 12

    if mode == 0:
        return b'{"jsonrpc":"2.0","id":1,"method":', headers
    if mode == 1:
        message["jsonrpc"] = rng.choice([None, True, 2, "1.0", "2.00"])
    elif mode == 2:
        message["id"] = rng.choice([True, 1.25, {"x": 1}, [1]])
    elif mode == 3:
        message["method"] = rng.choice(["", "x" * 4096, "tools/\x00list", "unknown/method"])
    elif mode == 4:
        message["params"] = rng.choice([None, "x", 42, True, [1, 2, 3]])
    elif mode == 5:
        meta = message["params"]["_meta"]
        meta["io.modelcontextprotocol/protocolVersion"] = rng.choice(
            ["", "2099-01-01", "2026-07-27", 42]
        )
    elif mode == 6:
        meta = message["params"]["_meta"]
        meta["io.modelcontextprotocol/clientCapabilities"] = rng.choice(
            [None, True, "all", 7, ["tools"]]
        )
    elif mode == 7:
        headers["Mcp-Method"] = rng.choice(["tools/call", "", "UNKNOWN"])
    elif mode == 8:
        call = wire.modern_message(
            1000 + index,
            "tools/call",
            {"name": wire.TOOL, "arguments": {}},
        )
        headers = wire.modern_headers("tools/call", rng.choice(["wrong", "=?base64?@@@?=", "x" * 2048]))
        return call, headers
    elif mode == 9:
        nested: Any = {"leaf": "ok"}
        for _ in range(rng.randint(20, 80)):
            nested = {"n": nested}
        message["params"]["noise"] = nested
    elif mode == 10:
        message["params"]["noise"] = [rng.randint(-2**31, 2**31 - 1) for _ in range(256)]
    else:
        # Unknown members must never become authority. Keep the core envelope valid
        # while injecting attacker-controlled metadata at several levels.
        message["attacker"] = {"Authorization": "Bearer should-not-be-trusted"}
        message["params"]["_meta"]["client_id"] = "attacker-controlled"
        message["params"]["_meta"]["role"] = "administrator"

    return message, headers


def main() -> int:
    rng = random.Random(SEED)
    secret_bytes = wire.PASSWORD.encode("utf-8")
    auth_bytes = wire.AUTH.encode("utf-8")

    try:
        for index in range(CASES):
            body, headers = mutate(rng, index)
            response = wire.request(body=body, headers=headers)

            check(response.status < 500, "mutation must never produce a server error", (index, response.status))
            check(response.status >= 200, "mutation returns a valid HTTP status", (index, response.status))
            check(secret_bytes not in response.raw, "Application Password is never reflected", index)
            check(auth_bytes not in response.raw, "Authorization header is never reflected", index)
            check(len(response.raw) <= 2 * 1024 * 1024, "mutation response remains bounded", (index, len(response.raw)))

            if response.raw and response.headers.get("content-type", "").lower().startswith("application/json"):
                try:
                    json.loads(response.raw.decode("utf-8"))
                except (UnicodeDecodeError, json.JSONDecodeError) as exc:
                    fail("JSON response must remain parseable", (index, str(exc)))

            if response.status == 429:
                fail(
                    "mutation corpus exhausted the default MCP budget; run after the rate window resets",
                    index,
                )

    except Exception as exc:  # deliberate top-level evidence marker
        print(f"WPNERVE_MCP_MUTATION_FUZZ_FAIL: {exc}", file=sys.stderr)
        return 1

    print(f"WPNERVE_MCP_MUTATION_FUZZ_OK cases={CASES} seed={SEED}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
