#!/usr/bin/env python3
"""
Detect the subject focal point of a background image, and detect/remove
solid black borders (film borders on lab scans).

Used by Montréal Analogue's Erugo fork for the rotating landing-page
backgrounds:

- Smart-crop: instead of always center-cropping (which cuts off portrait
  subjects on narrow viewports), the frontend positions the crop on the
  detected focal point via CSS `background-position`.
- Border removal: scans with black film borders get a border-cropped display
  copy so the slideshow never shows the black frame edges. Originals are
  never modified.

Method: YuNet DNN face detection (OpenCV) on the border-cropped region.
BestOf photos are overwhelmingly portraits, so the score-weighted face
centroid is the right "best guess". No face found -> null (caller falls back
to a centered crop, i.e. the old behaviour). Nothing ever gets worse than
today.

Border detection: scan inward from each edge at full resolution
(downscaling blurs the sharp white->black film edge). Two lab-scan border
styles are handled: a black film rebate right at the image edge, and a
white scanner surround outside the black film strip ([white][black][photo]).
Per edge: skip the white surround, allow a short gray lead-in, then
consume the black run; require a black core (>=80% film-black).
The film rebate is very black (mean ~15), so the strict core threshold
keeps dark photo content safe. Thin cores (<0.15% of the dimension) are
ignored to skip JPEG edge noise.

Usage: python3 detect_focal_point.py /path/to/image.jpg [--crop-output /path/to/cropped.jpg]
Output (stdout, JSON):
  {"x": 0.62, "y": 0.38,
   "borders": {"top": 0.08, "right": 0.0, "bottom": 0.08, "left": 0.0}}
  (x/y are fractions of the *cropped* region when borders were found,
   otherwise fractions of the full image; nulls when no subject/unreadable)
"""

import argparse
import json
import os
import sys

MODEL_FILENAME = "face_detection_yunet_2023mar.onnx"
SCORE_THRESHOLD = 0.6

# Border detection tuning.
#
# Lab scans come in two border styles:
#   1. Black film rebate right at the image edge.
#   2. White scanner surround outside a black film strip:
#      [white][black][photo].
# Both are detected per edge at full resolution (downscaling blurs the
# sharp white->black film edge) in three phases: strict white surround,
# short gray lead-in, then the black run. A black core (>=80% of the line
# darker than gray 25) is required — that is the distinctive film-border
# signature. The film rebate is very black (mean ~15); the strict core
# threshold keeps dark photo content (shadows, dark backgrounds) safe.
FILM_BLACK = 25         # grayscale value below which a pixel is film-black
WHITE_LEVEL = 180       # line mean above which it is white surround
STRIP_COVERAGE = 0.8    # fraction of a line that must be film-black for core
MIN_STRIP_FRAC = 0.0015 # ignore thinner cores (JPEG edge noise)
MAX_STRIP_FRAC = 0.03   # black core sanity cap per side (film is thin)
MAX_TOTAL_FRAC = 0.12   # surround+strip combined sanity cap per side


def fail(reason: str) -> None:
    print(json.dumps({"x": None, "y": None, "borders": None, "error": reason}))


def _side_inset(line_means, line_black_fracs, n):
    """Border inset (fraction) for one edge.

    line_means / line_black_fracs are per-line (row or column) stats
    ordered from the edge inward; n is the dimension length.

    Three phases: skip the white scanner surround, allow a short gray
    lead-in (the white->black transition) to reach the black run, then
    consume the black run. A black core (>=80% film-black) is required
    within the run — that is the distinctive film-border signature.
    """
    i = 0
    # Phase 1: white scanner surround.
    while i < n and line_means[i] > WHITE_LEVEL:
        i += 1
    # Phase 2: short gray lead-in (white->black transition) to the black run.
    # A sharp film edge transitions in a few pixels; a photo gradient takes
    # longer. Cap at 0.5% to reject gradients.
    j = i
    while j < n and line_black_fracs[j] <= 0.7 and (j - i) / n < 0.005:
        j += 1
    # Phase 3: consume the black run. The 0.7 threshold stops at dark
    # photo content (which can be ~0.6 black) instead of eating into it.
    k = j
    while k < n and line_black_fracs[k] > 0.7:
        k += 1
    if k <= j:
        return 0.0
    # Require a black core within the run — the film-border signature.
    # The core must be thin (film borders are narrow); a long "core" is a
    # dark photo gradient, not a border.
    core_rows = [r for r in range(j, k) if line_black_fracs[r] > 0.8]
    core_len = len(core_rows)
    if core_len / n < MIN_STRIP_FRAC:
        return 0.0
    if core_len / n > MAX_STRIP_FRAC:
        return 0.0
    # Crop tight to the core plus a small transition margin. The black run
    # can extend into dark photo content; cropping to the run end would
    # eat the photo.
    inset = (core_rows[-1] + 1 + int(0.005 * n)) / n
    if inset > MAX_TOTAL_FRAC:
        return 0.0
    return inset


