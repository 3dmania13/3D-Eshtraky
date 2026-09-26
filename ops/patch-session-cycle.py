"""Patch only getSessions in a compiled deployment, writing a new candidate."""
from pathlib import Path
import sys

live_path, built_path, output_path = map(Path, sys.argv[1:])
live = live_path.read_text()
built = built_path.read_text()
start = '    async getSessions(username, limit) {'
end = '    async getDevices('
assert live.count(start) == built.count(start) == 1
old_start, new_start = live.index(start), built.index(start)
old_end, new_end = live.index(end, old_start), built.index(end, new_start)
replacement = built[new_start:new_end]
assert "a.action_name IN ('user.package_settle','user.settle')" in replacement
output_path.write_text(live[:old_start] + replacement + live[old_end:])
