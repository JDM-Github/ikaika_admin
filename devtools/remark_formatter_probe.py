#!/usr/bin/env python3
"""Probe an Ollama model against the remark formatter before we wire it in.

Sends a remark, prints the model's reply verbatim (the "model:" block below), then
checks it against the template the portal would store: a Date line from the record's
own date, then "What/Changed/Wins: <one line>" using only known labels, every fact
kept and none invented.

Stdlib only -- runs anywhere, no pip, nothing to deploy.

    python devtools/remark_formatter_probe.py --remark "fixed login, removed old banner"
    python devtools/remark_formatter_probe.py                    # the built-in samples
    python devtools/remark_formatter_probe.py --remark "..." --day "October 7, 2026"
    python devtools/remark_formatter_probe.py --selftest         # check the checker, no model

With OLLAMA_API_KEY set (in the environment or ikaika_admin/.env) it targets the
hosted API, https://ollama.com, so the same script tests a deployed setup with no
local Ollama. Point it anywhere with OLLAMA_HOST.
"""

from __future__ import annotations

import argparse
import datetime
import json
import os
import sys
import urllib.error
import urllib.request
from pathlib import Path

DEFAULT_MODEL = "gemma4:31b-cloud"
DEFAULT_HOST = "http://localhost:11434"
OLLAMA_CLOUD = "https://ollama.com"
LABELS = ("What", "Changed", "Wins")

ENV_FILES = (Path(__file__).resolve().parent.parent / ".env", Path(".env"))


def dotenv_value(key: str) -> str:
    """The key lives in ikaika_admin/.env on this project, so the probe reads it too."""
    for file in ENV_FILES:
        try:
            for line in file.read_text(encoding="utf-8", errors="replace").splitlines():
                line = line.strip()
                if line.startswith("#") or "=" not in line:
                    continue
                name, _, value = line.partition("=")
                if name.strip() == key:
                    return value.strip().strip('"').strip("'")
        except OSError:
            continue
    return ""


def setting(key: str) -> str:
    return os.environ.get(key) or dotenv_value(key)


SYSTEM = (
    "You tidy one short work remark for a daily work log.\n"
    "Reply with the person's own facts, better written, in this exact template and nothing else -- "
    "no preamble, no notes, no code fences:\n"
    "Date: <the date you were given, word for word>\n"
    "What: <what the person did>\n"
    "Changed: <what changed because of it>\n"
    "Wins: <anything worth calling a win>\n"
    "\n"
    "Rules:\n"
    "- Fix grammar, spelling, punctuation and word order. Write each line as work already done, in "
    "past tense, starting with a capital and ending with a period.\n"
    "- Keep the meaning exactly. Add no fact, number, name or date that the remark does not state.\n"
    "- Keep each line short -- about a dozen words.\n"
    "- Leave out any field the remark says nothing about. Never write 'none', 'N/A' or 'nothing'.\n"
    "- Never invent the Date: copy the one you were given.\n"
    "- If the remark is blank, reply with the Date line alone.\n"
    "\n"
    "Worked example\n"
    "Remark: fix login bug n remove old banner, users can log in now\n"
    "What: Fixed the login bug and removed the old banner.\n"
    "Changed: Users can log in again.\n"
)

SAMPLES = (
    ("October 7, 2026", "Update portal, add blah blah"),
    ("October 8, 2026", "fixed the login bug and removed the old banner"),
    ("October 9, 2026", ""),
    ("October 10, 2026", "did the timesheet then also updated report, uploaded the files"),
)


def ask(host: str, key: str, model: str, day: str, remark: str, timeout: int) -> str:
    payload = {
        "model": model,
        "messages": [
            {"role": "system", "content": SYSTEM},
            {"role": "user", "content": f"Date: {day}\nRemark: {remark or '(blank)'}"},
        ],
        "stream": False,
        "options": {"temperature": 0},
    }
    headers = {"Content-Type": "application/json"}
    if key:
        headers["Authorization"] = f"Bearer {key}"
    request = urllib.request.Request(
        f"{host.rstrip('/')}/api/chat", data=json.dumps(payload).encode("utf-8"), headers=headers
    )
    with urllib.request.urlopen(request, timeout=timeout) as response:
        body = json.loads(response.read().decode("utf-8"))
    return (body.get("message") or {}).get("content", "").strip()


