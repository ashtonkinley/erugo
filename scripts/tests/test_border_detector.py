#!/usr/bin/env python3
"""Regression tests for the film-border detector (scripts/detect_focal_point.py).

Usage: test_border_detector.py --script <path/to/detect_focal_point.py> --fixtures <dir>
Exit 0 when every fixture behaves as expected, 1 otherwise.
"""
import argparse, json, subprocess, sys, os

CASES = {
    # name: expected per-side inset ranges (fractions). Strips are thin on
    # purpose: the detector caps the black core at 3% per side (film is
    # thin), so fixtures must live inside the real design envelope.
    '01_border_all':      {'top': (0.015, 0.035), 'bottom': (0.015, 0.04), 'left': (0.01, 0.035), 'right': (0.01, 0.035)},
    '02_white_surround':  {'top': (0.03, 0.07), 'bottom': (0.03, 0.07), 'left': (0.02, 0.06), 'right': (0.02, 0.06)},
    '03_interrupted_top': {'top': (0.015, 0.035), 'bottom': (0.0, 0.0), 'left': (0.0, 0.0), 'right': (0.0, 0.0)},
    '04_dark_edges':      {'top': (0.0, 0.0), 'bottom': (0.0, 0.0), 'left': (0.0, 0.0), 'right': (0.0, 0.0)},
    '05_uneven':          {'top': (0.01, 0.03), 'bottom': (0.015, 0.04), 'left': (0.008, 0.03), 'right': (0.005, 0.025)},
}

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--script', required=True)
    ap.add_argument('--fixtures', required=True)
    ap.add_argument('--probe', action='store_true', help='print raw outputs, no assertions')
    args = ap.parse_args()

    failures = 0
    for name in sorted(CASES):
        path = os.path.join(args.fixtures, name + '.jpg')
        if not os.path.exists(path):
            print(f"SKIP {name}: fixture missing"); failures += 1; continue
        r = subprocess.run([sys.executable, args.script, path],
                           capture_output=True, text=True, timeout=300)
        try:
            res = json.loads(r.stdout.strip().splitlines()[-1])
        except Exception:
            print(f"FAIL {name}: no JSON output\n{r.stdout[-500:]}\n{r.stderr[-500:]}")
            failures += 1
            continue
        b = res['borders']
        if args.probe:
            print(f"{name}: {json.dumps(b)}")
            continue
        ok = True
        for side, (lo, hi) in CASES[name].items():
            v = b[side]
            if not (lo - 1e-9 <= v <= hi + 1e-9):
                print(f"FAIL {name}: {side}={v:.4f} expected [{lo},{hi}]")
                ok = False
        if ok:
            print(f"ok   {name}: {json.dumps({s: round(b[s],4) for s in b})}")
        else:
            failures += 1
    print("PASS" if failures == 0 else f"{failures} FAILURES")
    return 1 if failures else 0

if __name__ == '__main__':
    sys.exit(main())
