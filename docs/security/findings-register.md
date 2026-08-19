# G9 findings register

This is the canonical disposition register for WPNerve's independent security
review. Do not mark G9 complete until every row has a final disposition and no
Critical or High finding remains open.

## Review metadata

| Field | Value |
|---|---|
| Reviewed commit | _pending_ |
| Reviewer | _pending_ |
| Review start | _pending_ |
| Review end | _pending_ |
| WordPress/PHP matrix used | _pending_ |
| G9 final decision | **Pending** |

## Severity model

- **Critical** — unauthenticated/low-friction compromise, arbitrary code execution,
  credential theft, or broad site/network takeover.
- **High** — privilege escalation, destructive action, persistent cross-boundary
  bypass, or secret disclosure with realistic preconditions.
- **Medium** — meaningful security weakness requiring stronger preconditions or
  producing bounded impact.
- **Low** — defense-in-depth weakness, limited information leak or hard-to-exploit
  edge case.
- **Informational** — hardening/documentation observation without direct impact.

## Findings

| ID | Severity | Title | Status | Affected commit | Fix commit | Retest |
|---|---|---|---|---|---|---|
| _none yet_ | — | Independent review not yet executed | Open | — | — | — |

## Finding detail template

Copy this section for every finding.

### G9-XXX — Title

- **Severity:**
- **Status:** Open / Fixed / Accepted / False positive
- **Affected commit:**
- **Affected files/functions:**
- **Required capability / preconditions:**
- **Invariant violated:**
- **Impact:**
- **Reproduction / PoC:**
- **Recommended remediation:**
- **Fix commit:**
- **Independent retest:**
- **Residual risk / beta limitation:**

## Disposition rules

- Critical/High findings cannot be risk-accepted for the beta. They must be fixed,
  independently retested, or the affected capability removed from beta scope.
- Medium/Low acceptance requires an explicit residual-risk statement and must be
  copied into beta known limitations when user/operator behavior is relevant.
- A fix is not closed until the reviewer retests the exact fix commit.
- Findings discovered after the review freeze receive a new ID; do not rewrite
  old IDs to make the register appear clean.
