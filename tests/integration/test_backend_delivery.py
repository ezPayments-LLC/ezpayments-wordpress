"""Deliver real ezPayments webhooks to the plugin's handler.

Runs inside the ezPayments backend test environment (see run.sh): the backend's
own WebhookService builds, signs and POSTs each event to the plugin handler
served by ``php -S`` (server.php), and the test reads back what the handler did
to the WooCommerce orders.
"""

import json
import os
import socket
import subprocess
import time
from pathlib import Path
from unittest.mock import patch

import pytest
from django.utils import timezone

from apis.v3.serializers.payment_links import PaymentLinkResponseSerializer
from apps.merchant_api.models import WebhookEvent
from apps.merchant_api.services.webhook_service import WebhookService
from apps.payment_links.models import PaymentLink
from tests.factories import PaymentLinkFactory, TenantFactory, WebhookEndpointFactory

SERVER = Path(__file__).with_name("server.php")
EVENTS = ["payment_link.paid", "payment_link.expired", "payment_link.cancelled"]


def _free_port():
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


@pytest.fixture
def shop(tmp_path):
    """A plugin webhook endpoint backed by a JSON order store."""
    tenant = TenantFactory()
    endpoint = WebhookEndpointFactory(tenant=tenant, events=EVENTS, mode="live")
    state_file = tmp_path / "orders.json"
    state_file.write_text(json.dumps({"orders": []}))
    port = _free_port()

    env = {**os.environ, "EZPAY_WEBHOOK_SECRET": endpoint.secret, "EZPAY_STATE_FILE": str(state_file)}
    server = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", str(SERVER)],
        env=env,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.PIPE,
    )
    deadline = time.time() + 10
    while time.time() < deadline:
        try:
            socket.create_connection(("127.0.0.1", port), timeout=0.2).close()
            break
        except OSError:
            time.sleep(0.05)
    else:
        server.kill()
        pytest.fail("php -S did not start")

    endpoint.url = f"http://127.0.0.1:{port}/"
    endpoint.save(update_fields=["url"])

    class Shop:
        def add_order(self, order_id, link):
            state = json.loads(state_file.read_text())
            state["orders"].append({"id": order_id, "link_id": str(link.uid), "status": "on-hold"})
            state_file.write_text(json.dumps(state))

        def order(self, order_id):
            return next(o for o in json.loads(state_file.read_text())["orders"] if o["id"] == order_id)

        def deliver(self, event_type, data):
            """Dispatch through the backend and deliver the resulting event."""
            WebhookService.dispatch_event(tenant=tenant, event_type=event_type, data=data, mode="live")
            event = WebhookEvent.objects.filter(webhook_endpoint=endpoint, event_type=event_type).latest("created_at")
            # The SSRF guard rightly refuses 127.0.0.1; everything after it is real.
            with patch.object(WebhookService, "is_safe_destination", return_value=True):
                WebhookService.deliver_webhook(str(event.uid))
            event.refresh_from_db()
            return event

    shop = Shop()
    shop.tenant = tenant
    yield shop
    server.terminate()
    server.wait(timeout=5)


@pytest.mark.django_db
def test_paid_delivery_marks_order_paid(shop):
    link = PaymentLinkFactory(
        tenant=shop.tenant,
        status=PaymentLink.StatusChoices.PAID,
        paid_at=timezone.now(),
        payment_method_type="card",
    )
    shop.add_order(101, link)

    event = shop.deliver("payment_link.paid", PaymentLinkResponseSerializer(link).data)

    assert event.last_response_status == 200, event.last_response_body
    assert event.status == WebhookEvent.DeliveryStatus.DELIVERED
    order = shop.order(101)
    assert order["status"] == "processing"
    assert order["transaction_id"] == str(link.uid)
    assert order["stock_reduced"] is True


@pytest.mark.django_db
def test_expired_delivery_cancels_order(shop):
    link = PaymentLinkFactory(tenant=shop.tenant, status=PaymentLink.StatusChoices.EXPIRED)
    shop.add_order(102, link)

    # Same shape as PaymentLinkService.expire_payment_links dispatches.
    event = shop.deliver("payment_link.expired", {"id": str(link.uid)})

    assert event.last_response_status == 200, event.last_response_body
    assert shop.order(102)["status"] == "cancelled"


@pytest.mark.django_db
def test_cancelled_delivery_cancels_order(shop):
    link = PaymentLinkFactory(tenant=shop.tenant, status=PaymentLink.StatusChoices.CANCELLED)
    shop.add_order(103, link)

    event = shop.deliver("payment_link.cancelled", PaymentLinkResponseSerializer(link).data)

    assert event.last_response_status == 200, event.last_response_body
    assert shop.order(103)["status"] == "cancelled"
