#!/bin/bash
#
# Deliver real webhooks from the ezPayments backend to this plugin's handler.
#
# Usage: EZPAYMENTS_BACKEND_DIR=/path/to/ezPayments/backend tests/integration/run.sh
#
# Needs php on PATH and the backend's virtualenv at $EZPAYMENTS_BACKEND_DIR/.venv.

set -e

: "${EZPAYMENTS_BACKEND_DIR:?Set EZPAYMENTS_BACKEND_DIR to the ezPayments backend checkout}"

TEST_FILE="$(cd "$(dirname "$0")" && pwd)/test_backend_delivery.py"

cd "${EZPAYMENTS_BACKEND_DIR}"
# -p conftest loads the backend's root conftest, which a test file outside the
# backend tree would otherwise not pick up.
exec .venv/bin/python -m pytest -p conftest -p no:cacheprovider --no-cov -q \
    --rootdir . -c pytest.ini "${TEST_FILE}" "$@"
