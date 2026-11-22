"""The line finder service (ml/line_markers_service.py): key, commands, one page at a time.

Kraken is not loaded here: keep_loaded and run_command are stood in for, so this
checks only how pages come in and results go back.

    python -m unittest discover -s tests/Python -p "test_line_markers_service.py"
"""

import json
import os
import sys
import threading
import time
import unittest
import urllib.error
import urllib.request
from http.server import ThreadingHTTPServer

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "..", "ml"))

import line_markers_service as service  # noqa: E402

KEY = "test-key"


class LineFinderServiceTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        os.environ["OCR_UPLOAD_SECRET"] = KEY
        cls.ran = []

        def run_command(argv):
            cls.ran.append(argv)
            time.sleep(0.05)
            return 0, {"ok": True, "lines": 3, "page": argv[2]}

        service.line_markers.keep_loaded = lambda: None
        service.line_markers.run_command = run_command
        threading.Thread(target=service._work, daemon=True).start()
        cls.server = ThreadingHTTPServer(("127.0.0.1", 0), service.Handler)
        cls.url = f"http://127.0.0.1:{cls.server.server_address[1]}"
        threading.Thread(target=cls.server.serve_forever, daemon=True).start()

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        os.environ.pop("OCR_UPLOAD_SECRET", None)

    def call(self, method, path, body=None, key=KEY):
        request = urllib.request.Request(self.url + path, method=method,
                                         data=json.dumps(body).encode() if body is not None else None,
                                         headers={"X-CRMS-Service-Key": key, "Content-Type": "application/json"})
        try:
            with urllib.request.urlopen(request) as response:
                return response.status, json.loads(response.read())
        except urllib.error.HTTPError as error:
            return error.code, json.loads(error.read())

    def wait_done(self, job):
        deadline = time.time() + 30  # the first page waits for the service to start (torch's import)
        while time.time() < deadline:
            status, body = self.call("GET", f"/jobs/{job}")
            if body.get("state") == "done":
                return body
            time.sleep(0.02)
        self.fail("the page never finished")

    def test_health_needs_no_key(self):
        status, body = self.call("GET", "/health", key="")
        self.assertEqual((200, True), (status, body["ok"]))

    def test_every_other_call_needs_the_key(self):
        self.assertEqual(401, self.call("POST", "/jobs", {"argv": ["process"]}, key="wrong")[0])
        self.assertEqual(401, self.call("GET", "/jobs/anything", key="")[0])

    def test_only_page_commands_are_taken(self):
        for argv in (["crop", "--page", "x"], ["snap"], [], "process", ["process", 3]):
            self.assertEqual(422, self.call("POST", "/jobs", {"argv": argv})[0], argv)

    def test_a_page_comes_back_with_the_scripts_summary(self):
        status, body = self.call("POST", "/jobs", {"argv": ["process", "--page", "a.png"]})
        self.assertEqual(202, status)
        done = self.wait_done(body["id"])
        self.assertEqual({"ok": True, "lines": 3, "page": "a.png"}, done["summary"])

    def test_pages_are_done_one_after_another_in_order(self):
        before = len(self.ran)
        jobs = [self.call("POST", "/jobs", {"argv": ["detect", "--page", f"p{i}.png"]})[1]["id"] for i in range(3)]
        for job in jobs:
            self.wait_done(job)
        self.assertEqual([f"p{i}.png" for i in range(3)], [argv[2] for argv in self.ran[before:]])

    def test_a_page_cancelled_while_waiting_is_skipped(self):
        before = len(self.ran)
        first = self.call("POST", "/jobs", {"argv": ["process", "--page", "first.png"]})[1]["id"]
        second = self.call("POST", "/jobs", {"argv": ["process", "--page", "second.png"]})[1]["id"]
        self.assertEqual((200, {"cancelled": True}), self.call("POST", f"/jobs/{second}/cancel"))
        self.wait_done(first)
        time.sleep(0.2)
        self.assertEqual(["first.png"], [argv[2] for argv in self.ran[before:]])

    def test_an_unknown_job_says_so(self):
        self.assertEqual(404, self.call("GET", "/jobs/nope")[0])


if __name__ == "__main__":
    unittest.main()