def detect_borders(img):
    """Return border insets as fractions {top, right, bottom, left}.

    Runs at full resolution: downscaling blurs the sharp white->black film
    edge into intermediate gray columns that confuse the detector.
    """
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY).astype("float32")
    black = gray < FILM_BLACK
    sh, sw = gray.shape

    col_means = gray.mean(axis=0)
    col_black = black.mean(axis=0)
    row_means = gray.mean(axis=1)
    row_black = black.mean(axis=1)

    insets = {
        "top": _side_inset(row_means, row_black, sh),
        "bottom": _side_inset(row_means[::-1], row_black[::-1], sh),
        "left": _side_inset(col_means, col_black, sw),
        "right": _side_inset(col_means[::-1], col_black[::-1], sw),
    }
    # Guard against degenerate crops (borders must not swallow the image).
    if insets["top"] + insets["bottom"] >= 0.9:
        insets["top"] = insets["bottom"] = 0.0
    if insets["left"] + insets["right"] >= 0.9:
        insets["left"] = insets["right"] = 0.0
    return insets


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("image")
    parser.add_argument("--crop-output", default=None,
                        help="write the border-cropped image here (only when "
                             "borders are actually found)")
    args = parser.parse_args()

    global cv2
    try:
        import cv2 as _cv2
        cv2 = _cv2
    except ImportError:
        fail("opencv-missing")
        return

    model_path = os.path.join(
        os.path.dirname(os.path.abspath(__file__)), "opencv-data", MODEL_FILENAME
    )
    if not os.path.isfile(model_path):
        fail("model-missing")
        return

    # Plain imread: honours EXIF orientation on modern OpenCV, so phone
    # scans are analysed upright.
    img = cv2.imread(args.image)
    if img is None:
        fail("unreadable")
        return

    height, width = img.shape[:2]
    if width < 64 or height < 64:
        fail("too-small")
        return

    borders = detect_borders(img)
    has_borders = any(v > 0 for v in borders.values())

    if has_borders:
        x0 = int(round(borders["left"] * width))
        x1 = int(round(borders["right"] * width))
        y0 = int(round(borders["top"] * height))
        y1 = int(round(borders["bottom"] * height))
        crop = img[y0:height - y1, x0:width - x1]
        if crop.size == 0:  # paranoia; guards above should prevent this
            crop = img
            has_borders = False
            borders = {k: 0.0 for k in borders}
    else:
        crop = img

    if has_borders and args.crop_output:
        out_dir = os.path.dirname(os.path.abspath(args.crop_output))
        os.makedirs(out_dir, exist_ok=True)
        params = []
        if args.crop_output.lower().endswith((".jpg", ".jpeg")):
            params = [cv2.IMWRITE_JPEG_QUALITY, 92]
        try:
            cv2.imwrite(args.crop_output, crop, params)
        except Exception:
            pass  # caller checks whether the file exists

    ch, cw = crop.shape[:2]
    result = {"x": None, "y": None,
              "borders": {k: round(v, 4) for k, v in borders.items()}}

    try:
        detector = cv2.FaceDetectorYN_create(
            model_path, "", (cw, ch),
            score_threshold=SCORE_THRESHOLD, nms_threshold=0.3, top_k=20,
        )
    except Exception:
        print(json.dumps(result))
        return

    _, faces = detector.detect(crop)
    if faces is not None and len(faces) > 0:
        # faces rows: [x, y, w, h, 5x landmarks (10), score]. Weight the
        # centroid by detection score so confident faces dominate.
        total = 0.0
        cx = 0.0
        cy = 0.0
        for face in faces:
            score = float(face[14])
            fx = float(face[0]) + float(face[2]) / 2.0
            fy = float(face[1]) + float(face[3]) / 2.0
            cx += fx * score
            cy += fy * score
            total += score

        if total > 0:
            # Keep a small margin from the edges so the crop never slams the
            # subject against the frame.
            fx = min(0.9, max(0.1, (cx / total) / cw))
            fy = min(0.9, max(0.1, (cy / total) / ch))
            result["x"] = round(fx, 4)
            result["y"] = round(fy, 4)

    print(json.dumps(result))


if __name__ == "__main__":
    main()
