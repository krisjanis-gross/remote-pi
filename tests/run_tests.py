from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
php_files = sorted(
    [*ROOT.glob("server_www/*.php"), *ROOT.glob("server_www/*.php_template"),
     *ROOT.glob("custom_configuration/**/*.php"), *ROOT.glob("sites/*/php/*.php"), *ROOT.glob("tests/*.php")]
)
for file in php_files:
    subprocess.run(["php", "-l", str(file)], check=True, stdout=subprocess.DEVNULL)
print(f"{len(php_files)} PHP files lint clean")

shell_files = [ROOT / "provision/install.sh", ROOT / "provision/doctor.sh", ROOT / "provision/lib.sh",
               ROOT / "provision/deploy.sh", ROOT / "provision/files/remote-pi-maintenance",
               ROOT / "provision/files/remote-pi-update", ROOT / "core/build/pi.sh",
               *sorted(ROOT.glob("provision/steps/*.sh"))]
subprocess.run(["shellcheck", "-x", "--source-path=SCRIPTDIR", "--source-path", str(ROOT), *map(str, shell_files)],
               cwd=ROOT, check=True)
print(f"{len(shell_files)} shell scripts pass shellcheck")

subprocess.run(["php", "tests/monitor_test.php"], cwd=ROOT, check=True)
subprocess.run(["php", "tests/app_test.php"], cwd=ROOT, check=True)
subprocess.run([sys.executable, "-m", "unittest", "discover", "-s", "tests", "-v"], cwd=ROOT, check=True)
