from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
for file in ["functions_monitor.php", "monitor_checkin.php", "read_sensor_data.php", "custom_app_config.php_template"]:
    subprocess.run(["php", "-l", str(ROOT / "server_www" / file)], check=True)
subprocess.run(["php", "tests/monitor_test.php"], cwd=ROOT, check=True)
subprocess.run([sys.executable, "-m", "unittest", "discover", "-s", "tests", "-v"], cwd=ROOT, check=True)
