# Regression fixtures for the film-border detector.
#
# Run from the repo root (needs the detector's cv2 environment, e.g. inside
# the erugo container):
#   python3 scripts/tests/test_border_detector.py \
#       --script scripts/detect_focal_point.py \
#       --fixtures scripts/tests/fixtures
#
# Fixtures (960x640 synthetic):
#   01_border_all      black strip on all four sides
#   02_white_surround  white scanner surround outside the black strip
#   03_interrupted_top top strip with bright light-leak gaps
#   04_dark_edges      smooth dark vignette, no sharp step -> no crop
#   05_uneven          thin uneven strips, different per side
