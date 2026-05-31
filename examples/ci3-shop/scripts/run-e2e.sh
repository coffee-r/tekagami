#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8088}"
EXAMPLE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
TRACE="${EXAMPLE_DIR}/var/tekagami.jsonl"
FLOW_MAP="${EXAMPLE_DIR}/var/flow-map.tsv"
TMP_DIR="$(mktemp -d)"
LAST_BODY="${TMP_DIR}/body.json"
LAST_STATUS="${TMP_DIR}/status.txt"

trap 'rm -rf "${TMP_DIR}"' EXIT

rm -f "${TRACE}" "${FLOW_MAP}" "${EXAMPLE_DIR}/var/report.md" "${EXAMPLE_DIR}/var/report.json" "${EXAMPLE_DIR}/var/analysis.md" "${EXAMPLE_DIR}/var/export.json"
printf "flow_id\tscenario\n" > "${FLOW_MAP}"

random_flow_id() {
  od -An -N4 -tx1 /dev/urandom | tr -d ' \n'
}

flow_id() {
  local scenario="$1"
  local existing
  existing="$(awk -F '\t' -v scenario="${scenario}" 'NR > 1 && $2 == scenario { print $1; exit }' "${FLOW_MAP}")"
  if [ -n "${existing}" ]; then
    printf "%s" "${existing}"
    return
  fi

  local id
  while :; do
    id="$(random_flow_id)"
    if ! awk -F '\t' -v id="${id}" 'NR > 1 && $1 == id { found = 1 } END { exit found ? 0 : 1 }' "${FLOW_MAP}"; then
      break
    fi
  done
  printf "%s\t%s\n" "${id}" "${scenario}" >> "${FLOW_MAP}"
  printf "%s" "${id}"
}

api() {
  local scenario="$1"
  local flow
  flow="$(flow_id "${scenario}")"
  local method="$2"
  local path="$3"
  local body
  if [ "$#" -ge 4 ]; then
    body="$4"
  else
    body="{}"
  fi
  local expected="${5:-200}"

  local status
  status="$(curl -sS -o "${LAST_BODY}" -w "%{http_code}" \
    -X "${method}" "${BASE_URL}${path}" \
    -H "Content-Type: application/json" \
    -H "X-Tekagami-Flow: ${flow}" \
    --data "${body}")"
  printf "%s" "${status}" > "${LAST_STATUS}"

  if [ "${status}" != "${expected}" ]; then
    echo "Unexpected status for ${scenario} (${flow}) ${method} ${path}: got ${status}, expected ${expected}" >&2
    cat "${LAST_BODY}" >&2
    exit 1
  fi
}

order_id() {
  php -r '$j=json_decode(file_get_contents($argv[1]), true); echo isset($j["order_id"]) ? $j["order_id"] : "";' "${LAST_BODY}"
}

echo "reset fixtures"
api reset POST /api/test/reset '{}' 200

echo "purchase limits"
api one-qty-cancelled-ok POST /api/cart/items '{"cart_id":"limit-ok","customer_id":1,"product_code":"LIMIT_QTY","quantity":1}' 201
api one-qty-cancelled-ok POST /api/checkout/quote '{"cart_id":"limit-ok","customer_id":1,"address_id":101,"payment_method":"prepaid","base_date":"2026-06-01","delivery_date":"2026-06-04","delivery_time":"morning"}' 200
api one-qty-cancelled-ok POST /api/orders '{"cart_id":"limit-ok","customer_id":1,"address_id":101,"payment_method":"prepaid","base_date":"2026-06-01","delivery_date":"2026-06-04","delivery_time":"morning"}' 201
LIMIT_ORDER="$(order_id)"
api one-qty-after-order-rejected POST /api/cart/items '{"cart_id":"limit-ng","customer_id":1,"product_code":"LIMIT_QTY","quantity":1}' 201
api one-qty-after-order-rejected POST /api/orders '{"cart_id":"limit-ng","customer_id":1,"address_id":101,"payment_method":"prepaid","base_date":"2026-06-01"}' 422
api one-qty-after-cancel-ok POST "/api/orders/${LIMIT_ORDER}/cancel" '{}' 200
api one-qty-after-cancel-ok POST /api/cart/items '{"cart_id":"limit-ok2","customer_id":1,"product_code":"LIMIT_QTY","quantity":1}' 201
api one-qty-after-cancel-ok POST /api/orders '{"cart_id":"limit-ok2","customer_id":1,"address_id":101,"payment_method":"prepaid","base_date":"2026-06-01"}' 201

api one-time-rejected POST /api/cart/items '{"cart_id":"once-ng","customer_id":1,"product_code":"LIMIT_ONCE","quantity":1}' 201
api one-time-rejected POST /api/orders '{"cart_id":"once-ng","customer_id":1,"address_id":101,"payment_method":"prepaid","base_date":"2026-06-01"}' 422
api one-qty-quantity-rejected POST /api/cart/items '{"cart_id":"limit-qty-ng","customer_id":1,"product_code":"LIMIT_QTY","quantity":2}' 201
api one-qty-quantity-rejected POST /api/orders '{"cart_id":"limit-qty-ng","customer_id":1,"address_id":101,"payment_method":"prepaid","base_date":"2026-06-01"}' 422