def check(day: str, remark: str, output: str) -> list[str]:
    """The smallest thing that fails if the model drifts off the template."""
    problems: list[str] = []
    lines = [line.strip() for line in output.splitlines() if line.strip()]
    if not lines:
        return ["empty reply"]
    if not lines[0].lower().startswith("date:"):
        problems.append("first line is not 'Date: ...'")
    elif day not in lines[0]:
        problems.append(f"date changed or invented (wanted {day!r})")
    for line in lines[1:]:
        label = line.split(":", 1)[0].strip()
        if label not in LABELS:
            problems.append(f"unknown label {label!r} in {line!r}")
    if remark.strip() == "" and len(lines) > 1:
        problems.append("invented content for a blank remark")
    return problems


def selftest() -> int:
    """check() is what decides pass/fail, so prove it on hand-made replies."""
    cases = (
        ("October 7, 2026", "Update portal", "Date: October 7, 2026\nWhat: Updated the portal.", []),
        ("October 7, 2026", "Update portal", "Date: October 8, 2026\nWhat: x", ["date changed or invented (wanted 'October 7, 2026')"]),
        ("October 7, 2026", "Update portal", "Date: October 7, 2026\nSummary: x", ["unknown label 'Summary' in 'Summary: x'"]),
        ("October 9, 2026", "", "Date: October 9, 2026\nWhat: nothing", ["invented content for a blank remark"]),
        ("October 9, 2026", "", "Date: October 9, 2026", []),
        ("October 7, 2026", "x", "Date: October 7, 2026\n\n", []),
    )
    for day, remark, output, wanted in cases:
        got = check(day, remark, output)
        if got != wanted:
            print(f"FAIL {output!r}\n  wanted {wanted}\n  got    {got}")
            return 1
    print(f"selftest: {len(cases)}/{len(cases)} ok")
    return 0


def today() -> str:
    now = datetime.date.today()
    return f"{now:%B} {now.day}, {now.year}"


def main() -> int:
    parser = argparse.ArgumentParser(description="Probe an Ollama model on the remark formatter.")
    parser.add_argument("--model", default="", help=f"default {DEFAULT_MODEL}")
    parser.add_argument("--host", default="", help="default: ollama.com with a key, else localhost:11434")
    parser.add_argument("--key", default="", help="default: OLLAMA_API_KEY from the environment or .env")
    parser.add_argument("--remark", default="", help="your own remark; omit to run the built-in samples")
    parser.add_argument("--day", default="", help="the Date given to the model; default today")
    parser.add_argument("--timeout", type=int, default=120)
    parser.add_argument("--selftest", action="store_true", help="check the checker, no model needed")
    args = parser.parse_args()

    if args.selftest:
        return selftest()

    key = args.key or setting("OLLAMA_API_KEY")
    host = args.host or setting("OLLAMA_HOST") or (OLLAMA_CLOUD if key else DEFAULT_HOST)
    model = args.model or setting("OLLAMA_MODEL") or DEFAULT_MODEL
    cases = ((args.day or today(), args.remark),) if args.remark else SAMPLES

    print(f"model: {model}\nhost : {host}\nkey  : {'from .env/env' if key else 'none (local)'}\n")
    failures = 0
    for day, remark in cases:
        print(f"remark : {remark or '(nothing typed)'}")
        try:
            output = ask(host, key, model, day, remark, args.timeout)
        except urllib.error.HTTPError as error:
            print(f"model  : HTTP {error.code}: {error.read().decode('utf-8', 'replace').strip()[:200]}\n")
            failures += 1
            continue
        except (urllib.error.URLError, TimeoutError, OSError) as error:
            print(f"  cannot reach {host}: {error}")
            print("  start Ollama, or set OLLAMA_HOST=https://ollama.com and OLLAMA_API_KEY.\n")
            return 1

        for line in output.splitlines():
            print(f"  {line}")
        problems = check(day, remark, output)
        print(f"check  : {'ok' if not problems else '; '.join(problems)}")
        print()
        failures += 1 if problems else 0

    print(f"{len(cases) - failures}/{len(cases)} met the template.")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
