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

Border detection: scan inward from each edge on a downscaled grayscale copy;
a row/column counts as border when >=97% of its pixels are near-black
(gray < 14). Film borders are uniformly black, so a near-black strip carries
no visible content and is always safe to trim. Thin strips (<0.4% of the
dimension) are ignored to skip JPEG edge noise.

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

# Border detection tuning
BLACK_LEVEL = 14        # grayscale value below which a pixel counts as black
LINE_COVERAGE = 0.97    # fraction of a row/col that must be black to be border
MIN_BORDER_FRAC = 0.004 # ignore thinner strips (JPEG edge noise)
MAX_BORDER_FRAC = 0.35  # sanity cap per side


def fail(reason: str) -> None:
    print(json.dumps({"x": None, "y": None, "borders": None, "error": reason}))


def detect_borders(img):
    """Return border insets as fractions {top, right, bottom, left}."""
    height, width = img.shape[:2]

    # Downscale for speed; border strips survive this fine.
    scale = 400.0 / max(height, width)
    if scale < 1.0:
        small = cv2.resize(img, (int(width * scale), int(height * scale)),
                           interpolation=cv2.INTER_AREA)
    else:
        small = img
    gray = cv2.cvtColor(small, cv2.COLOR_BGR2GRAY)
    black = gray < BLACK_LEVEL
    sh, sw = gray.shape

    def edge_depth(lines):
        depth = 0
        for line in lines:
            if line.mean() >= LINE_COVERAGE:
                depth += 1
            else:
                break
        return depth

    top = edge_depth(black[i, :] for i in range(sh))
    bottom = edge_depth(black[sh - 1 - i, :] for i in range(sh))
    left = edge_depth(black[:, j] for j in range(sw))
    right = edge_depth(black[:, sw - 1 - j] for j in range(sw))

    insets = {
        "top": top / sh,
        "bottom": bottom / sh,
        "left": left / sw,
        "right": right / sw,
    }
    for key, frac in insets.items():
        if frac < MIN_BORDER_FRAC or frac > MAX_BORDER_FRAC:
            insets[key] = 0.0
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