echo "product availability"
api product-not-found POST /api/cart/items '{"cart_id":"missing-product","customer_id":1,"product_code":"NO_SUCH_PRODUCT","quantity":1}' 404
api sale-period-ok POST /api/cart/items '{"cart_id":"period-ok","customer_id":1,"product_code":"PERIOD1","quantity":1,"base_date":"2026-06-01"}' 201
api sale-period-ended POST /api/cart/items '{"cart_id":"period-ended","customer_id":1,"product_code":"PERIOD1","quantity":1,"base_date":"2026-06-02"}' 422

echo "shipping code swap and revert"
api yumail-single POST /api/cart/items '{"cart_id":"mail1","customer_id":1,"product_code":"MAIL1","quantity":1}' 201
api yumail-single GET '/api/cart?cart_id=mail1' '{}' 200
api yumail-revert POST /api/cart/items '{"cart_id":"mail1","customer_id":1,"product_code":"NORMAL1","quantity":1}' 201
api yumail-revert GET '/api/cart?cart_id=mail1' '{}' 200

echo "payment and delivery restrictions"
api cod-yumail-rejected POST /api/cart/items '{"cart_id":"pay-yumail","customer_id":1,"product_code":"MAIL1","quantity":1}' 201
api cod-yumail-rejected POST /api/checkout/quote '{"cart_id":"pay-yumail","customer_id":1,"address_id":101,"payment_method":"cod","base_date":"2026-06-01"}' 422
api cod-other-address-rejected POST /api/cart/items '{"cart_id":"pay-other","customer_id":1,"product_code":"NORMAL1","quantity":1}' 201
api cod-other-address-rejected POST /api/checkout/quote '{"cart_id":"pay-other","customer_id":1,"address_id":102,"payment_method":"cod","base_date":"2026-06-01"}' 422
api deferred-credit-limit-rejected POST /api/cart/items '{"cart_id":"pay-deferred","customer_id":2,"product_code":"SET100","quantity":3}' 201
api deferred-credit-limit-rejected POST /api/checkout/quote '{"cart_id":"pay-deferred","customer_id":2,"address_id":105,"payment_method":"deferred","base_date":"2026-06-01"}' 422
api prepaid-only-ok POST /api/checkout/quote '{"cart_id":"pay-deferred","customer_id":2,"address_id":105,"payment_method":"prepaid","base_date":"2026-06-01"}' 200

api yumail-date-rejected POST /api/checkout/quote '{"cart_id":"pay-yumail","customer_id":1,"address_id":101,"payment_method":"prepaid","base_date":"2026-06-01","delivery_date":"2026-06-04"}' 422
api delayed-area-date-rejected POST /api/cart/items '{"cart_id":"delay","customer_id":1,"product_code":"NORMAL1","quantity":1}' 201
api delayed-area-date-rejected POST /api/checkout/quote '{"cart_id":"delay","customer_id":1,"address_id":103,"payment_method":"prepaid","base_date":"2026-06-01","delivery_date":"2026-06-04"}' 422
api remote-island-date-rejected POST /api/cart/items '{"cart_id":"remote","customer_id":1,"product_code":"NORMAL1","quantity":1}' 201
api remote-island-date-rejected POST /api/checkout/quote '{"cart_id":"remote","customer_id":1,"address_id":104,"payment_method":"prepaid","base_date":"2026-06-01","delivery_date":"2026-06-04"}' 422
api working-day-window-rejected POST /api/cart/items '{"cart_id":"window","customer_id":1,"product_code":"NORMAL1","quantity":1}' 201
api working-day-window-rejected POST /api/checkout/quote '{"cart_id":"window","customer_id":1,"address_id":101,"payment_method":"prepaid","base_date":"2026-06-05","delivery_date":"2026-06-08"}' 422
api delivery-date-too-late-rejected POST /api/cart/items '{"cart_id":"late","customer_id":1,"product_code":"NORMAL1","quantity":1}' 201
api delivery-date-too-late-rejected POST /api/checkout/quote '{"cart_id":"late","customer_id":1,"address_id":101,"payment_method":"prepaid","base_date":"2026-06-01","delivery_date":"2026-06-20"}' 422
api delivery-time-yumail-rejected POST /api/checkout/quote '{"cart_id":"pay-yumail","customer_id":1,"address_id":101,"payment_method":"prepaid","base_date":"2026-06-01","delivery_time":"morning"}' 422

