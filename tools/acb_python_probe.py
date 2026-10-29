#!/usr/bin/env python3
"""Read-only ACB protocol probe.

Receives credentials as one JSON object through stdin. It intentionally prints
only safe status/stage/error-code metadata and never prints credentials, tokens,
account numbers, response bodies, or transaction data.
"""

from datetime import datetime, timedelta, timezone
import json
import sys
import uuid

import requests


BASE_URL = "https://apiapp.acb.com.vn"
CLIENT_ID = "iuSuHYVufIUuNIREV0FB9EoLn9kHsDbm"
API_KEY = "CQk6S5usauGmMgMYLGqCuDtgtqIM8FI1"
CONVERSATION_ID = "f43472f1-6d88-4228-91b9-4618a079342a"


def safe_error_code(response):
    if response is None:
        return ""
    try:
        payload = response.json()
    except ValueError:
        return ""
    if not isinstance(payload, dict):
        return ""
    for field in ("code", "errorCode", "error_code"):
        value = payload.get(field)
        if isinstance(value, (str, int)):
            safe = str(value).strip()
            if safe and len(safe) <= 48 and safe.replace("_", "").replace("-", "").isalnum():
                return safe
    return ""


def fail(stage, error):
    response = error.response if isinstance(error, requests.exceptions.HTTPError) else None
    status = response.status_code if response is not None else 0
    code = safe_error_code(response)
    print("PROBE_STATUS=failed")
    print(f"PROBE_STAGE={stage}")
    print(f"HTTP_STATUS={status}")
    print(f"ERROR_CODE={code}")
    return 1


def read_config():
    try:
        payload = json.load(sys.stdin)
    except ValueError as error:
        raise RuntimeError("invalid_stdin_json") from error
    if not isinstance(payload, dict):
        raise RuntimeError("invalid_stdin_object")
    return payload


def main():
    try:
        config = read_config()
        username = config.get("username")
        password = config.get("password")
        account_number = config.get("account_number")
        timeout = config.get("timeout", 20)
        if not all(isinstance(value, str) and value for value in (username, password, account_number)):
            raise RuntimeError("missing_configuration")
        if not isinstance(timeout, int) or timeout < 1 or timeout > 120:
            raise RuntimeError("invalid_timeout")
    except RuntimeError as error:
        print("PROBE_STATUS=failed")
        print("PROBE_STAGE=configuration")
        print("HTTP_STATUS=0")
        print(f"ERROR_CODE={error}")
        return 1

    session = requests.Session()
    session.headers.update(
        {
            "Accept": "application/json, text/plain, */*",
            "Content-Type": "application/json",
            "apikey": API_KEY,
            "User-Agent": "ACB-MBA/8 CFNetwork/1335.0.3 Darwin/21.6.0",
            "x-app-version": "3.26.0",
            "x-conversation-id": CONVERSATION_ID,
        }
    )

    device_id = str(uuid.uuid4()).upper()
    login_payload = {
        "username": username,
        "password": password,
        "deviceId": device_id,
        "clientId": CLIENT_ID,
    }
    try:
        login_response = session.post(
            f"{BASE_URL}/mb/v2/auth/tokens",
            data=json.dumps(login_payload),
            headers={"x-request-id": str(uuid.uuid4())},
            timeout=timeout,
        )
        login_response.raise_for_status()
        login_data = login_response.json()
        access_token = login_data.get("accessToken") if isinstance(login_data, dict) else None
        if not isinstance(access_token, str) or not access_token:
            raise RuntimeError("missing_access_token")
    except requests.exceptions.RequestException as error:
        return fail("auth_login", error)
    except (ValueError, RuntimeError):
        print("PROBE_STATUS=failed")
        print("PROBE_STAGE=auth_login_response")
        print("HTTP_STATUS=200")
        print("ERROR_CODE=invalid_response")
        return 1

    vn_tz = timezone(timedelta(hours=7))
    now_vn = datetime.now(vn_tz)
    start_dt = (now_vn - timedelta(days=1)).replace(hour=0, minute=0, second=0, microsecond=0)
    end_dt = (now_vn + timedelta(days=5)).replace(hour=23, minute=59, second=59, microsecond=999000)
    history_url = (
        f"{BASE_URL}/mb/legacy/ss/cs/bankservice/saving/{account_number}/tx-history"
        f"?account={account_number}&transactionType=ALL"
        f"&from={int(start_dt.timestamp() * 1000)}&to={int(end_dt.timestamp() * 1000)}"
        "&min=0&max=9007199254740991&page=1&size=1"
    )
    headers = {
        "x-request-id": str(uuid.uuid4()),
        "Authorization": f"Bearer {access_token}",
    }
    try:
        history_response = session.get(history_url, headers=headers, timeout=timeout)
        if history_response.status_code != 200:
            fallback_url = (
                f"{BASE_URL}/mb/legacy/ss/cs/bankservice/transfers/account-payment"
                f"?account={account_number}"
            )
            history_response = session.get(fallback_url, headers=headers, timeout=timeout)
        history_response.raise_for_status()
        history_data = history_response.json()
        if not isinstance(history_data, dict):
            raise RuntimeError("invalid_history_response")
    except requests.exceptions.RequestException as error:
        return fail("history", error)
    except (ValueError, RuntimeError):
        print("PROBE_STATUS=failed")
        print("PROBE_STAGE=history_response")
        print("HTTP_STATUS=200")
        print("ERROR_CODE=invalid_response")
        return 1

    raw_data = history_data.get("data")
    if isinstance(raw_data, dict):
        transactions = raw_data.get("transactions", [])
    elif isinstance(raw_data, list):
        transactions = raw_data
    else:
        transactions = []

    print("PROBE_STATUS=success")
    print("PROBE_STAGE=history")
    print("HTTP_STATUS=200")
    print(f"TRANSACTION_LIST_VALID={'yes' if isinstance(transactions, list) else 'no'}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
