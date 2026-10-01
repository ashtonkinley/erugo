#!/usr/bin/env python3
"""
Detect the subject focal point of a background image.

Used by Montréal Analogue's Erugo fork to smart-crop rotating landing-page
backgrounds: instead of always center-cropping (which cuts off portrait
subjects on narrow viewports), the frontend positions the crop on the
detected focal point via CSS `background-position`.

Method: YuNet DNN face detection (OpenCV). BestOf photos are overwhelmingly
portraits, so the score-weighted face centroid is the right "best guess".
No face found -> null (caller falls back to a centered crop, i.e. the old
behaviour). Nothing ever gets worse than today.

Usage: python3 detect_focal_point.py /path/to/image.jpg
Output (stdout, JSON): {"x": 0.62, "y": 0.38}  (fractions of width/height)
                       {"x": null, "y": null}   (no subject found / unreadable)
"""

import json
import os
import sys

MODEL_FILENAME = "face_detection_yunet_2023mar.onnx"
SCORE_THRESHOLD = 0.6


def fail(reason: str) -> None:
    print(json.dumps({"x": None, "y": None, "error": reason}))


def main() -> None:
    if len(sys.argv) != 2:
        fail("usage")
        return

    try:
        import cv2
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
    img = cv2.imread(sys.argv[1])
    if img is None:
        fail("unreadable")
        return

    height, width = img.shape[:2]
    if width < 64 or height < 64:
        fail("too-small")
        return

    try:
        detector = cv2.FaceDetectorYN_create(
            model_path, "", (width, height),
            score_threshold=SCORE_THRESHOLD, nms_threshold=0.3, top_k=20,
        )
    except Exception:
        fail("detector-init")
        return

    _, faces = detector.detect(img)
    if faces is None or len(faces) == 0:
        print(json.dumps({"x": None, "y": None}))
        return

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

    if total <= 0:
        print(json.dumps({"x": None, "y": None}))
        return

    # Keep a small margin from the edges so the crop never slams the subject
    # against the frame.
    fx = min(0.9, max(0.1, (cx / total) / width))
    fy = min(0.9, max(0.1, (cy / total) / height))

    print(json.dumps({"x": round(fx, 4), "y": round(fy, 4)}))


if __name__ == "__main__":
    main()