echo "shipping fee and gifts"
api first-free-shipping POST /api/cart/items '{"cart_id":"first-free","customer_id":1,"product_code":"NORMAL1","quantity":2}' 201
api first-free-shipping POST /api/checkout/quote '{"cart_id":"first-free","customer_id":1,"address_id":101,"payment_method":"prepaid","base_date":"2026-06-01"}' 200
api repeat-shipping-fee POST /api/cart/items '{"cart_id":"repeat-fee","customer_id":2,"product_code":"NORMAL1","quantity":2}' 201
api repeat-shipping-fee POST /api/checkout/quote '{"cart_id":"repeat-fee","customer_id":2,"address_id":105,"payment_method":"prepaid","base_date":"2026-06-01"}' 200
api repeat-free-shipping POST /api/cart/items '{"cart_id":"repeat-free","customer_id":2,"product_code":"SET100","quantity":2}' 201
api repeat-free-shipping POST /api/checkout/quote '{"cart_id":"repeat-free","customer_id":2,"address_id":105,"payment_method":"prepaid","base_date":"2026-06-01"}' 200
api gift-attached POST /api/cart/items '{"cart_id":"gift","customer_id":1,"product_code":"GIFT_TRIGGER","quantity":1}' 201
api gift-attached GET '/api/cart?cart_id=gift' '{}' 200

echo "points, variety, reserved, set, missing email"
api point-guest-rejected POST /api/cart/items '{"cart_id":"point-guest","product_code":"POINT1000","quantity":1}' 422
api point-ok POST /api/cart/items '{"cart_id":"point-ok","customer_id":1,"product_code":"POINT1000","quantity":2}' 201
api point-ok POST /api/orders '{"cart_id":"point-ok","customer_id":1,"address_id":101,"payment_method":"prepaid","base_date":"2026-06-01"}' 201
api point-insufficient POST /api/cart/items '{"cart_id":"point-ng","customer_id":2,"product_code":"POINT1000","quantity":2}' 422

api variety-ok POST /api/cart/items '{"cart_id":"var-ok","customer_id":1,"product_code":"VAR_A","quantity":1}' 201
api variety-ok POST /api/cart/items '{"cart_id":"var-ok","customer_id":1,"product_code":"VAR_B","quantity":1}' 201
api variety-ok POST /api/cart/items '{"cart_id":"var-ok","customer_id":1,"product_code":"VAR_C","quantity":1}' 201
api variety-ok POST /api/orders '{"cart_id":"var-ok","customer_id":1,"address_id":101,"payment_method":"prepaid","base_date":"2026-06-01"}' 201
api variety-mixed-rejected POST /api/cart/items '{"cart_id":"var-ng","customer_id":1,"product_code":"VAR_A","quantity":1}' 201
api variety-mixed-rejected POST /api/cart/items '{"cart_id":"var-ng","customer_id":1,"product_code":"NORMAL1","quantity":1}' 201
api variety-mixed-rejected POST /api/orders '{"cart_id":"var-ng","customer_id":1,"address_id":101,"payment_method":"prepaid","base_date":"2026-06-01"}' 422
api variety-quantity-rejected POST /api/cart/items '{"cart_id":"var-qty-ng","customer_id":1,"product_code":"VAR_A","quantity":1}' 201
api variety-quantity-rejected POST /api/cart/items '{"cart_id":"var-qty-ng","customer_id":1,"product_code":"VAR_B","quantity":1}' 201
api variety-quantity-rejected POST /api/orders '{"cart_id":"var-qty-ng","customer_id":1,"address_id":101,"payment_method":"prepaid","base_date":"2026-06-01"}' 422

api reserved-cart POST /api/cart/items '{"cart_id":"reserve","customer_id":1,"product_code":"YRESERVE1","quantity":1}' 201
api reserved-cart GET '/api/cart?cart_id=reserve' '{}' 200
api set-n-plus-one POST /api/cart/items '{"cart_id":"sets","customer_id":1,"product_code":"SET100","quantity":1}' 201
api set-n-plus-one POST /api/cart/items '{"cart_id":"sets","customer_id":1,"product_code":"SET100","quantity":1}' 201
api set-n-plus-one GET '/api/cart?cart_id=sets' '{}' 200

api missing-email-rejected POST /api/cart/items '{"cart_id":"no-email","customer_id":3,"product_code":"NORMAL1","quantity":1}' 201
api missing-email-rejected POST /api/orders '{"cart_id":"no-email","customer_id":3,"address_id":101,"payment_method":"prepaid","base_date":"2026-06-01"}' 422

echo "credit callback"
api credit-order POST /api/cart/items '{"cart_id":"credit","customer_id":1,"product_code":"NORMAL1","quantity":1}' 201
api credit-order POST /api/orders '{"cart_id":"credit","customer_id":1,"address_id":101,"payment_method":"credit_card","base_date":"2026-06-01"}' 201
CREDIT_ORDER="$(order_id)"
api credit-callback POST /api/payments/credit/callback "{\"order_id\":${CREDIT_ORDER},\"status\":\"success\"}" 200

echo "E2E complete. Trace: ${TRACE}"
echo "Flow map: ${FLOW_MAP}"
