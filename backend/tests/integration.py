#!/usr/bin/env python3
"""Exercise the real HTTP/PHP/MySQL API using Python's standard library.

Use only against an explicitly configured development database containing seed.sql.
Temporary resources are deleted; existing seed rows and passwords are not changed.
"""

from __future__ import annotations

import argparse
import base64
import concurrent.futures
import datetime as dt
import hashlib
import hmac
import json
import random
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from pathlib import Path


class RejectRedirects(urllib.request.HTTPRedirectHandler):
    """A REST request must keep its original method and exact endpoint URL."""

    def redirect_request(self, request, response, code, message, headers, new_url):
        return None


class Api:
    def __init__(self, base: str):
        self.base = base.rstrip("/")
        self.checks = 0
        self.opener = urllib.request.build_opener(RejectRedirects())

    def call(self, method, path, token=None, body=None, query=None, headers=None, raw=None, allow_non_json=False):
        url = path if path.startswith(("http://", "https://")) else self.base + path
        if query:
            url += "?" + urllib.parse.urlencode(query)
        request_headers = {"Accept": "application/json", **(headers or {})}
        if token:
            request_headers["Authorization"] = "Bearer " + token
        data = raw
        if body is not None:
            data = json.dumps(body, ensure_ascii=False).encode()
        if data is not None:
            request_headers.setdefault("Content-Type", "application/json")
        request = urllib.request.Request(url, data=data, method=method, headers=request_headers)
        try:
            response = self.opener.open(request, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            payload = response.read()
            try:
                # Capture the original redirect status; never silently replay a POST as GET.
                decoded = None if 300 <= response.status < 400 else json.loads(payload) if payload else None
            except (ValueError, UnicodeDecodeError):
                if not allow_non_json:
                    raise AssertionError(f"{method} {path}: HTTP {response.status} returned non-JSON") from None
                decoded = None
            return response.status, response.headers, decoded

    def check(self, condition, message):
        self.checks += 1
        if not condition:
            raise AssertionError(message)

    def expect(self, result, statuses, label):
        if isinstance(statuses, int):
            statuses = (statuses,)
        status, headers, payload = result
        code = payload.get("error", {}).get("code") if isinstance(payload, dict) else None
        self.check(status in statuses, f"{label}: expected HTTP {statuses}, received {status} ({code})")
        if status != 204:
            self.check("application/json" in headers.get("Content-Type", ""), f"{label}: JSON Content-Type")
            self.check(isinstance(payload, dict), f"{label}: object response")
        if status >= 400:
            self.check(isinstance(payload.get("error"), dict), f"{label}: error envelope")
            self.check(bool(payload["error"].get("code")), f"{label}: stable error code")
            self.check(isinstance(payload["error"].get("message"), str), f"{label}: error message")
            text = json.dumps(payload)
            self.check(not any(value in text for value in ("PDOException", "Stack trace", "SQLSTATE", "/workspace/", "password_hash")), f"{label}: no internal diagnostics")
            return payload["error"]
        if status == 204:
            self.check(payload is None, f"{label}: empty 204 body")
            return None
        self.check("data" in payload, f"{label}: data envelope")
        return payload["data"]

    def collection(self, path, token, query=None):
        data = self.expect(self.call("GET", path, token, query=query), 200, path + " collection")
        self.check(isinstance(data.get("items"), list), path + ": items array")
        for key in ("page", "limit", "total", "pages"):
            self.check(isinstance(data.get(key), int) and data[key] >= 0, f"{path}: integer {key}")
        self.check(data["page"] >= 1 and 1 <= data["limit"] <= 100, path + ": bounded pagination")
        expected_pages = (data["total"] + data["limit"] - 1) // data["limit"]
        self.check(data["pages"] == expected_pages, path + ": accurate page count")
        return data


def signed_token(claims, secret, algorithm="HS256"):
    def encode(value):
        return base64.urlsafe_b64encode(json.dumps(value, separators=(",", ":")).encode()).rstrip(b"=")
    parts = encode({"typ": "JWT", "alg": algorithm}) + b"." + encode(claims)
    signature = hmac.new(secret, parts, hashlib.sha256).digest()
    return (parts + b"." + base64.urlsafe_b64encode(signature).rstrip(b"=")).decode()


def run(args):
    api = Api(args.base_url)
    created = {name: [] for name in ("clubs", "schedules", "tournaments", "bookings")}
    tokens, users = {}, {}
    prefix = "API test " + uuid.uuid4().hex[:12]
    day = dt.date.today() + dt.timedelta(days=3650 + random.randrange(10000))
    date = day.isoformat()
    admin = None

    def create(resource, body, token=None, label=None):
        data = api.expect(api.call("POST", "/" + resource, token or admin, body), 201, label or "create " + resource)
        api.check(isinstance(data.get("id"), int) and data["id"] > 0, resource + ": positive identifier")
        created[resource].append(data["id"])
        return data

    def delete(resource, identifier):
        data = api.expect(api.call("DELETE", f"/{resource}/{identifier}", admin), (200, 204), "delete " + resource)
        if data is not None:
            api.check(data.get("deleted") is True, resource + ": deletion confirmation")
        created[resource].remove(identifier)
        api.expect(api.call("GET", f"/{resource}/{identifier}", admin), 404, "deleted resource missing")

    try:
        health_result = api.call("GET", "/system/health")
        health = api.expect(health_result, 200, "MySQL health")
        api.check(health.get("status") == "operational", "health: operational")
        api.check(health.get("database") == "connected", "health: actual database connection")
        api.check(isinstance(health.get("latency_ms"), (int, float)) and health["latency_ms"] >= 0, "health: measured latency")
        for header, value in (("X-Content-Type-Options", "nosniff"), ("X-Frame-Options", "SAMEORIGIN"), ("Referrer-Policy", "strict-origin-when-cross-origin")):
            api.check(health_result[1].get(header) == value, "security header: " + header)

        for role, email, password in (
            ("student", "student@nsbm.ac.lk", "password123"),
            ("club_manager", "clubmanager@nsbm.ac.lk", "password123"),
            ("sports_admin", "admin@nsbm.ac.lk", "admin123"),
            ("super_admin", "superadmin@nsbm.ac.lk", "admin123"),
        ):
            data = api.expect(api.call("POST", "/auth/login", body={"email": email, "password": password}), 200, role + " login")
            api.check(data.get("token_type") == "Bearer", "bearer token type")
            api.check(isinstance(data.get("access_token"), str) and len(data["access_token"].split(".")) == 3, "signed JWT structure")
            api.check(data["user"].get("role") == role, role + " current role")
            api.check("password_hash" not in data["user"] and "password" not in data["user"], "safe public user")
            api.check(isinstance(data.get("permissions"), list), "login permissions")
            tokens[role], users[role] = data["access_token"], data["user"]
            session = api.expect(api.call("GET", "/auth/session", tokens[role]), 200, role + " session")
            api.check(session["user"]["id"] == data["user"]["id"], "session user identity")
            api.check(session["permissions"] == data["permissions"], "session permissions")
        admin, student, manager = tokens["sports_admin"], tokens["student"], tokens["club_manager"]

        api.expect(api.call("POST", "/auth/login", body={"email": "student@nsbm.ac.lk", "password": "password123\u0000unexpected-suffix"}), 422, "password containing NUL rejected")
        bad_password = api.expect(api.call("POST", "/auth/login", body={"email": "student@nsbm.ac.lk", "password": "wrong-password"}), 401, "invalid password")
        bad_email = api.expect(api.call("POST", "/auth/login", body={"email": prefix.replace(" ", "") + "@example.test", "password": "wrong-password"}), 401, "unknown email")
        api.check(bad_password["message"] == bad_email["message"], "login does not reveal email existence")
        api.expect(api.call("GET", "/auth/session"), 401, "unauthenticated session")
        api.expect(api.call("GET", "/clubs"), 401, "unauthenticated collection")
        api.expect(api.call("GET", "/clubs", "not-a-jwt"), 401, "malformed token")
        parts = student.split(".")
        tampered = parts[0] + "." + base64.urlsafe_b64encode(b'{"sub":1,"role":"super_admin"}').rstrip(b"=").decode() + "." + parts[2]
        api.expect(api.call("GET", "/auth/session", tampered), 401, "tampered token")

        if args.jwt_secret_file:
            secret = Path(args.jwt_secret_file).read_bytes().strip()
            claims = json.loads(base64.urlsafe_b64decode(parts[1] + "=" * (-len(parts[1]) % 4)))
            expired = {**claims, "iat": int(time.time()) - 7200, "nbf": int(time.time()) - 7200, "exp": int(time.time()) - 3600}
            api.expect(api.call("GET", "/auth/session", signed_token(expired, secret)), 401, "expired signed JWT")
            future = {**claims, "iat": int(time.time()) + 3600, "nbf": int(time.time()) + 3600, "exp": int(time.time()) + 7200}
            api.expect(api.call("GET", "/auth/session", signed_token(future, secret)), 401, "future signed JWT")
            api.expect(api.call("GET", "/auth/session", signed_token(claims, secret, "none")), 401, "algorithm substitution")
            forged_role = signed_token({**claims, "role": "super_admin"}, secret)
            forged_session = api.expect(api.call("GET", "/auth/session", forged_role), 200, "database role overrides JWT role")
            api.check(forged_session["user"]["role"] == "student", "JWT role does not grant privileges")
            api.expect(api.call("POST", "/clubs", forged_role, {}), 403, "signed role escalation denied")

        preflight = api.call("OPTIONS", "/clubs", headers={"Origin": args.origin, "Access-Control-Request-Method": "POST", "Access-Control-Request-Headers": "Authorization, Content-Type"})
        api.check(preflight[0] in (200, 204), "CORS preflight accepted")
        api.check(preflight[1].get("Access-Control-Allow-Origin") == args.origin, "CORS exact allowed origin")
        api.check("Authorization" in preflight[1].get("Access-Control-Allow-Headers", ""), "CORS bearer header allowed")
        denied_cors = api.call("GET", "/system/health", headers={"Origin": "https://untrusted.example.test"})
        api.check(denied_cors[1].get("Access-Control-Allow-Origin") not in ("*", "https://untrusted.example.test"), "untrusted CORS origin not allowed")

        backend_root = api.base.removesuffix("/api/v1")
        for path in ("/config/config.php", "/database/schema.sql", "/.env", "/core/auth.php"):
            private_response = api.call("GET", backend_root + path, allow_non_json=True)
            api.check(private_response[0] in (403, 404), "private path protected " + path)
        api.expect(api.call("PATCH", "/clubs", admin, {}), 405, "unsupported method")
        api.expect(api.call("GET", "/auth/login"), 405, "login wrong method")
        api.expect(api.call("POST", "/clubs", admin, raw=b'{"name":'), 400, "malformed JSON")
        api.expect(api.call("GET", "/clubs/999999999", admin), 404, "missing resource")

        metrics_before = api.expect(api.call("GET", "/dashboard/metrics", student), 200, "student dashboard")
        for key in ("active_members", "clubs", "bookings", "upcoming_events_count"):
            api.check(isinstance(metrics_before.get(key), int) and metrics_before[key] >= 0, "database metric " + key)
        for key in ("weekly_activity", "recent_activity", "upcoming_events"):
            api.check(isinstance(metrics_before.get(key), list), "dashboard list " + key)

        club_body = {"name": prefix + " Cricket", "sport": "Cricket", "president": "චනුප Test", "member_count": 17, "status": "active", "description": "Integration test club"}
        api.expect(api.call("POST", "/clubs", student, club_body), 403, "student cannot create club")
        for body in ({**club_body, "name": ""}, {**club_body, "name": "x" * 121}, {**club_body, "member_count": -1}, {**club_body, "status": "made-up"}, {**club_body, "status": None}, {**club_body, "president": ""}):
            err = api.expect(api.call("POST", "/clubs", admin, body), 422, "club field validation")
            api.check(isinstance(err.get("details"), dict) and bool(err["details"]), "field-level validation errors")
        club = create("clubs", club_body)
        api.check(club["president"] == club_body["president"], "UTF-8 name round-trip")
        persisted = api.expect(api.call("GET", f'/clubs/{club["id"]}', student), 200, "club persists in MySQL")
        api.check(persisted["name"] == club_body["name"], "resource read persisted fields")
        metrics_after = api.expect(api.call("GET", "/dashboard/metrics", admin), 200, "dashboard after create")
        api.check(metrics_after["clubs"] == metrics_before["clubs"] + 1, "dashboard club metric reflects insert")
        found = api.collection("/clubs", student, {"search": prefix.lower(), "sport": "Cricket", "status": "active", "sort": "name", "order": "asc", "page": 1, "limit": 1})
        api.check(found["total"] == 1 and found["items"][0]["id"] == club["id"], "case-insensitive search and compound filters")
        second = api.collection("/clubs", student, {"search": prefix, "page": 2, "limit": 1})
        api.check(not second["items"] and second["total"] == 1, "out-of-range page preserves total")
        injection = api.collection("/clubs", student, {"search": "' OR 1=1 -- "})
        api.check(injection["total"] == 0, "search input remains SQL data")
        api.expect(api.call("GET", "/clubs", student, query={"sort": "name;DROP TABLE clubs"}), 422, "sort SQL injection rejected")
        api.expect(api.call("GET", "/clubs", student, query={"order": "nonsense"}), 422, "sort direction rejected")
        api.expect(api.call("GET", "/clubs", student, query={"limit[]": 10}), 422, "array query parameters rejected")
        max_page = api.collection("/clubs", student, {"limit": 999999999})
        api.check(max_page["limit"] == 100, "maximum collection size is 100")
        bounded = api.call("GET", "/clubs", student, query={"limit": 999999999, "page": 0})
        bounded_data = api.expect(bounded, (200, 422), "pagination bounds")
        if bounded[0] == 200:
            api.check(bounded_data["limit"] <= 100 and bounded_data["page"] >= 1, "pagination safely clamped")
        updated_body = {**club_body, "name": prefix + " Updated", "member_count": 20, "status": "inactive"}
        updated = api.expect(api.call("PUT", f'/clubs/{club["id"]}', admin, updated_body), 200, "club update")
        api.check(updated["name"] == updated_body["name"] and updated["member_count"] == 20, "club update persisted")
        api.expect(api.call("DELETE", f'/clubs/{club["id"]}', student), 403, "student cannot delete club")
        manager_club = create("clubs", {**club_body, "name": prefix + " Managed"}, manager, "manager creates assigned club")
        api.expect(api.call("PUT", f'/clubs/{manager_club["id"]}', manager, {**club_body, "name": prefix + " Manager edited"}), 200, "manager updates own club")
        api.expect(api.call("PUT", f'/clubs/{club["id"]}', manager, updated_body), 403, "manager cannot update another club")
        api.expect(api.call("DELETE", f'/clubs/{manager_club["id"]}', manager), 403, "manager lacks delete permission")

        schedule_body = {"sport": "Cricket", "event_name": prefix + " Training", "event_date": date, "start_time": "09:00", "end_time": "10:00", "location": "Main Ground", "teams": "Computing vs Business", "status": "scheduled", "description": "API test training"}
        api.expect(api.call("POST", "/schedules", student, schedule_body), 403, "student cannot create schedule")
        for body in ({**schedule_body, "end_time": "08:30"}, {**schedule_body, "start_time": "24:01"}, {**schedule_body, "event_date": "2026-02-30"}, {**schedule_body, "status": "unknown"}):
            api.expect(api.call("POST", "/schedules", admin, body), 422, "schedule validation")
        schedule = create("schedules", schedule_body, manager)
        api.expect(api.call("GET", f'/schedules/{schedule["id"]}', student), 200, "schedule resource")
        filtered = api.collection("/schedules", student, {"search": prefix, "sport": "Cricket", "location": "Main Ground", "status": "scheduled", "date": date, "sort": "event_date", "order": "asc"})
        api.check(filtered["total"] == 1, "schedule filters")
        api.expect(api.call("PUT", f'/schedules/{schedule["id"]}', manager, {**schedule_body, "event_name": prefix + " Match", "status": "live"}), 200, "manager schedule update")
        api.expect(api.call("DELETE", f'/schedules/{schedule["id"]}', manager), 403, "manager schedule delete forbidden")

        tournament_body = {"name": prefix + " Cup", "sport": "Cricket", "start_date": date, "end_date": (day + dt.timedelta(days=2)).isoformat(), "format": "Knockout", "max_teams": 8, "registered_teams": 2, "status": "registration_open", "description": "API test tournament"}
        for token in (student, manager):
            api.expect(api.call("POST", "/tournaments", token, tournament_body), 403, "non-admin tournament create forbidden")
        for body in ({**tournament_body, "max_teams": 0}, {**tournament_body, "registered_teams": 9}, {**tournament_body, "registered_teams": -1}, {**tournament_body, "end_date": (day - dt.timedelta(days=1)).isoformat()}):
            api.expect(api.call("POST", "/tournaments", admin, body), 422, "tournament validation")
        tournament = create("tournaments", tournament_body)
        api.expect(api.call("GET", f'/tournaments/{tournament["id"]}', student), 200, "tournament resource")
        filtered = api.collection("/tournaments", student, {"search": prefix, "sport": "Cricket", "status": "registration_open", "date": date, "sort": "start_date", "order": "desc"})
        api.check(filtered["total"] == 1, "tournament filters")
        api.expect(api.call("PUT", f'/tournaments/{tournament["id"]}', admin, {**tournament_body, "status": "upcoming", "registered_teams": 3}), 200, "tournament update")

        booking_body = {"facility": "Main Ground", "sport": "Cricket", "booking_date": date, "start_time": "12:00", "end_time": "13:00", "notes": prefix}
        for body in ({**booking_body, "facility": "Uncontrolled Facility"}, {**booking_body, "end_time": "11:59"}, {**booking_body, "start_time": "12:99"}, {**booking_body, "booking_date": "2026-13-01"}):
            api.expect(api.call("POST", "/bookings", student, body), 422, "booking validation")
        booking = create("bookings", booking_body, student)
        api.check(booking["booked_by"] == users["student"]["id"], "student booking belongs to authenticated user")
        api.check(booking["status"] == "pending", "student booking starts pending")
        api.expect(api.call("POST", "/bookings", student, {**booking_body, "booked_by": [], "start_time": "19:00", "end_time": "20:00"}), (403, 422), "array owner cannot bypass identity validation")
        api.expect(api.call("GET", f'/bookings/{booking["id"]}', student), 200, "own booking read")
        conflict = api.expect(api.call("POST", "/bookings", student, {**booking_body, "start_time": "12:30", "end_time": "13:30"}), 409, "booking overlap rejected")
        api.check(conflict["code"] == "BOOKING_CONFLICT", "stable booking conflict code")
        api.check(conflict.get("details", {}).get("facility") == booking_body["facility"], "conflict facility detail")
        adjacent = create("bookings", {**booking_body, "start_time": "13:00", "end_time": "14:00"}, student, "adjacent booking allowed")
        api.expect(api.call("PUT", f'/bookings/{booking["id"]}', admin, {**booking_body, "booked_by": users["student"]["id"], "status": "confirmed"}), 200, "update excludes own booking ID")
        api.expect(api.call("PUT", f'/bookings/{adjacent["id"]}', admin, {**booking_body, "start_time": "12:45", "end_time": "14:00", "booked_by": users["student"]["id"], "status": "confirmed"}), 409, "booking update overlap rejected")
        api.expect(api.call("PUT", f'/bookings/{booking["id"]}', student, {"status": "confirmed"}), (403, 422), "student cannot confirm booking")
        api.expect(api.call("DELETE", f'/bookings/{booking["id"]}', student), 403, "student cannot delete booking")
        own = api.collection("/bookings", student, {"search": prefix, "facility": "Main Ground", "date": date, "sort": "booking_date"})
        api.check(all(item["booked_by"] == users["student"]["id"] for item in own["items"]), "student booking list ownership")
        cancelled = api.expect(api.call("PUT", f'/bookings/{booking["id"]}', student, {"status": "cancelled"}), 200, "student cancels own confirmed booking")
        api.check(cancelled["status"] == "cancelled", "cancellation persisted")
        create("bookings", {**booking_body, "booked_by": users["student"]["id"], "status": "confirmed"}, admin, "cancelled interval available again")
        other_booking = create("bookings", {**booking_body, "start_time": "15:00", "end_time": "16:00", "booked_by": users["club_manager"]["id"], "status": "confirmed"}, admin, "admin creates on behalf of manager")
        api.check(other_booking["booked_by"] == users["club_manager"]["id"], "admin booking owner respected")
        api.expect(api.call("GET", f'/bookings/{other_booking["id"]}', student), (403, 404), "student cannot read other user booking")
        api.expect(api.call("PUT", f'/bookings/{other_booking["id"]}', student, {"status": "cancelled"}), (403, 404), "student cannot cancel other user booking")
        spoof_body = {**booking_body, "start_time": "17:00", "end_time": "18:00", "booked_by": users["sports_admin"]["id"], "status": "confirmed"}
        spoof_result = api.call("POST", "/bookings", student, spoof_body)
        spoof = api.expect(spoof_result, (201, 403, 422), "student cannot impersonate booking owner")
        if spoof_result[0] == 201:
            created["bookings"].append(spoof["id"])
            api.check(spoof["booked_by"] == users["student"]["id"] and spoof["status"] == "pending", "spoof fields forced to safe defaults")

        # Separate HTTP connections begin at a barrier; exactly one may reserve the interval.
        barrier = threading.Barrier(2)
        race_body = {**booking_body, "facility": "Tennis Court", "start_time": "10:00", "end_time": "11:00"}
        def compete():
            barrier.wait(timeout=5)
            return api.call("POST", "/bookings", student, race_body)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            race_results = list(pool.map(lambda _: compete(), range(2)))
        for result in race_results:
            if result[0] == 201:
                created["bookings"].append(result[2]["data"]["id"])
        api.check(sorted(result[0] for result in race_results) == [201, 409], "concurrent overlapping creates: one success, one conflict")

        for resource in ("schedules", "tournaments", "bookings"):
            api.expect(api.call("GET", "/" + resource, student, query={"sort": "id desc;DROP TABLE users"}), 422, resource + " sorting whitelist")
        api.expect(api.call("GET", "/dashboard/metrics", tokens["super_admin"]), 200, "super admin wildcard permission")
        logout = api.expect(api.call("POST", "/auth/logout", student, {}), 200, "logout")
        api.check(logout.get("token_revoked") is False, "stateless logout is honest about revocation")
        api.expect(api.call("GET", "/auth/session"), 401, "protected access after client discards token")

        # Delete through the API and verify 404, rather than only relying on final cleanup.
        delete("clubs", club["id"])
        delete("schedules", schedule["id"])
        delete("tournaments", tournament["id"])
        delete("bookings", adjacent["id"])

        if args.rate_limit:
            statuses = []
            for _ in range(7):
                response = api.call("POST", "/auth/login", body={"email": prefix.replace(" ", "") + "@example.test", "password": "incorrect"})
                statuses.append(response[0])
                api.expect(response, (401, 429), "login abuse protection")
            api.check(429 in statuses, "repeated login failures are rate limited")
    finally:
        errors = []
        if admin:
            for resource in ("bookings", "schedules", "tournaments", "clubs"):
                for identifier in reversed(created[resource]):
                    try:
                        status, _, _ = api.call("DELETE", f"/{resource}/{identifier}", admin)
                        if status not in (200, 204, 404):
                            errors.append(f"{resource}/{identifier}: HTTP {status}")
                    except Exception as error:
                        errors.append(f"{resource}/{identifier}: {type(error).__name__}")
        if errors:
            raise AssertionError("Cleanup failed: " + "; ".join(errors))
    print(f"PASS: {api.checks} HTTP/API assertions against real PHP/MySQL.")
    print("Temporary API records cleaned up. Existing seed records were not modified.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", default="http://127.0.0.1:8000/api/v1")
    parser.add_argument("--origin", default="http://localhost:5500", help="One configured allowed CORS origin")
    parser.add_argument("--jwt-secret-file", help="Private test secret file, for extra signed JWT negative tests")
    parser.add_argument("--rate-limit", action="store_true", help="Dedicated test instance only: trigger login throttling at the end")
    run(parser.parse_args())
